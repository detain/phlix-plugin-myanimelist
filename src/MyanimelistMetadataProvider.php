<?php

declare(strict_types=1);

namespace Phlix\Myanimelist;

use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Workerman\Coroutine;
use Workerman\Coroutine\Coroutine\Fiber as CoroutineFiber;
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
class MyanimelistMetadataProvider implements LifecycleInterface
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
     * Shared HTTP client instance for connection pooling.
     */
    private ?Client $httpClient = null;

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
    }

    /**
     * Called by the loader once when the plugin is enabled.
     *
     * Performs a lightweight connectivity + credential check (a one-result
     * search) so a bad Client ID or unreachable MAL surfaces immediately
     * rather than on the first library scan, and registers an adapter with
     * the host MetadataManager so the server's metadata pipeline can actually
     * consume MAL results.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     *
     * @throws \RuntimeException If MAL is unreachable or the Client ID is rejected.
     */
    public function onEnable(ContainerInterface $container): void
    {
        $check = $this->httpGetJson(
            self::API_BASE . '/anime?q=test&limit=1&fields=id'
        );
        if ($check === null) {
            throw new \RuntimeException(
                'MyAnimeList API unreachable or Client ID rejected (401 Unauthorized).'
                . ' Check your Client ID and network connectivity.'
            );
        }

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

        $adapter = new MyanimelistMetadataProviderAdapter($this);
        $manager->registerProvider(
            MyanimelistMetadataProviderAdapter::SOURCE_NAME,
            $adapter,
            ['anime'],
        );
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
     * Uses Workerman's Fiber-based async HTTP via workerman/http-client. The
     * HTTP request is issued non-blockingly and the Fiber suspends until the
     * response arrives, allowing the Workerman event loop to handle other
     * requests during the wait.
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

        $result = $this->requestAsync($url, $cacheKey);
        if (!is_array($result)) {
            return null;
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Issue an async HTTP GET and return the decoded JSON response.
     *
     * Runs inside a Fiber so that the HTTP request suspends the current
     * context (not the worker) while waiting for the response, allowing the
     * Workerman event loop to service other tasks.
     *
     * @param string $url        Full URL to request.
     * @param string $cacheKey   Pre-computed cache key (md5 of URL).
     *
     * @return mixed Decoded JSON or null on failure.
     */
    private function requestAsync(string $url, string $cacheKey): mixed
    {
        $fiber = new CoroutineFiber(function () use ($url, $cacheKey): ?array {
            $client = $this->getHttpClient();

            $headers = [
                self::CLIENT_ID_HEADER . ': ' . $this->settings['client_id'],
                'Accept: application/json',
            ];

            $options = [
                'headers' => $headers,
                'timeout' => self::HTTP_TIMEOUT_SEC,
            ];

            if (($this->settings['use_ssl_verification'] ?? true) === false) {
                $options['verify_ssl'] = false;
            }

            try {
                /** @var mixed $rawResponse */
                $rawResponse = $client->request($url, $options);
                if (!$rawResponse instanceof Response) {
                    return null;
                }

                return $this->handleResponseObject($cacheKey, $rawResponse);
            } catch (\Throwable) {
                return null;
            }
        });

        return $fiber->start();
    }

    /**
     * Apply the status-inspection, back-off and cache-only-on-2xx policy to a
     * Workerman HTTP Response object.
     *
     * This is the async-HTTP counterpart to {@see self::handleResponse()}. It
     * reads the status code directly from the Response object and extracts the
     * body string for JSON decoding. Retains all B5 semantics (429/503 back-off,
     * non-2xx → null, JSON object cache validation).
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
     */
    private function getHttpClient(): Client
    {
        return $this->httpClient ??= new Client();
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
     * Uses Workerman's Timer::sleep() which yields to the event loop rather
     * than blocking the worker (when a Fiber-based loop is active).
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
