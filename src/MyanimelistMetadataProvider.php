<?php

/**
 * Myanimelistmetadataprovider.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Myanimelist;

use Phlix\Shared\Metadata\MetadataSourceInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Workerman\Http\Client;
use Workerman\Http\Response;
use Workerman\Timer;

/**
 * MyAnimeList (MAL) metadata provider plugin for Phlix.
 *
 * Fetches anime metadata (titles, descriptions, episodes, ratings, images) from
 * the official MyAnimeList API v2 over HTTPS/JSON.
 *
 * ## Features
 *
 * - MAL API v2 integration (search + details) over HTTP/JSON
 * - Client-ID authentication via the `X-MAL-CLIENT-ID` header
 * - Rate limiting — ~1 request/second to respect MAL's implicit limit
 * - In-memory response caching — identical URLs are fetched once per session
 * - Optional SSL verification toggle (`use_ssl_verification`) for self-hosted proxies
 * - Connectivity/credential check on enable (fails fast on a bad Client ID)
 * - Filename heuristics to extract a clean search query
 * - Poster (main_picture) + fanart (additional `pictures`) image extraction
 * - Maps MAL responses to Phlix MetadataManager's expected return shape
 *
 * ## Configuration (plugin.json settings)
 *
 * - client_id: MyAnimeList API client ID (required, secret)
 * - use_ssl_verification: verify TLS certificates (optional, default true)
 *
 * ## Protocol notes
 *
 * MAL uses a REST/JSON API at https://api.myanimelist.net/v2.
 * Every request carries the `X-MAL-CLIENT-ID: <client_id>` header.
 * Search: `GET /anime?q=<query>&limit=10`.
 * Details: `GET /anime/{id}?fields=...`.
 *
 * @see https://myanimelist.net/apiconfig/references/api/v2
 * @package Phlix\Myanimelist
 * @since 0.1.0
 */
class MyanimelistMetadataProvider implements LifecycleInterface, MetadataSourceInterface
{
    /**
     * MAL API v2 base URL.
     */
    private const API_BASE = 'https://api.myanimelist.net/v2';

    /**
     * Header name carrying the MAL client ID on every request.
     */
    private const CLIENT_ID_HEADER = 'X-MAL-CLIENT-ID';

    /**
     * Maximum number of search results to request per query.
     */
    private const SEARCH_LIMIT = 10;

    /**
     * HTTP request timeout in seconds.
     */
    private const HTTP_TIMEOUT_SEC = 10;

    /**
     * Minimum interval between API requests in seconds (rate protection).
     *
     * MAL suggests approximately one request per second.
     */
    private const RATE_LIMIT_INTERVAL_SEC = 1.0;

    /**
     * Fields requested from the MAL anime-details endpoint.
     *
     * `pictures` is requested so a backdrop/fanart image can be derived.
     */
    private const DETAIL_FIELDS = 'id,title,main_picture,alternative_titles,start_date,'
        . 'synopsis,mean,num_scoring_users,genres,num_episodes,media_type,status,'
        . 'studios,average_episode_duration,rating,pictures';

    /**
     * Number of ticks per second (host convention: 1s = 10,000,000 100ns ticks).
     */
    private const TICKS_PER_SECOND = 10_000_000;

    /**
     * Poll interval (seconds) of the cooperative-wait loop that awaits an
     * async HTTP response. 1ms matches the host convention in CLAUDE.md.
     */
    private const WAIT_INTERVAL_SEC = 0.001;

    /**
     * Plugin settings from plugin.json.
     *
     * @var array{client_id: string, use_ssl_verification?: bool}
     */
    private array $settings;

    /**
     * Unix timestamp (with microseconds) of the last API request, for rate limiting.
     */
    private float $lastRequestTimestamp = 0.0;

    /**
     * Unix timestamp (with microseconds) before which no further request may be
     * issued, set from a `Retry-After` header on a 429/503 response.
     *
     * 0.0 means no back-off is currently in effect.
     */
    private float $retryAfterUntil = 0.0;

    /**
     * In-memory response cache: md5(url) => decoded JSON object.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Clock used for time measurements in rate limiting.
     *
     * @var \Closure(): float
     */
    private \Closure $clock;

    /**
     * Sleep function for rate-limit delays. Allows injection of a no-op or
     * fake in tests when Workerman's Timer is not available.
     *
     * @var \Closure(float): void
     */
    private \Closure $timerSleep;

    /**
     * Single tick of the cooperative-wait loop used while awaiting an async
     * HTTP response. Defaults to a 1ms `usleep`, which under the Swoole
     * runtime hook yields to the event loop rather than blocking the worker
     * (see phlix-server/CLAUDE.md "Async Patterns"). Injectable so tests can
     * drive the loop deterministically with a no-op.
     *
     * @var \Closure(): void
     */
    private \Closure $waitTick;

    /**
     * Shared HTTP client instance for connection pooling.
     */
    private ?Client $httpClient = null;

    /**
     * Lazily-built host-contract adapter, reused by both the legacy
     * {@see registerWithMetadataManager()} path and the
     * {@see MetadataSourceInterface} triad below so a single object owns the
     * external-id ⇄ MAL-id translation.
     */
    private ?MyanimelistMetadataProviderAdapter $adapter = null;

    /**
     * Optional PSR-3 logger for debug output.
     */
    private ?LoggerInterface $logger = null;

    /**
     * @param array{client_id: string, use_ssl_verification?: bool} $settings
     *     Plugin settings from plugin.json. client_id is the MyAnimeList API
     *     client ID, sent as the X-MAL-CLIENT-ID header on every request.
     *     use_ssl_verification toggles TLS certificate verification (default true).
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->clock = static fn(): float => microtime(true);
        $this->timerSleep = static function (float $seconds): void {
            Timer::sleep($seconds);
        };
        $this->waitTick = static function (): void {
            // 1ms; hooked by the Swoole runtime to yield to the event loop.
            usleep((int) (self::WAIT_INTERVAL_SEC * 1_000_000));
        };
    }

    /**
     * Inject an optional PSR-3 logger.
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Called by the loader once when the plugin is enabled — and, once
     * boot-time activation (plan_plugins F1) lands, on every worker start
     * across all ~14 resident workers.
     *
     * This is the cheap **wire** step ONLY: it resolves the host
     * MetadataManager from the container and registers a lightweight adapter.
     * It performs NO network I/O, no DB migrations, no socket opens, no
     * credential probe and never waits on the rate limiter, so it can run at
     * boot in every worker without risking the item-5c3 boot-hang.
     *
     * The **connect** step — building the HTTP client and validating the MAL
     * Client ID — is deferred lazily to the first API call
     * ({@see getHttpClient()} / {@see httpGetJson()}), off the boot path.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     */
    public function onEnable(ContainerInterface $container): void
    {
        $this->registerWithMetadataManager($container);
    }

    /**
     * Resolve the host MetadataManager from the container and register an
     * adapter of this provider against it for the anime media type.
     *
     * The registration is best-effort: if the host does not expose a
     * MetadataManager (e.g. a stripped CLI bootstrap) we log nothing and move
     * on — onEnable() must not abort the whole plugin just because the registry
     * is unavailable.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     */
    private function registerWithMetadataManager(ContainerInterface $container): void
    {
        $managerClass = 'Phlix\\Media\\Metadata\\MetadataManager';

        if (!$container->has($managerClass)) {
            return;
        }

        $manager = $container->get($managerClass);
        if (!is_object($manager) || !method_exists($manager, 'registerProvider')) {
            return;
        }

        $manager->registerProvider(
            MyanimelistMetadataProviderAdapter::SOURCE_NAME,
            $this->adapter(),
            $this->supportedMediaTypes(),
        );
    }

    /**
     * Lazily build (and cache) the host-contract adapter that bridges this
     * provider's filename/MAL-id lookup to the external-id triad.
     *
     * @return MyanimelistMetadataProviderAdapter
     */
    private function adapter(): MyanimelistMetadataProviderAdapter
    {
        return $this->adapter ??= new MyanimelistMetadataProviderAdapter($this);
    }

    // -------------------------------------------------------------------------
    // MetadataSourceInterface (Phlix\Shared\Metadata) — the first-class typed
    // contract the host SourceRegistry registers on plugin-enable and
    // deregisters on plugin-disable (Step 3.5). The triad delegates to the
    // existing adapter so there is a single source of truth for the
    // external-id ⇄ MAL-id lookup.
    // -------------------------------------------------------------------------

    /**
     * Canonical source name — matches the host anime priority-map entry
     * `['anidb', 'myanimelist', 'tvdb', 'fanart', 'local']`.
     *
     * @return non-empty-string Always `myanimelist`.
     */
    public function sourceName(): string
    {
        return MyanimelistMetadataProviderAdapter::SOURCE_NAME;
    }

    /**
     * Media types MAL answers for.
     *
     * Anime is deliberately NOT its own media type: `anime` is not a member of
     * the `media_items.type` ENUM (it is only a scanner/library label), so a
     * source indexed under `anime` is never consulted by the host resolver.
     * MAL titles are stored as ordinary `series` (TV/OVA/ONA/special) or
     * `movie` items; the provider answers for both and matches by title.
     *
     * @return list<non-empty-string> Always `['series', 'movie']`.
     */
    public function supportedMediaTypes(): array
    {
        return ['series', 'movie'];
    }

    /**
     * @param string               $query   Free-text anime title.
     * @param array<string, mixed> $options Optional hints (ignored by MAL).
     * @return list<array{id: non-empty-string, title: string, overview?: string, poster_path?: string}>
     */
    public function search(string $query, array $options = []): array
    {
        $results = [];
        foreach ($this->adapter()->search($query, $options) as $row) {
            $id = $row['id'] ?? '';
            if (!is_string($id) || $id === '') {
                continue; // a usable external id is mandatory for the host triad
            }
            /** @var array{id: non-empty-string, title: string, overview?: string, poster_path?: string} $entry */
            $entry = ['id' => $id, 'title' => (string) ($row['title'] ?? '')];
            if (isset($row['overview']) && is_string($row['overview'])) {
                $entry['overview'] = $row['overview'];
            }
            if (isset($row['poster_path']) && is_string($row['poster_path'])) {
                $entry['poster_path'] = $row['poster_path'];
            }
            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @param string               $externalId MAL anime ID as a decimal string.
     * @param array<string, mixed> $options    Optional hints (ignored).
     * @return array<string, mixed>
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        return $this->adapter()->getDetails($externalId, $options);
    }

    /**
     * @param string $externalId MAL anime ID as a decimal string.
     * @return array<string, list<array{url: non-empty-string, width?: int, height?: int}>>
     */
    public function getImages(string $externalId): array
    {
        $images = [];
        foreach ($this->adapter()->getImages($externalId) as $group => $entries) {
            $list = [];
            foreach ($entries as $entry) {
                $url = $entry['url'] ?? '';
                if (!is_string($url) || $url === '') {
                    continue;
                }
                /** @var array{url: non-empty-string, width?: int, height?: int} $image */
                $image = ['url' => $url];
                if (isset($entry['width']) && is_int($entry['width'])) {
                    $image['width'] = $entry['width'];
                }
                if (isset($entry['height']) && is_int($entry['height'])) {
                    $image['height'] = $entry['height'];
                }
                $list[] = $image;
            }
            if ($list !== []) {
                $images[(string) $group] = $list;
            }
        }

        return $images;
    }

    /**
     * Public bridge: find a MAL anime ID by title via the search endpoint.
     *
     * Thin, host-facing wrapper over the internal search path. Consumed by
     * {@see MyanimelistMetadataProviderAdapter::search()}.
     *
     * @param string $title Anime title to search for.
     *
     * @return int|null MAL anime ID of the first result, or null if none.
     */
    public function findIdByTitle(string $title): ?int
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            return null;
        }

        // Use the existing private implementation
        return $this->findIdByTitleInternal($trimmed);
    }

    /**
     * Public bridge: fetch full, host-shaped metadata for a MAL anime ID.
     *
     * Thin, host-facing wrapper over {@see fetchAnimeDetails()} +
     * {@see mapToMetadataReturn()}. Consumed by
     * {@see MyanimelistMetadataProviderAdapter::getDetails()} /
     * {@see MyanimelistMetadataProviderAdapter::getImages()}.
     *
     * @param int $malId MAL anime ID.
     *
     * @return array<string, mixed> Mapped metadata array, or `[]` when not found.
     */
    public function fetchAnimeMetadata(int $malId): array
    {
        if ($malId <= 0) {
            return [];
        }

        $anime = $this->fetchAnimeDetails($malId);
        if ($anime === null) {
            return [];
        }

        return $this->mapToMetadataReturn($anime);
    }

    /**
     * Called by the loader once when the plugin is disabled.
     *
     * Drops the response cache. MAL holds no open sockets, so there is
     * nothing else to tear down.
     *
     * @return void
     */
    public function onDisable(): void
    {
        $this->cache = [];
    }

    /**
     * Return the PSR-14 listener subscriptions this plugin wants.
     *
     * This plugin is invoked directly by MetadataManager via lookup()
     * rather than through the PSR-14 event dispatcher, so no subscriptions.
     *
     * @return array<class-string, string|callable> Empty for this plugin.
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Look up anime metadata by file path.
     *
     * Parses the filename to extract a clean anime title, searches MAL for a
     * matching anime ID, fetches full details, and returns structured metadata.
     *
     * @param string $filePath Absolute filesystem path of the media item.
     *
     * @return array{
     *     title: string,
     *     original_name: string|null,
     *     overview: string|null,
     *     year: int|null,
     *     genres: array<int, string>,
     *     rating: float|null,
     *     vote_count: int|null,
     *     poster_url: string|null,
     *     fanart_url: string|null,
     *     episodes: int|null,
     *     type: string|null,
     *     mal_id: int,
     *     titles: array<int, string>,
     *     status: string|null,
     *     runtime_ticks: int|null,
     *     studio: string|null
     * }|array{} Matched anime metadata or empty array when not found.
     */
    public function lookup(string $filePath): array
    {
        // Step 1: Extract anime name from filename
        $animeName = $this->extractAnimeName($filePath);
        if ($animeName === null) {
            return [];
        }

        // Step 2: Find MAL ID via the search endpoint
        $malId = $this->findIdByTitleInternal($animeName);
        if ($malId === null) {
            return [];
        }

        // Step 3: Fetch full anime details from MAL
        $anime = $this->fetchAnimeDetails($malId);
        if ($anime === null) {
            return [];
        }

        // Step 4: Map to return shape expected by MetadataManager
        return $this->mapToMetadataReturn($anime);
    }

    // -------------------------------------------------------------------------
    // Private: HTTP
    // -------------------------------------------------------------------------

    /**
     * Perform a GET request against the MAL API and decode the JSON body.
     *
     * Applies rate limiting (≈1 req/s), an in-memory cache keyed by URL, and an
     * optional SSL-verification bypass.
     *
     * The HTTP status line is inspected before the body is trusted: only a
     * 2xx response carrying a well-formed JSON object is decoded and cached.
     * A 4xx/5xx error body (e.g. the JSON error MAL returns on a rejected
     * Client ID or unknown anime) is never decoded-and-cached as if it were
     * real data — it returns null. A 429/503 additionally records a back-off
     * from the `Retry-After` header so the next call waits before retrying.
     *
     * Uses the canonical non-blocking `workerman/http-client` cooperative-wait
     * pattern (a `$state` array + a `while (!$state['done'] ...)` loop; see
     * phlix-server/CLAUDE.md). The request is issued with `success`/`error`
     * callbacks and the loop yields to the event loop until one of them fires,
     * so the decoded body is returned to THIS caller on THIS call — no Fibers,
     * no reliance on a suspend return value, no "populate on the next call"
     * cache side-effect.
     *
     * @param string $url Absolute MAL API URL (already query-encoded).
     *
     * @return array<string, mixed>|null Decoded JSON object or null on
     *     transport/HTTP/JSON failure.
     */
    protected function httpGetJson(string $url): ?array
    {
        $cacheKey = md5($url);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->enforceRateLimit();

        $response = $this->requestAndWait($url);
        if ($response === null) {
            $this->logger?->debug('MAL API produced no response; will retry on next request');

            return null;
        }

        return $this->handleResponseObject($cacheKey, $response);
    }

    /**
     * Issue an async HTTP GET and cooperatively wait for the response.
     *
     * This is the canonical CLAUDE.md pattern: the async client is handed
     * `success`/`error` callbacks that flip a shared `$state` flag, and the
     * calling context spins a short poll loop that yields to the event loop
     * (never a blocking `sleep()`) until the flag is set or the timeout
     * budget is exhausted. The resolved {@see Response} (or null on
     * transport error / timeout) is returned directly to the caller.
     *
     * @param string $url Full URL to request.
     *
     * @return Response|null The HTTP response, or null on transport error or
     *     timeout.
     */
    private function requestAndWait(string $url): ?Response
    {
        $headers = [
            self::CLIENT_ID_HEADER . ': ' . ($this->settings['client_id'] ?? ''),
            'Accept: application/json',
        ];

        /** @var array<string, mixed> $options */
        $options = [
            'method'  => 'GET',
            'headers' => $headers,
            'timeout' => self::HTTP_TIMEOUT_SEC,
        ];

        if (($this->settings['use_ssl_verification'] ?? true) === false) {
            $options['verify_ssl'] = false;
        }

        /** @var array{done: bool, response: Response|null, error: \Throwable|null} $state */
        $state = ['done' => false, 'response' => null, 'error' => null];

        $options['success'] = static function (mixed $response) use (&$state): void {
            $state['response'] = $response instanceof Response ? $response : null;
            $state['done'] = true;
        };
        $options['error'] = static function (mixed $error) use (&$state): void {
            $state['error'] = $error instanceof \Throwable
                ? $error
                : new \RuntimeException('MAL HTTP transport error');
            $state['done'] = true;
        };

        try {
            $this->getHttpClient()->request($url, $options);
        } catch (\Throwable $e) {
            $this->logger?->debug('MAL API request threw: ' . $e->getMessage());

            return null;
        }

        // Cooperative wait — yields to the event loop so other tasks proceed
        // while the response is in flight. Bounded by the request timeout plus
        // a small grace so a lost callback can never wedge the worker.
        $waited = 0.0;
        $maxWait = (float) self::HTTP_TIMEOUT_SEC + 1.0;
        while (!$state['done'] && $waited < $maxWait) {
            ($this->waitTick)();
            $waited += self::WAIT_INTERVAL_SEC;
        }

        if ($state['error'] !== null) {
            $this->logger?->debug('MAL API transport error: ' . $state['error']->getMessage());

            return null;
        }

        return $state['response'];
    }

    /**
     * Apply the status-inspection, back-off and cache-only-on-2xx policy to a
     * Workerman HTTP Response object.
     *
     * Called by {@see httpGetJson()} once {@see requestAndWait()} resolves a
     * response. It reads the status code directly from the Response object and
     * extracts the body string for JSON decoding. Retains all B5 semantics
     * (429/503 back-off, non-2xx → null, JSON object cache validation).
     *
     * @param string    $cacheKey  In-memory cache key (md5 of URL).
     * @param Response  $response  Workerman HTTP Response object.
     *
     * @return array<string, mixed>|null Decoded JSON object on a cacheable 2xx,
     *     null otherwise.
     */
    private function handleResponseObject(string $cacheKey, Response $response): ?array
    {
        $status = $response->getStatusCode();

        // Treat any non-2xx status as failure: never decode or cache the error
        // body. Record a back-off on 429/503 so the next request honours MAL's
        // Retry-After before trying again.
        if ($status < 200 || $status >= 300) {
            if ($status === 429 || $status === 503) {
                $this->recordRetryAfterFromResponse($response);
            }

            return null;
        }

        $body = $response->getBody()->getContents();

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $this->cache[$cacheKey] = $decoded;

        return $decoded;
    }

    /**
     * Get or create the shared HTTP client instance.
     *
     * This is the lazy "connect" step: the client is built on first use (the
     * first real API call), never at plugin-enable / worker-boot, keeping
     * {@see onEnable()} free of any socket setup.
     */
    private function getHttpClient(): Client
    {
        return $this->httpClient ??= new Client(['timeout' => self::HTTP_TIMEOUT_SEC]);
    }

    /**
     * Record a back-off window from a 429/503 response's Retry-After header
     * (extracted from a Workerman Response object).
     *
     * @param Response $response Workerman HTTP Response object.
     *
     * @return void
     */
    private function recordRetryAfterFromResponse(Response $response): void
    {
        $delaySeconds = self::RATE_LIMIT_INTERVAL_SEC;

        $retryAfter = $response->getHeaderLine('Retry-After');
        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            $delaySeconds = (float) $retryAfter;
        }

        $until = ($this->clock)() + $delaySeconds;
        if ($until > $this->retryAfterUntil) {
            $this->retryAfterUntil = $until;
        }
    }

    /**
     * Sleep, if necessary, so consecutive API requests stay below the rate
     * limit and honour any recorded `Retry-After` back-off.
     *
     * Uses the injected sleep (Workerman's Timer::sleep() by default) so the
     * pause yields to the event loop rather than blocking the worker under the
     * Swoole runtime.
     *
     * @return void
     */
    private function enforceRateLimit(): void
    {
        $now = ($this->clock)();

        // Honour a 429/503 Retry-After back-off first: do not issue the next
        // request until the recorded deadline has passed.
        if ($this->retryAfterUntil > $now) {
            ($this->timerSleep)($this->retryAfterUntil - $now);
            $this->retryAfterUntil = 0.0;
            $now = ($this->clock)();
        }

        $elapsed = $now - $this->lastRequestTimestamp;
        if ($elapsed < self::RATE_LIMIT_INTERVAL_SEC) {
            ($this->timerSleep)(self::RATE_LIMIT_INTERVAL_SEC - $elapsed);
        }

        $this->lastRequestTimestamp = ($this->clock)();
    }

    // -------------------------------------------------------------------------
    // Private: Title Lookup
    // -------------------------------------------------------------------------

    /**
     * Internal: find a MAL anime ID by title via the search endpoint.
     *
     * Uses scored matching against all available titles (main + English +
     * Japanese + synonyms) to select the best candidate, rather than blindly
     * returning the first MAL search result.
     *
     * Scoring algorithm (per anidb B4):
     * - Exact match (case-insensitive): return immediately (highest confidence)
     * - Prefix match (query starts title): score = 800 - |query_len - title_len|
     * - Contains match (query inside title): score = 600 - |query_len - title_len|
     *
     * @param string $title Anime title to search for.
     *
     * @return int|null Best-scoring MAL anime ID, or null if no match found.
     */
    private function findIdByTitleInternal(string $title): ?int
    {
        $url = self::API_BASE . '/anime?q=' . rawurlencode($title)
            . '&limit=' . self::SEARCH_LIMIT . '&fields=id,title,alternative_titles';

        $response = $this->httpGetJson($url);
        if ($response === null) {
            return null;
        }

        return $this->scoreSearchResults($response, $title);
    }

    /**
     * Extract the first anime ID from a MAL search response.
     *
     * Response shape: `{ "data": [ { "node": { "id": 1, ... } }, ... ] }`.
     *
     * @param array<string, mixed> $response Decoded search JSON.
     *
     * @return int|null First node ID or null when the result set is empty.
     *
     * @internal Kept for test compatibility via reflection.
     */
    private function parseSearchResponse(array $response): ?int
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return null;
        }

        $first = $data[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        $node = $first['node'] ?? null;
        if (!is_array($node) || !isset($node['id'])) {
            return null;
        }

        if (!is_numeric($node['id'])) {
            return null;
        }

        return (int) $node['id'];
    }

    /**
     * Score each search result against all its titles and return the best match.
     *
     * MAL search returns results ranked by popularity, which may not correspond
     * to the user's intent when querying by a subtitle, short name, or
     * alternative title. This method evaluates every candidate across all of its
     * available titles (main, English, Japanese, synonyms) using the anidb B4
     * scoring algorithm:
     *
     * - Exact match (case-insensitive): return immediately (highest confidence)
     * - Prefix match (query starts title): score = 800 - |query_len - title_len|
     * - Contains match (query inside title): score = 600 - |query_len - title_len|
     *
     * Response shape: `{ "data": [ { "node": { "id", "title", "alternative_titles": {en, ja, synonyms} } }, ... ] }`.
     *
     * @param array<string, mixed> $response Decoded search JSON from MAL.
     * @param string              $query    Original search query (user intent).
     *
     * @return int|null Highest-scoring MAL ID, or null when no candidate matches.
     */
    private function scoreSearchResults(array $response, string $query): ?int
    {
        $data = $response['data'] ?? null;
        if (!is_array($data) || $data === []) {
            return null;
        }

        $queryLower = mb_strtolower($query, 'UTF-8');
        $queryLen = mb_strlen($queryLower, 'UTF-8');

        $bestId = null;
        $bestScore = -1;

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $node = $entry['node'] ?? null;
            if (!is_array($node) || !isset($node['id'])) {
                continue;
            }

            $id = is_numeric($node['id']) ? (int) $node['id'] : 0;

            // Collect all titles for this candidate
            $titles = [];

            // Main title
            if (isset($node['title']) && is_string($node['title']) && $node['title'] !== '') {
                $titles[] = $node['title'];
            }

            // Alternative titles
            $altTitles = is_array($node['alternative_titles'] ?? null) ? $node['alternative_titles'] : [];
            if (isset($altTitles['en']) && is_string($altTitles['en']) && $altTitles['en'] !== '') {
                $titles[] = $altTitles['en'];
            }
            if (isset($altTitles['ja']) && is_string($altTitles['ja']) && $altTitles['ja'] !== '') {
                $titles[] = $altTitles['ja'];
            }
            if (isset($altTitles['synonyms']) && is_array($altTitles['synonyms'])) {
                foreach ($altTitles['synonyms'] as $synonym) {
                    if (is_string($synonym) && $synonym !== '') {
                        $titles[] = $synonym;
                    }
                }
            }

            // Score this candidate's titles
            $candidateScore = $this->scoreCandidateTitles($titles, $queryLower, $queryLen);

            if ($candidateScore === -1) {
                // Exact match found — return immediately
                return $id;
            }

            if ($candidateScore > $bestScore) {
                $bestScore = $candidateScore;
                $bestId = $id;
            }
        }

        return $bestScore > 0 ? $bestId : null;
    }

    /**
     * Score a single candidate's titles against the query.
     *
     * @param array<int, string> $titles      All titles for this candidate.
     * @param string             $queryLower  Lowercased query string.
     * @param int                $queryLen    Length of the lowercased query.
     *
     * @return int -1 on exact match (signal to stop searching), >0 score
     *     for prefix/contains matches, 0 when no match.
     */
    private function scoreCandidateTitles(array $titles, string $queryLower, int $queryLen): int
    {
        $bestScore = 0;

        foreach ($titles as $title) {
            $titleLower = mb_strtolower($title, 'UTF-8');
            $titleLen = mb_strlen($titleLower, 'UTF-8');

            // Exact match (case-insensitive) — checked FIRST, before prefix
            if ($titleLower === $queryLower) {
                return -1;
            }

            // Prefix match: query starts the title
            if (str_starts_with($titleLower, $queryLower)) {
                $score = 800 - abs($queryLen - $titleLen);
                if ($score > $bestScore) {
                    $bestScore = $score;
                }
            }

            // Contains match: query inside title
            if (str_contains($titleLower, $queryLower)) {
                $score = 600 - abs($queryLen - $titleLen);
                if ($score > $bestScore) {
                    $bestScore = $score;
                }
            }
        }

        return $bestScore;
    }

    // -------------------------------------------------------------------------
    // Private: Anime Details Fetch & Parse
    // -------------------------------------------------------------------------

    /**
     * Fetch full anime details from MAL by ID.
     *
     * @param int $malId MAL anime ID.
     *
     * @return array<string, mixed>|null Parsed anime data or null on failure.
     */
    private function fetchAnimeDetails(int $malId): ?array
    {
        $url = self::API_BASE . '/anime/' . $malId . '?fields=' . self::DETAIL_FIELDS;

        $response = $this->httpGetJson($url);
        if ($response === null) {
            return null;
        }

        return $this->parseAnimeResponse($response);
    }

    /**
     * Parse a MAL anime-details response into a flat structured array.
     *
     * Pulls each field out of the raw JSON with null-safe fallbacks so
     * mapToMetadataReturn() can rely on a stable internal key set.
     *
     * @param array<string, mixed> $raw Decoded anime-details JSON.
     *
     * @return array<string, mixed>|null Parsed fields or null when there is
     *     no usable ID.
     */
    private function parseAnimeResponse(array $raw): ?array
    {
        if (!isset($raw['id'])) {
            return null;
        }

        $altTitles = is_array($raw['alternative_titles'] ?? null) ? $raw['alternative_titles'] : [];

        $synonyms = [];
        if (isset($altTitles['synonyms']) && is_array($altTitles['synonyms'])) {
            foreach ($altTitles['synonyms'] as $synonym) {
                if (is_string($synonym) && $synonym !== '') {
                    $synonyms[] = $synonym;
                }
            }
        }

        $enTitle = isset($altTitles['en']) && is_string($altTitles['en']) && $altTitles['en'] !== ''
            ? $altTitles['en']
            : null;
        $jaTitle = isset($altTitles['ja']) && is_string($altTitles['ja']) && $altTitles['ja'] !== ''
            ? $altTitles['ja']
            : null;

        $mainPicture = is_array($raw['main_picture'] ?? null) ? $raw['main_picture'] : [];
        $pictureLarge = isset($mainPicture['large']) && is_string($mainPicture['large'])
            ? $mainPicture['large']
            : null;
        $pictureMedium = isset($mainPicture['medium']) && is_string($mainPicture['medium'])
            ? $mainPicture['medium']
            : null;

        $genres = [];
        if (isset($raw['genres']) && is_array($raw['genres'])) {
            foreach ($raw['genres'] as $genre) {
                if (is_array($genre) && isset($genre['name']) && is_string($genre['name'])) {
                    $genres[] = $genre['name'];
                }
            }
        }

        $studio = null;
        if (isset($raw['studios']) && is_array($raw['studios']) && $raw['studios'] !== []) {
            $firstStudio = $raw['studios'][0] ?? null;
            if (is_array($firstStudio) && isset($firstStudio['name']) && is_string($firstStudio['name'])) {
                $studio = $firstStudio['name'];
            }
        }

        // Parse year from start_date "YYYY-MM-DD" or "YYYY"
        $year = null;
        if (isset($raw['start_date']) && is_string($raw['start_date']) && strlen($raw['start_date']) >= 4) {
            $yearStr = substr($raw['start_date'], 0, 4);
            if (ctype_digit($yearStr)) {
                $year = (int) $yearStr;
            }
        }

        $title = isset($raw['title']) && is_string($raw['title']) ? $raw['title'] : '';

        return [
            'id'             => is_numeric($raw['id']) ? (int) $raw['id'] : 0,
            'title'          => $title,
            'en_title'       => $enTitle,
            'ja_title'       => $jaTitle,
            'synonyms'       => $synonyms,
            'synopsis'       => isset($raw['synopsis']) && is_string($raw['synopsis']) && $raw['synopsis'] !== ''
                ? $raw['synopsis']
                : null,
            'year'           => $year,
            'genres'         => $genres,
            'rating'         => isset($raw['mean']) && is_numeric($raw['mean']) ? (float) $raw['mean'] : null,
            'vote_count'     => isset($raw['num_scoring_users']) && is_numeric($raw['num_scoring_users'])
                ? (int) $raw['num_scoring_users']
                : null,
            'poster_url'     => $pictureLarge ?? $pictureMedium,
            'fanart_url'     => $this->extractFanartUrl($raw),
            'episodes'       => isset($raw['num_episodes']) && is_numeric($raw['num_episodes'])
                ? (int) $raw['num_episodes']
                : null,
            'media_type'     => isset($raw['media_type']) && is_string($raw['media_type'])
                ? $raw['media_type']
                : null,
            'status'         => isset($raw['status']) && is_string($raw['status']) ? $raw['status'] : null,
            'studio'         => $studio,
            'episode_length' => isset($raw['average_episode_duration']) && is_numeric($raw['average_episode_duration'])
                ? (int) $raw['average_episode_duration']
                : null,
        ];
    }

    /**
     * Derive a fanart/backdrop URL from a MAL anime's additional `pictures`.
     *
     * MAL's `main_picture` is the poster; the `pictures` array carries extra
     * artwork. We take the first entry (preferring its large size) as fanart.
     *
     * @param array<string, mixed> $raw Decoded anime-details JSON.
     *
     * @return string|null First additional picture URL, or null when absent.
     */
    private function extractFanartUrl(array $raw): ?string
    {
        if (!isset($raw['pictures']) || !is_array($raw['pictures']) || $raw['pictures'] === []) {
            return null;
        }

        $first = $raw['pictures'][0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        if (isset($first['large']) && is_string($first['large']) && $first['large'] !== '') {
            return $first['large'];
        }
        if (isset($first['medium']) && is_string($first['medium']) && $first['medium'] !== '') {
            return $first['medium'];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private: Filename Parsing
    // -------------------------------------------------------------------------

    /**
     * Extract a likely anime title from a file path.
     *
     * Uses heuristics: strips S##E## patterns, group tags, file extensions,
     * resolution suffixes, etc.
     *
     * @param string $filePath Absolute path to media file.
     *
     * @return string|null Extracted title or null if no clear match.
     */
    private function extractAnimeName(string $filePath): ?string
    {
        /** @var string $filename pathinfo() returns string|false, never null for PATHINFO_FILENAME */
        $filename = pathinfo($filePath, PATHINFO_FILENAME) ?: '';

        // Strip common release group patterns: [GroupName], (TX), (...)
        $clean = preg_replace('/\[[^\]]+\]/', '', $filename ?? '');
        $clean = preg_replace('/\(TX\)/', '', $clean ?? '');
        $clean = preg_replace('/\([^\)]+\)/', '', $clean ?? '');

        // Strip episode patterns: S01E02, 01x02, Episode 01, Episode.01
        // (standalone episode strip happens AFTER resolution/codec/source strip below)
        $clean = preg_replace('/[Ss]\d{1,2}[Ee]\d{1,4}/', '', $clean ?? '');
        $clean = preg_replace('/\d{1,2}[Xx]\d{1,4}/', '', $clean ?? '');
        $clean = preg_replace('/(?:^|[.\-_ ])[Ee]p?[i]?[t]?[.]?\d{1,4}\b/i', '', $clean ?? '');

        // Strip common suffixes: 720p, 1080p, BluRay, HDTV, etc.
        // MUST run before standalone-episode strip so episode numbers become genuinely trailing
        $clean = preg_replace('/(720p|1080p|2160p|480p|BluRay|BRRip|HDRip|HDTV|DVDRip|x264|x265|HEVC|AAC|AC3)/i', '', $clean ?? '');

        // Collapse any consecutive dots left behind by resolution/codec stripping
        // (e.g. ".720p.BluRay.x264" → "..." then → ".")
        $clean = preg_replace('/\.{2,}/', '.', $clean ?? '');

        // Strip year patterns: (2016), trailing 2001/2023
        $clean = preg_replace('/\(\d{4}\)/', '', $clean ?? '');
        $clean = preg_replace('/\s+\d{4}$/', '', $clean ?? '');

        // Strip resolution and codec patterns (e.g. 1920x1080)
        $clean = preg_replace('/\d{3,4}[xX]\d{3,4}/', '', $clean ?? '');

        // Strip leading/trailing dashes, dots, underscores, spaces
        // Must happen BEFORE standalone-episode strip so trailing separators don't block episode detection
        $clean = trim((string) $clean, '.-_ ');

        // Strip standalone episode numbers: leading separator before 1-4 digits at end
        // Now genuinely trailing after resolution/codec/source has been stripped
        $clean = preg_replace('/[.\- ][0-9]{1,4}$/', '', $clean);

        // Replace remaining dots with spaces (common in anime filenames)
        $clean = str_replace('.', ' ', $clean ?? '');

        // If result is too short or looks like garbage, skip
        if (strlen($clean) < 2) {
            return null;
        }

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Private: Response Mapping
    // -------------------------------------------------------------------------

    /**
     * Map a parsed MAL anime array to the return shape expected by MetadataManager.
     *
     * @param array<string, mixed> $anime Parsed from parseAnimeResponse().
     *
     * @return array<string, mixed> Mapped metadata array (16 fixed keys).
     */
    private function mapToMetadataReturn(array $anime): array
    {
        /** @var string $title */
        $title = $anime['title'];
        /** @var string|null $enTitle */
        $enTitle = $anime['en_title'];
        /** @var string|null $jaTitle */
        $jaTitle = $anime['ja_title'];
        /** @var array<int, string> $synonyms */
        $synonyms = $anime['synonyms'];

        // Build the title list: primary, English, Japanese, synonyms (de-duplicated)
        $allTitles = array_filter(array_merge(
            [$title],
            $enTitle !== null ? [$enTitle] : [],
            $jaTitle !== null ? [$jaTitle] : [],
            $synonyms
        ));

        return [
            'title'         => $title,
            'original_name' => $jaTitle ?? ($title !== '' ? $title : null),
            'overview'      => $anime['synopsis'],
            'year'          => $anime['year'],
            'genres'        => $anime['genres'],
            'rating'        => $anime['rating'],
            'vote_count'    => $anime['vote_count'],
            'poster_url'    => $anime['poster_url'],
            'fanart_url'    => $anime['fanart_url'] ?? null,
            'episodes'      => ($anime['episodes'] ?? 0) > 0 ? $anime['episodes'] : null,
            'type'          => $this->mapType($anime['media_type'] ?? null),
            'mal_id'        => $anime['id'],
            'titles'        => array_values(array_unique($allTitles)),
            'status'        => $this->mapStatus($anime['status'] ?? null),
            'runtime_ticks' => $this->mapRuntimeTicks($anime['episode_length'] ?? null),
            'studio'        => $anime['studio'],
        ];
    }

    /**
     * Map a MAL `media_type` value to a normalized type string.
     *
     * @param mixed $mediaType Raw MAL media_type (e.g. "tv", "movie").
     *
     * @return string|null Normalized type or null when unknown/absent.
     */
    private function mapType(mixed $mediaType): ?string
    {
        if (!is_string($mediaType)) {
            return null;
        }

        if ($mediaType === '') {
            return null;
        }

        return match (strtolower($mediaType)) {
            'tv'                 => 'tv',
            'movie'              => 'movie',
            'ova'                => 'ova',
            'special'            => 'special',
            'ona'                => 'ona',
            'music'              => 'music',
            default              => strtolower($mediaType),
        };
    }

    /**
     * Map a MAL `status` value to Phlix's status vocabulary.
     *
     * Mirrors AnidbMetadataProvider's status strings:
     * finished_airing → 'Finished', currently_airing → 'Currently Airing',
     * not_yet_aired → 'Upcoming'.
     *
     * @param mixed $status Raw MAL status string.
     *
     * @return string|null Mapped status or null when unknown/absent.
     */
    private function mapStatus(mixed $status): ?string
    {
        if (!is_string($status)) {
            return null;
        }

        if ($status === '') {
            return null;
        }

        return match (strtolower($status)) {
            'finished_airing'  => 'Finished',
            'currently_airing' => 'Currently Airing',
            'not_yet_aired'    => 'Upcoming',
            default            => null,
        };
    }

    /**
     * Convert a MAL average episode duration (seconds) to runtime ticks.
     *
     * Uses the host tick convention (1 second = 10,000,000 100ns ticks).
     *
     * @param mixed $seconds Average episode duration in seconds.
     *
     * @return int|null Runtime in ticks, or null when duration is unknown/zero.
     */
    private function mapRuntimeTicks(mixed $seconds): ?int
    {
        if (!is_int($seconds)) {
            return null;
        }

        if ($seconds <= 0) {
            return null;
        }

        return $seconds * self::TICKS_PER_SECOND;
    }
}
