<?php

/**
 * MyAnimeList metadata provider: resolves anime by title via the MAL API v2
 * search endpoint and maps detail responses onto the host metadata contract.
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
 * - Rate limiting — ~1 request/second PER RESIDENT WORKER, honoring MAL's
 *   implicit limit. The limiter state (`$lastRequestTimestamp`) and any
 *   recorded back-off deadline are process-local, so a fleet of ~14 resident
 *   workers is roughly a workers × 1/s aggregate against MAL, not 1/s
 *   fleet-wide. `Retry-After` back-off from 429/503 is clamped to a finite
 *   cap so a resident worker can never be parked by an arbitrarily large
 *   header
 * - In-memory response caching — identical URLs are served from memory while
 *   fresh (1-hour TTL) and the cache is capped (512 entries, oldest evicted),
 *   so a resident worker's view of MAL cannot freeze at a stale snapshot
 * - NSFW default-deny: no `nsfw`/`nsfw_all` parameter is ever sent, so adult
 *   entries are excluded from search results and unresolvable via details;
 *   the details `rating` classification is surfaced as `adult_rating` for
 *   host-side gating
 * - Optional SSL verification toggle (`use_ssl_verification`) for self-hosted
 *   proxies — honored via the HTTP client's TLS stream context; when disabled
 *   a warning is logged on client build (never silently unverified)
 * - No enable-time probe: the HTTP client is built lazily on first request
 *   (onEnable does zero network I/O, never waits on the rate limiter); a bad
 *   Client ID surfaces on the first lookup as a 4xx → "no results", never a
 *   boot-time failure
 * - Filename heuristics to extract a clean search query
 * - Poster (main_picture) + fanart (additional `pictures`) image extraction
 * - Maps MAL responses to the legacy lookup() return contract (the live host
 *   path is the adapter triad; see {@see mapToMetadataReturn()})
 *
 * ## Registration model (era-bridging — PG-175)
 *
 * This provider reaches the host through TWO seams and both stay live on
 * purpose during the registration era:
 *
 * 1. The duck-typed legacy path — {@see registerWithMetadataManager()} sniffs
 *    the host container's `Phlix\Media\Metadata\MetadataManager` for a
 *    `registerProvider` method and pushes the adapter through it. That feed
 *    is consumed only while the server-side MetadataManager::refreshItemMetadata()
 *    still reads its internal providers/providers-by-type map.
 * 2. The interface path — the server's PluginLoader independently auto-registers
 *    this instance with the SourceRegistry whenever it satisfies
 *    {@see MetadataSourceInterface} (the class implements it above), no plugin
 *    cooperation required.
 *
 * The legacy map has no removal API server-side (MetadataManager exposes
 * registerProvider only — no removeProvider/deregister/unregister), which is
 * exactly why a runtime disable cannot un-register anything and
 * {@see onDisable()} carries the inert latch instead (PG-171). Retiring seam
 * 1 is tracked against the server-side migration off that map: when
 * refreshItemMetadata stops reading it, delete the
 * {@see registerWithMetadataManager()} leg and nothing else changes.
 *
 * ## Configuration (plugin.json settings)
 *
 * - client_id: MyAnimeList API Client ID (required, secret)
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
 * @since 0.2.0
 */
class MyanimelistMetadataProvider implements LifecycleInterface, MetadataSourceInterface
{
    /**
     * MAL API v2 base URL.
     */
    private const API_BASE = 'https://api.myanimelist.net/v2';

    /**
     * MAL API v2 hostname — the TLS peer identity pinned in the stream
     * context built by {@see tlsContext()}.
     */
    private const API_HOST = 'api.myanimelist.net';

    /**
     * Header name carrying the MAL Client ID on every request.
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
     * MAL suggests approximately one request per second. The enforcing
     * state ($lastRequestTimestamp / $retryAfterUntil) is process-local,
     * so this spaces each resident worker's OWN requests: a fleet of ~14
     * workers is roughly 14/s aggregate, not 1/s fleet-wide.
     */
    private const RATE_LIMIT_INTERVAL_SEC = 1.0;

    /**
     * Upper bound (seconds) applied to a `Retry-After` back-off read from a
     * 429/503 response.
     *
     * A malformed or hostile header (seconds-since-epoch values, or numeric
     * strings like `1e999` that overflow to INF) must never park a resident
     * worker indefinitely: finite values are clamped to this cap and
     * non-finite values fall back to the default interval.
     */
    private const MAX_RETRY_AFTER_SEC = 300.0;

    /**
     * How long (seconds) an in-memory cached API response stays fresh.
     *
     * The provider lives in long-lived resident workers; without a TTL a URL
     * fetched once would be served from cache forever, silently defeating the
     * server's `metadata.cache_ttl_hours` policy.
     */
    private const CACHE_TTL_SEC = 3600.0;

    /**
     * Maximum number of entries kept in the in-memory response cache.
     *
     * Writes evict the least-recently-inserted entry beyond this bound,
     * capping per-worker memory growth.
     */
    private const CACHE_MAX_ENTRIES = 512;

    /**
     * Upper bound (bytes) for a response body this provider will decode.
     *
     * 8 MiB is orders of magnitude beyond any legitimate MAL detail/search
     * payload (tens of KiB), so real traffic never brushes it — but it bounds
     * what a hostile or broken endpoint can buy with ONE cacheable 2xx:
     * without a cap, an oversized body is json_decode()'d and then parked in
     * the response cache (up to {@see CACHE_MAX_ENTRIES} entries), turning a
     * single response into sustained decode and memory amplification.
     *
     * Honest scope (PG-173): the vendored client buffers the FULL peer body in
     * memory before the success callback runs, so this cap does not prevent
     * network-level buffering — it prevents the DECODE COST and the CACHE
     * RESIDENCY from multiplying the exposure. An over-cap response takes the
     * same path as invalid JSON: null, never cached.
     */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /**
     * Fields requested from the MAL anime-details endpoint.
     *
     * MAL returns nested data ONLY for fields listed here: trim
     * `alternative_titles`/`genres`/`studios`/`pictures` from the list and
     * the failure is silent, not loud — titles lose their English/Japanese/
     * synonym variants (scoring degrades to main-title matching only),
     * genres and studio come back empty, `fanart_url` becomes null, and the
     * `adult_rating` gate input disappears. No error is raised anywhere;
     * metadata just quietly degrades. Keep every consumed field listed.
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
     * TLS certificate-verification posture, resolved ONCE at the constructor
     * boundary from the `use_ssl_verification` setting (coercion table on
     * {@see __construct()}). Fail-safe: only a genuine boolean `false`
     * disables verification; every other value — absent, null, true, or any
     * mistyped/ambiguous non-bool — resolves to `true`. Consumed by
     * {@see getHttpClient()} when it builds the pooled client's stream
     * context; nothing re-reads the raw settings array afterwards.
     */
    private bool $verifyTls;

    /**
     * The Client ID exactly as validated by the constructor boundary —
     * non-empty, printable ASCII (RFC 9110 field-value). Request construction
     * uses this parsed value directly instead of re-trusting the settings
     * array with fresh null-coalesce hedges on every request.
     */
    private string $clientId;

    /**
     * Unix timestamp (with microseconds) of the last API request, for rate limiting.
     */
    private float $lastRequestTimestamp = 0.0;

    /**
     * Unix timestamp (with microseconds) before which no further request may be
     * issued, set from a `Retry-After` header on a 429/503 response.
     *
     * The recorded deadline is clamped: the header's delay never exceeds
     * {@see MAX_RETRY_AFTER_SEC} and never enters $this as a non-finite value.
     *
     * 0.0 means no back-off is currently in effect.
     */
    private float $retryAfterUntil = 0.0;

    /**
     * In-memory response cache: md5(url) => [decoded JSON object, fetch time].
     *
     * Entries are checked against {@see CACHE_TTL_SEC} at read time and the
     * map is capped at {@see CACHE_MAX_ENTRIES} entries (oldest-inserted
     * evicted on write), so a resident worker's view of MAL cannot freeze at
     * a stale snapshot or grow without bound.
     *
     * @var array<string, array{0: array<string, mixed>, 1: float}>
     */
    private array $cache = [];

    /**
     * PG-171 lifecycle latch, closed by {@see onDisable()}.
     *
     * {@see onEnable()} registers a host adapter with the server's
     * MetadataManager, whose provider map exposes NO removal API: after a
     * disable the adapter object stays registered and would otherwise keep
     * driving this instance's two public bridge calls
     * ({@see findIdByTitle()} / {@see fetchAnimeMetadata()}) — an orphan
     * consuming the MAL API with the stored Client ID behind the disabled
     * plugin's back. While latched, both bridge calls return empty without
     * touching transport, so the orphaned adapter surface goes inert
     * immediately. The latch is per-instance and permanent: re-enable builds a
     * FRESH provider instance (the server re-instantiates the entry class), so
     * this flag never needs to open again.
     */
    private bool $disabled = false;

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
     *     Client ID, sent as the X-MAL-CLIENT-ID header on every request.
     *     use_ssl_verification toggles TLS certificate verification (default
     *     true). It is parsed here, once, into {@see $verifyTls} with this
     *     fail-safe coercion table (MAL-13 direction: an ambiguous value can
     *     never silently disable peer authentication):
     *
     *       | raw `use_ssl_verification` value          | $verifyTls |
     *       |-------------------------------------------|------------|
     *       | key absent / null                         | true       |
     *       | bool true                                 | true       |
     *       | bool false                                | false      |
     *       | any other value (e.g. "false", "0", 0,    | true       |
     *       |   "", "off", arrays — not a real bool)    |            |
     *
     *     Only the boolean `false` the plugin.json `"type": "boolean"`
     *     setting stores for an unticked toggle is a genuine disable.
     *
     * @throws \InvalidArgumentException
     *     when the required secret `client_id` is missing or blank. The
     *     setting is declared `required: true, secret: true` in plugin.json;
     *     accepting it empty would paper the absence over as an empty header
     *     value (silently "no results", the same invisible outage the
     *     transport-failure logging at {@see handleResponseObject()} exists
     *     to surface). Fail fast at the boundary instead.
     */
    public function __construct(array $settings)
    {
        $clientId = (string) ($settings['client_id'] ?? '');
        if (trim($clientId) === '') {
            throw new \InvalidArgumentException(
                'MyAnimeList plugin requires a non-empty "client_id" setting '
                . '(the MyAnimeList API Client ID, sent as the X-MAL-CLIENT-ID '
                . 'header). Configure it in the plugin settings before enabling.'
            );
        }

        // The value is interpolated into a request header verbatim, so it must
        // be printable ASCII (RFC 9110 field-value): a control character —
        // CR/LF above all — would be a header-injection primitive, and spaces
        // are not part of any MAL-issued Client ID. Reject the RAW stored
        // value (not the trim of it): the blank check above already covers
        // whitespace-only settings; anything else non-printable means the
        // config itself is malformed or hostile and no request may carry it.
        // The anchor is \z, not $: PCRE's $ also matches immediately before a
        // final newline, so "abc\n" would sail through a $-anchored guard and
        // reach the wire with a trailing LF inside the header value (PG-179).
        if (preg_match('/^[\x21-\x7E]+\z/', $clientId) !== 1) {
            throw new \InvalidArgumentException(
                'MyAnimeList "client_id" must contain only printable ASCII '
                . 'characters (no spaces, CR/LF or other control bytes) — '
                . 'it is sent verbatim as the X-MAL-CLIENT-ID header value.'
            );
        }

        $this->clientId = $clientId;

        // TLS posture boundary parse (coercion table in the docblock above):
        // strict identity against bool false, so only a genuine boolean
        // disable turns verification off — fail-safe for every other value.
        $this->verifyTls = ($settings['use_ssl_verification'] ?? true) !== false;
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
     * Visible test seam for the timing clock (PG-178) — NOT plugin API.
     *
     * The rate limiter and cache freshness read all time through
     * {@see self::$clock}; tests drive it with a fake clock instead of
     * sleeping wall time. These seams replace the former ReflectionProperty
     * injection of the private closures, which broke silently at runtime on
     * renames (phpstan analyses src/ only, and a renamed private property is
     * invisible to any static check over the test source).
     *
     * @param \Closure(): float $clock
     */
    public function setClockForTest(\Closure $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * Visible test seam for the rate-limit sleep (PG-178) — NOT plugin API.
     *
     * @param \Closure(float): void $timerSleep
     */
    public function setTimerSleepForTest(\Closure $timerSleep): void
    {
        $this->timerSleep = $timerSleep;
    }

    /**
     * Visible test seam for the cooperative-wait tick (PG-178) — NOT plugin API.
     *
     * @param \Closure(): void $waitTick
     */
    public function setWaitTickForTest(\Closure $waitTick): void
    {
        $this->waitTick = $waitTick;
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
     * This is legacy seam 1 of the two-seam registration model documented in
     * the class header (PG-175): it stays only while the server's
     * MetadataManager map is still consumed, and it can never be retracted at
     * runtime (no server-side removal API — hence the onDisable() inert
     * latch, PG-171). The SourceRegistry leg needs nothing from here.
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
     * @return int|null Best-scoring MAL anime ID (scored across every candidate's
     *     titles), or null if none — including always after {@see onDisable()},
     *     which latches this instance against the orphaned host adapter
     *     ({@see $disabled}).
     */
    public function findIdByTitle(string $title): ?int
    {
        if ($this->disabled) {
            return null;
        }

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
     * @return array<string, mixed> Mapped metadata array, or `[]` when not
     *     found — including always after {@see onDisable()}, which latches
     *     this instance against the orphaned host adapter ({@see $disabled}).
     */
    public function fetchAnimeMetadata(int $malId): array
    {
        if ($this->disabled) {
            return [];
        }

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
     * Drops the in-memory response cache (defense-in-depth for credential
     * rotation: bodies fetched under the old Client ID are never replayed by
     * a later instance). What this hook CANNOT do is tear down the HTTP
     * transport: once the first API call built the shared
     * {@see Client} lazily, its pooled connections to api.myanimelist.net:443
     * outlive this method — the vendored client exposes no public close API
     * (ConnectionPool has only closeTimeoutConnection()), and dropping the
     * registration detaches the instance so the worker's next GC reclaims
     * it along with its pool. The "no open sockets" claim that used to live
     * here was true only pre-first-request.
     *
     * The hook ALSO closes the PG-171 lifecycle latch: the host-side
     * MetadataManager has no provider-removal API, so the adapter registered
     * by {@see onEnable()} survives this call and remains reachable by host
     * metadata refreshes. Latching makes the two bridge calls the adapter
     * routes through ({@see findIdByTitle()} / {@see fetchAnimeMetadata()})
     * return empty without touching transport, so the orphaned registration
     * can no longer consume the API with this plugin's Client ID. The latch
     * is per-instance and permanent: a later re-enable operates on a fresh
     * instance built by the loader.
     *
     * @return void
     */
    public function onDisable(): void
    {
        $this->disabled = true;
        $this->cache = [];
    }

    /**
     * Return the PSR-14 listener subscriptions this plugin wants.
     *
     * The host invokes this plugin through the adapter registered in
     * {@see onEnable()} — search()/getDetails()/getImages() — rather than
     * through the PSR-14 event dispatcher, so no subscriptions.
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
     * @deprecated since v0.3.0 — the host does not call this entry point; the
     *     live path is the adapter triad ({@see MyanimelistMetadataProviderAdapter})
     *     plus SourceRegistry, as the README states. Kept for interface parity
     *     only; removal is planned for the next minor release (v0.4.0), at
     *     which point the filename corpus it alone exercises goes too.
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
     *     studio: string|null,
     *     adult_rating: string|null
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

        if ($malId <= 0) {
            // Defensive boundary: MAL ids are 1-based, so 0/negative can only
            // be malformed API data. Never carry it into the details fetch
            // (`/anime/0` is not a thing) nor out as `mal_id` to the host.
            return [];
        }

        // Step 3: Fetch full anime details from MAL
        $anime = $this->fetchAnimeDetails($malId);
        if ($anime === null) {
            return [];
        }

        // Step 4: Map to this plugin's legacy lookup() return contract
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
     * Cached bodies are served only while fresh ({@see CACHE_TTL_SEC}); an
     * expired entry is dropped and the URL refetched.
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
     *
     * Protected by design (PG-177): this is the class's ONLY protected method
     * and it is a load-bearing test-override seam — every hermetic network
     * double (the provider suite's anonymous subclasses, the transport file's
     * TransportDriver) replaces exactly this leg, which is also why the class
     * is deliberately not final. New code must not widen the protected
     * surface without treating the override set as its API contract.
     */
    protected function httpGetJson(string $url): ?array
    {
        $cacheKey = md5($url);
        if (isset($this->cache[$cacheKey])) {
            /** @var array{0: array<string, mixed>, 1: float} $entry */
            $entry = $this->cache[$cacheKey];
            if (($this->clock)() - $entry[1] < self::CACHE_TTL_SEC) {
                return $entry[0];
            }

            // Fresh-window expired: drop the entry and refetch.
            unset($this->cache[$cacheKey]);
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
            self::CLIENT_ID_HEADER => $this->clientId,
            'Accept' => 'application/json',
        ];

        /** @var array<string, mixed> $options */
        $options = [
            'method'  => 'GET',
            'headers' => $headers,
            'timeout' => self::HTTP_TIMEOUT_SEC,
            // Redirects are REFUSED outright: the vendored client (v3.1.3)
            // defaults to following up to 5, and Request::redirect() re-issues
            // the SAME options — our `headers` with X-MAL-CLIENT-ID included —
            // against whatever Location the server names, cross-origin
            // included. max 0 makes the first 3xx surface to the success
            // callback, where handleResponseObject() treats it as the non-2xx
            // failure it is. MAL API v2 does not redirect JSON endpoints.
            'allow_redirects' => ['max' => 0],
        ];

        // TLS posture is NOT controlled here: workerman/http-client never
        // reads a `verify_ssl` request option. The `use_ssl_verification`
        // toggle is honored once, at the client/pool layer, by
        // {@see getHttpClient()} — the only place the vendored
        // ConnectionPool::create() actually consults (its `context` option).

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
            $this->logger?->debug('MAL API request threw: ' . $this->scrubForLog($e->getMessage()));

            return null;
        }

        // Cooperative wait — yields to the event loop so other tasks proceed
        // while the response is in flight. Bounded by WALL CLOCK (the same
        // seconds the request `timeout` is declared in) plus a small grace, so
        // a lost callback can never wedge the worker: tick-counting would let
        // any runtime that coalesces or fast-forwards ticks stretch the bound
        // to arbitrary wall time.
        $deadline = ($this->clock)() + self::HTTP_TIMEOUT_SEC + 1.0;
        while (!$state['done'] && ($this->clock)() < $deadline) {
            ($this->waitTick)();
        }

        if ($state['error'] !== null) {
            $this->logger?->debug(
                'MAL API transport error: ' . $this->scrubForLog($state['error']->getMessage())
            );

            return null;
        }

        return $state['response'];
    }

    /**
     * Sanitize a transport exception message for the log line.
     *
     * These messages are NOT purely local text: the vendored transport
     * composes them from REMOTE bytes — e.g. Request::onMessage throws
     * `Invalid response string: <start-line>` and unexpected-close errors
     * embed the peer address. Control characters (NUL, ESC sequences, raw
     * CR/LF beyond the line break our logger itself appends) would enter the
     * log file verbatim, enabling terminal/log injection for anyone who
     * later `cat`s or greps it. Keep printable ASCII, cap the length: the
     * diagnosis survives, the payload does not.
     */
    private function scrubForLog(string $message): string
    {
        $truncated = substr($message, 0, 200);

        return preg_replace('/[^\x20-\x7E]/', '?', $truncated) ?? $truncated;
    }

    /**
     * Apply the status-inspection, back-off and cache-only-on-2xx policy to a
     * Workerman HTTP Response object.
     *
     * Called by {@see httpGetJson()} once {@see requestAndWait()} resolves a
     * response. It reads the status code directly from the Response object and
     * extracts the body string for JSON decoding, preserving the 429/503
     * back-off, non-2xx → null, and cache-on-2xx-only JSON-object validation
     * policy. Objecthood is enforced strictly (PG-174): a decoded top-level
     * list — including '{}' (the empty array) — is the failure path, never a
     * cacheable value. The body is size-bounded against
     * {@see self::MAX_RESPONSE_BYTES} before decode or cache (PG-173).
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
            // Surface the failure with its status code (never headers or body)
            // so a wrong/revoked Client ID (401/403) is diagnosable instead of
            // a silent, forever "no results". A rejected credential is the
            // operator-error case worth a warning; everything else is debug.
            if ($status === 401 || $status === 403) {
                $this->logger?->warning(
                    'MAL API returned HTTP ' . $status . ' — check the configured client_id '
                    . '(revoked/invalid Client ID); request will not be cached.'
                );
            } else {
                $this->logger?->debug('MAL API returned HTTP ' . $status);
            }

            if ($status === 429 || $status === 503) {
                $this->recordRetryAfterFromResponse($response);
            }

            return null;
        }

        // PG-173: bound the body before it is decoded or cached. A declared
        // Content-Length over the cap is rejection on its own terms — the
        // header itself is hostile or broken — whatever the body turns out to
        // be. Never log body content, only sizes (repo logging posture).
        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== '' && ctype_digit($declaredLength)
            && (int) $declaredLength > self::MAX_RESPONSE_BYTES) {
            $this->logger?->warning(
                'MAL API declared a ' . $declaredLength . '-byte body, over the '
                . self::MAX_RESPONSE_BYTES . '-byte response cap; response rejected, not cached.'
            );

            return null;
        }

        $stream = $response->getBody();
        $streamSize = $stream->getSize();
        if ($streamSize !== null && $streamSize > self::MAX_RESPONSE_BYTES) {
            $this->logger?->warning(
                'MAL API returned a ' . $streamSize . '-byte body, over the '
                . self::MAX_RESPONSE_BYTES . '-byte response cap; response rejected, not cached.'
            );

            return null;
        }

        $body = $stream->getContents();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $this->logger?->warning(
                'MAL API body exceeded the ' . self::MAX_RESPONSE_BYTES
                . '-byte response cap; response rejected, not cached.'
            );

            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        // PG-174: only a JSON OBJECT is a valid MAL response. json_decode(…,
        // true) hands a top-level list back as an array too, and the old
        // is_array-only gate accepted it — the array<string,mixed> stamp then
        // laundered the lie into the cache and every downstream key read.
        // Disclosed tightening: '{}' decodes to the empty array, which is
        // list-shaped, so an empty body now takes the failure path (null,
        // uncached). The real zero-results shape is {"data":[]} — a keyed
        // object — and is unaffected.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        // Boundary type (now earned, not laundered): with the list gate above
        // passed, the document was a JSON object, and JSON object keys are
        // strings by definition.
        /** @var array<string, mixed> $decoded */
        $this->cache[$cacheKey] = [$decoded, ($this->clock)()];

        // Cap the cache: PHP arrays preserve insertion order and read hits do
        // not re-insert, so the first key is the least-recently-fetched entry.
        if (count($this->cache) > self::CACHE_MAX_ENTRIES) {
            $oldest = array_key_first($this->cache);
            if ($oldest !== null) {
                unset($this->cache[$oldest]);
            }
        }

        return $decoded;
    }

    /**
     * Get or create the shared HTTP client instance.
     *
     * This is the lazy "connect" step: the client is built on first use (the
     * first real API call), never at plugin-enable / worker-boot, keeping
     * {@see onEnable()} free of any socket setup. The TLS verification
     * posture was already parsed at the constructor boundary into
     * {@see $verifyTls}; this is the single point that hands it to the
     * vendored pool (via the client-constructor `context`, the only TLS input
     * it consults), so it stays fixed for the life of this provider instance.
     *
     * Disabling verification is logged as a warning: the credential-bearing
     * HTTPS path silently loses peer authentication and must never go quiet.
     */
    private function getHttpClient(): Client
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        if ($this->verifyTls === false) {
            // Attribution: the channel is still TLS-encrypted; what is lost is
            // authentication of the PEER, so the credential reaches a server
            // whose identity nobody checked (MAL-09 wording).
            $this->logger?->warning(
                'MAL plugin: use_ssl_verification is disabled, so TLS certificate '
                . 'verification is OFF for ' . self::API_HOST . ' requests. Only use '
                . 'this behind a trusted self-hosted proxy; the X-MAL-CLIENT-ID '
                . 'credential then travels to a peer whose identity is not verified.'
            );
        }

        return $this->httpClient = new Client([
            'timeout' => self::HTTP_TIMEOUT_SEC,
            'context' => self::tlsContext($this->verifyTls),
        ]);
    }

    /**
     * Build the complete `ssl` stream context for pooled HTTPS connections.
     *
     * workerman/http-client v3.1.3 ConnectionPool::create() defaults to
     * `verify_peer => false, verify_peer_name => false, allow_self_signed =>
     * true` and SHALLOW-merges a client-constructor `context` over those
     * defaults, so the whole `ssl` section must be stated explicitly — a
     * partial one would let the lax defaults be replaced by a sparser block
     * and leave flags unset. `peer_name` is pinned to the MAL API host and
     * SNI is sent explicitly because the vendor only auto-sets peer_name on
     * proxy paths. Mirrors the estate idiom shipped for the same vendor
     * family by phlix-hub (519c0c0) and phlix-plugin-anilist (983a6e7).
     *
     * @param bool $verifyTls True to enforce certificate verification, false
     *     only for the documented self-hosted-proxy escape hatch.
     *
     * @return array{ssl: array<string, mixed>} A complete `ssl` stream context.
     */
    private static function tlsContext(bool $verifyTls): array
    {
        return ['ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
            'allow_self_signed' => !$verifyTls,
            'SNI_enabled' => true,
            'peer_name' => self::API_HOST,
        ]];
    }

    /**
     * Record a back-off window from a 429/503 response's Retry-After header
     * (extracted from a Workerman Response object).
     *
     * RFC 9110 allows two forms and both are honored: delta-seconds
     * (`Retry-After: 30`) and an HTTP-date (`Retry-After: Wed, 21 Oct 2026
     * 07:28:00 GMT`), parsed strictly against the RFC 7231 fixed shape —
     * deliberately NOT a loose `strtotime()` grammar, which accepts relative
     * junk a server should never send.
     *
     * Malformed and non-canonical dates — weekday contradicting the calendar
     * fields, stray spacing — are refused exactly like unparseable ones: the
     * parse must reproduce the header verbatim on a canonical RFC 7231
     * re-render to be accepted.
     *
     * The recorded delay is bounded: non-finite numeric strings (e.g.
     * `1e999`, which overflows to INF), unparseable dates and dates already
     * in the past fall back to the default interval, and finite values are
     * clamped into [0, {@see MAX_RETRY_AFTER_SEC}] before they reach
     * {@see enforceRateLimit()} — a single header must never be able to park
     * a resident worker for an arbitrary duration.
     *
     * @param Response $response Workerman HTTP Response object.
     *
     * @return void
     */
    private function recordRetryAfterFromResponse(Response $response): void
    {
        $delaySeconds = self::RATE_LIMIT_INTERVAL_SEC;

        $retryAfter = $response->getHeaderLine('Retry-After');
        if ($retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                $candidate = (float) $retryAfter;
                if (is_finite($candidate)) {
                    $delaySeconds = min(max($candidate, 0.0), self::MAX_RETRY_AFTER_SEC);
                }
            } else {
                // HTTP-date form: the wait is the distance from NOW to the
                // instant named (never the raw timestamp); a past date means
                // the server already released us, so keep the safe default.
                // The timezone argument is REQUIRED, not decorative: RFC 7231
                // dates are by definition GMT, but createFromFormat's `\G\M\T`
                // is a literal — without an explicit UTC the wall-clock
                // fields would be read in the host timezone, shifting every
                // instant by the host offset (a +14400s "wait" in NY).
                $when = \DateTimeImmutable::createFromFormat(
                    \DateTimeInterface::RFC7231,
                    $retryAfter,
                    new \DateTimeZone('UTC')
                );
                // Strictness made real (PG-060): createFromFormat resolves a
                // contradictory weekday by shifting the DAY forward to the
                // next date matching the named weekday — silently yielding a
                // different instant than the calendar fields describe. A
                // truthful RFC 7231 parse must be canonical: accept the
                // result only when the formatted round-trip reproduces the
                // header verbatim. Contradictory or non-canonical dates then
                // take the same safe-default path as unparseable ones.
                if ($when !== false && $when->format(\DateTimeInterface::RFC7231) === $retryAfter) {
                    $candidate = (float) ($when->getTimestamp() - (int) floor(($this->clock)()));
                    if (is_finite($candidate) && $candidate > 0.0) {
                        $delaySeconds = min($candidate, self::MAX_RETRY_AFTER_SEC);
                    }
                }
            }
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
     * Scoring algorithm (exact > prefix > contains, best candidate wins):
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
     * Score each search result against all its titles and return the best match.
     *
     * MAL search returns results ranked by popularity, which may not correspond
     * to the user's intent when querying by a subtitle, short name, or
     * alternative title. This method evaluates every candidate across all of its
     * available titles (main, English, Japanese, synonyms) using the
     * exact/prefix/contains scoring algorithm:
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
            if ($id <= 0) {
                // No usable MAL id: non-numeric, zero or negative nodes can
                // never be fetched for details — scoring them would let a
                // junk row win and surface id 0 to the caller.
                continue;
            }

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
     * Matching is symmetric (PG-172): the query sitting inside/starting the
     * title and the title sitting inside/starting the query score on the
     * same 800-prefix / 600-contains bands, length-difference-penalized.
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

            // PG-172: symmetric bands. A registered title that is itself the
            // query's prefix or substring used to score zero, so the
            // sequel-shaped lookup ("One Piece TV" against candidate
            // "One Piece") silently lost the match. Mirroring the forward
            // bands at the same strengths fixes that. An EMPTY title is
            // never a reverse prefix of any query — without the guard, one
            // blank alternative-title entry would make its candidate score
            // 800 - |queryLen| against EVERY query.
            if ($titleLower !== '') {
                if (str_starts_with($queryLower, $titleLower)) {
                    $score = 800 - abs($queryLen - $titleLen);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                    }
                }

                if (str_contains($queryLower, $titleLower)) {
                    $score = 600 - abs($queryLen - $titleLen);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                    }
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
        // The docblock contract, now enforced: no usable ID -> null. MAL ids
        // are 1-based naturals, so "usable" means numeric and positive; a
        // non-numeric, zero or negative id is not laundered into 0 but makes
        // the whole record unaddressable (it can never be fetched or cited).
        $rawId = $raw['id'] ?? null;
        if (!is_numeric($rawId) || (int) $rawId <= 0) {
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
        // `!== ''` matters: MAL (and any partial `fields=` response) can carry
        // an empty string where artwork should be — an '' would defeat the
        // `??` fallbacks below and paint "poster exists but is blank".
        $pictureLarge = isset($mainPicture['large']) && is_string($mainPicture['large'])
            && $mainPicture['large'] !== ''
            ? $mainPicture['large']
            : null;
        $pictureMedium = isset($mainPicture['medium']) && is_string($mainPicture['medium'])
            && $mainPicture['medium'] !== ''
            ? $mainPicture['medium']
            : null;

        $genres = [];
        if (isset($raw['genres']) && is_array($raw['genres'])) {
            foreach ($raw['genres'] as $genre) {
                if (is_array($genre) && isset($genre['name']) && is_string($genre['name'])
                    && $genre['name'] !== ''
                ) {
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

        // MAL's adult `rating` classification (g, pg-13, r-17, rx) — requested
        // via DETAIL_FIELDS and surfaced for host-side gating. Distinct from
        // the mean user score, which the internal 'rating' key already holds.
        $adultRating = isset($raw['rating']) && is_string($raw['rating']) && $raw['rating'] !== ''
            ? $raw['rating']
            : null;

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
            // Guaranteed usable by the gate at the top of this method.
            'id'             => (int) $rawId,
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
            'adult_rating'   => $adultRating,
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
     * @deprecated since v0.3.0 — exercised only by the legacy
     *     {@see lookup()} path the host no longer calls (PG-057); removal is
     *     planned for v0.4.0 together with it.
     *
     * @param string $filePath Absolute path to media file.
     *
     * @return string|null Extracted title or null if no clear match.
     */
    private function extractAnimeName(string $filePath): ?string
    {
        /** @var string $filename pathinfo() returns string|false, never null for PATHINFO_FILENAME */
        $filename = pathinfo($filePath, PATHINFO_FILENAME) ?: '';

        // Every strip coerces its result to string: preg_replace() returns
        // null only on a PCRE runtime error, and (string) null === '' —
        // byte-identical to the per-step `?? ''` hedges these lines carried
        // (PG-058). Subjects are therefore string by construction.
        // Strip common release group patterns: [GroupName], (TX), (...)
        $clean = (string) preg_replace('/\[[^\]]+\]/', '', $filename);
        $clean = (string) preg_replace('/\(TX\)/', '', $clean);
        $clean = (string) preg_replace('/\([^\)]+\)/', '', $clean);

        // Strip episode patterns: S01E02, 01x02, Episode 01, Episode.01
        // (standalone episode strip happens AFTER resolution/codec/source strip below).
        // PG-172: the previous per-letter-optional construction
        // ([Ee]p?[i]?[t]?[.]?) could never cross the 'sode' remainder or a
        // space separator, so the very "Episode 01" form this comment
        // promises survived polluted ("Show Episode 01" → "Show Episode"
        // after the later trailing-number shave). Word-form alternatives
        // under a separator class — with 'e'/'ep' still covering the short
        // 'E13'/'EP04' corpus shapes — make the claimed behavior real.
        $clean = (string) preg_replace('/[Ss]\d{1,2}[Ee]\d{1,4}/', '', $clean);
        $clean = (string) preg_replace('/\d{1,2}[Xx]\d{1,4}/', '', $clean);
        $clean = (string) preg_replace('/(?:^|[.\-_ ])(?:episode|ep|e)[ ._-]*\d{1,4}\b/i', '', $clean);

        // Strip common suffixes: 720p, 1080p, BluRay, HDTV, etc.
        // MUST run before standalone-episode strip so episode numbers become genuinely trailing
        $clean = (string) preg_replace(
            '/(720p|1080p|2160p|480p|BluRay|BRRip|HDRip|HDTV|DVDRip|x264|x265|HEVC|AAC|AC3)/i',
            '',
            $clean
        );

        // Collapse any consecutive dots left behind by resolution/codec stripping
        // (e.g. ".720p.BluRay.x264" → "..." then → ".")
        $clean = (string) preg_replace('/\.{2,}/', '.', $clean);

        // Strip year patterns: (2016), trailing 2001/2023
        $clean = (string) preg_replace('/\(\d{4}\)/', '', $clean);
        $clean = (string) preg_replace('/\s+\d{4}$/', '', $clean);

        // Strip resolution and codec patterns (e.g. 1920x1080)
        $clean = (string) preg_replace('/\d{3,4}[xX]\d{3,4}/', '', $clean);

        // Strip leading/trailing dashes, dots, underscores, spaces
        // Must happen BEFORE standalone-episode strip so trailing separators don't block episode detection
        $clean = trim($clean, '.-_ ');

        // Strip standalone episode numbers: leading separator before 1-4 digits at end
        // Now genuinely trailing after resolution/codec/source has been stripped
        $clean = (string) preg_replace('/[.\- ][0-9]{1,4}$/', '', $clean);

        // Re-trim: the strips above (episode number most visibly) detach text
        // from the right end and can leave a dangling separator —
        // 'My Hero Academia - 05' becomes 'My Hero Academia -' here, which the
        // pre-episode trim (running BEFORE these strips) could not have caught.
        // Without this, the dangling ' -' rides into the MAL query verbatim.
        $clean = trim($clean, '.-_ ');

        // Replace remaining dots with spaces (common in anime filenames)
        $clean = str_replace('.', ' ', $clean);

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
     * Map a parsed MAL anime array to the legacy {@see lookup()} return contract.
     *
     * The 17-key shape below is THIS plugin's own lookup() contract, not a
     * host-verified one: the live host drives the adapter triad
     * ({@see MyanimelistMetadataProviderAdapter}) and stores detail payloads
     * opaquely, so no server-side code pins these key names.
     *
     * @param array<string, mixed> $anime Parsed from parseAnimeResponse().
     *     Every key read below is set by that boundary (PG-058), so values
     *     are trusted as-is — no post-condition coalescing.
     *
     * @return array<string, mixed> Mapped metadata array (17 fixed keys).
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
            'fanart_url'    => $anime['fanart_url'],
            'episodes'      => is_int($anime['episodes']) && $anime['episodes'] > 0 ? $anime['episodes'] : null,
            'type'          => $this->mapType($anime['media_type']),
            'mal_id'        => $anime['id'],
            'titles'        => array_values(array_unique($allTitles)),
            'status'        => $this->mapStatus($anime['status']),
            'runtime_ticks' => $this->mapRuntimeTicks($anime['episode_length']),
            'studio'        => $anime['studio'],
            'adult_rating'  => $anime['adult_rating'],
        ];
    }

    /**
     * Map a MAL `media_type` value to a normalized type string.
     *
     * Normalization is intentionally pass-through-lowercase: the MAL v2
     * media_type vocabulary (tv, ova, movie, special, ona, music) already
     * arrives lowercase, so the former named match-arms were all identity
     * (PG-059 — documented as prose, not as a no-op table). A future mapping
     * onto the host media-type vocabulary (e.g. MAL tv → host series) belongs
     * HERE, as a real transformation with its own pinned tests.
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

        return strtolower($mediaType);
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
     * Durations at or beyond the largest safely-multipliable int are reported
     * as unknown (PG-176): parseAnimeResponse() saturates an out-of-range
     * numeric string to PHP_INT_MAX through its is_numeric cast, and under
     * strict_types an overflowing product would leave this `: ?int` as a
     * TypeError thrown inside the metadata refresh, not a null runtime.
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

        if ($seconds > intdiv(PHP_INT_MAX, self::TICKS_PER_SECOND)) {
            return null;
        }

        return $seconds * self::TICKS_PER_SECOND;
    }
}
