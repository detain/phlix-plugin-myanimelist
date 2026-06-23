<?php

declare(strict_types=1);

namespace Phlix\Myanimelist;

use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;

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
final class MyanimelistMetadataProvider implements LifecycleInterface
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
     * Host PSR-11 container for resolving services.
     */
    private ?ContainerInterface $container = null;

    /**
     * Unix timestamp (with microseconds) of the last API request, for rate limiting.
     */
    private float $lastRequestTimestamp = 0.0;

    /**
     * In-memory response cache: md5(url) => decoded JSON object.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * @param array{client_id: string, use_ssl_verification?: bool} $settings
     *     Plugin settings from plugin.json. client_id is the MyAnimeList API
     *     client ID, sent as the X-MAL-CLIENT-ID header on every request.
     *     use_ssl_verification toggles TLS certificate verification (default true).
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Called by the loader once when the plugin is enabled.
     *
     * Stashes the host container and performs a lightweight connectivity +
     * credential check (a one-result search) so a bad Client ID or unreachable
     * MAL surfaces immediately rather than on the first library scan.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     *
     * @throws \RuntimeException If MAL is unreachable or the Client ID is rejected.
     */
    public function onEnable(ContainerInterface $container): void
    {
        $this->container = $container;

        $check = $this->httpGetJson(
            self::API_BASE . '/anime?q=test&limit=1&fields=id'
        );
        if ($check === null) {
            throw new \RuntimeException(
                'MyAnimeList API unreachable. Check your Client ID and network connectivity.'
            );
        }
    }

    /**
     * Called by the loader once when the plugin is disabled.
     *
     * Releases the stashed container reference and drops the response cache.
     * MAL holds no open sockets, so there is nothing else to tear down.
     *
     * @return void
     */
    public function onDisable(): void
    {
        $this->container = null;
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
        $malId = $this->findIdByTitle($animeName);
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
     * @param string $url Absolute MAL API URL (already query-encoded).
     *
     * @return array<string, mixed>|null Decoded JSON object or null on
     *     transport/HTTP/JSON failure.
     */
    private function httpGetJson(string $url): ?array
    {
        $cacheKey = md5($url);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->enforceRateLimit();

        $httpOptions = [
            'method'        => 'GET',
            'header'        => self::CLIENT_ID_HEADER . ': ' . $this->settings['client_id'] . "\r\n"
                . "Accept: application/json\r\n",
            'timeout'       => self::HTTP_TIMEOUT_SEC,
            'ignore_errors' => true,
        ];

        $contextOptions = ['http' => $httpOptions];
        if (($this->settings['use_ssl_verification'] ?? true) === false) {
            $contextOptions['ssl'] = [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ];
        }

        $body = @file_get_contents($url, false, stream_context_create($contextOptions));
        if ($body === false) {
            return null;
        }

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
     * Sleep, if necessary, so consecutive API requests stay below the rate limit.
     *
     * @return void
     */
    private function enforceRateLimit(): void
    {
        $elapsed = microtime(true) - $this->lastRequestTimestamp;
        if ($elapsed < self::RATE_LIMIT_INTERVAL_SEC) {
            usleep((int) ((self::RATE_LIMIT_INTERVAL_SEC - $elapsed) * 1_000_000));
        }

        $this->lastRequestTimestamp = microtime(true);
    }

    // -------------------------------------------------------------------------
    // Private: Title Lookup
    // -------------------------------------------------------------------------

    /**
     * Find a MAL anime ID by title via the search endpoint.
     *
     * @param string $title Anime title to search for.
     *
     * @return int|null MAL anime ID of the first result, or null if none.
     */
    private function findIdByTitle(string $title): ?int
    {
        $url = self::API_BASE . '/anime?q=' . rawurlencode($title)
            . '&limit=' . self::SEARCH_LIMIT;

        $response = $this->httpGetJson($url);
        if ($response === null) {
            return null;
        }

        return $this->parseSearchResponse($response);
    }

    /**
     * Extract the first anime ID from a MAL search response.
     *
     * Response shape: `{ "data": [ { "node": { "id": 1, ... } }, ... ] }`.
     *
     * @param array<string, mixed> $response Decoded search JSON.
     *
     * @return int|null First node ID or null when the result set is empty.
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

        return (int) $node['id'];
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
            'id'             => (int) $raw['id'],
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
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        // Strip common release group patterns: [GroupName], (TX), (...)
        $clean = preg_replace('/\[[^\]]+\]/', '', $filename);
        $clean = preg_replace('/\(TX\)/', '', $clean);
        $clean = preg_replace('/\([^\)]+\)/', '', $clean);

        // Strip episode patterns: S01E02, 01x02, Episode 01, Episode.01, standalone 01
        $clean = preg_replace('/[Ss]\d{1,2}[Ee]\d{1,4}/', '', $clean);
        $clean = preg_replace('/\d{1,2}[Xx]\d{1,4}/', '', $clean);
        $clean = preg_replace('/[.\-_ ]*[Ee]p?[i]?[t]?[.]?\d{1,4}/i', '', $clean);
        // Strip standalone episode numbers: leading ., -, _, space before 1-4 digits at end
        $clean = preg_replace('/[.\- ][0-9]{1,4}$/', '', $clean);

        // Strip common suffixes: 720p, 1080p, BluRay, HDTV, etc.
        $clean = preg_replace('/(720p|1080p|2160p|480p|BluRay|BRRip|HDRip|HDTV|DVDRip|x264|x265|HEVC|AAC|AC3)/i', '', $clean);

        // Strip year patterns: (2016), trailing 2001/2023
        $clean = preg_replace('/\(\d{4}\)/', '', $clean);
        $clean = preg_replace('/\s+\d{4}$/', '', $clean);

        // Strip resolution and codec patterns
        $clean = preg_replace('/\d{3,4}[xX]\d{3,4}/', '', $clean);

        // Strip leading/trailing dashes, dots, underscores, spaces
        $clean = trim((string) $clean, '.-_ ');

        // Replace remaining dots with spaces (common in anime filenames)
        $clean = str_replace('.', ' ', $clean);

        // If result is too short or looks like garbage, skip
        if (strlen($clean) < 2) {
            return null;
        }

        return $clean !== '' ? $clean : null;
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
     * @param string|null $mediaType Raw MAL media_type (e.g. "tv", "movie").
     *
     * @return string|null Normalized type or null when unknown/absent.
     */
    private function mapType(?string $mediaType): ?string
    {
        if ($mediaType === null || $mediaType === '') {
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
     * @param string|null $status Raw MAL status string.
     *
     * @return string|null Mapped status or null when unknown/absent.
     */
    private function mapStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
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
     * @param int|null $seconds Average episode duration in seconds.
     *
     * @return int|null Runtime in ticks, or null when duration is unknown/zero.
     */
    private function mapRuntimeTicks(?int $seconds): ?int
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return $seconds * self::TICKS_PER_SECOND;
    }
}
