<?php

/**
 * Unit Myanimelistmetadataprovidertest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Myanimelist\Tests\Unit;

use Phlix\Myanimelist\MyanimelistMetadataProvider;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for the MyanimelistMetadataProvider plugin.
 *
 * These tests cover the filename-parsing logic, JSON response parsing,
 * field mapping, and helper mappers without requiring network access.
 * Private methods are exercised via Reflection; nothing here issues a
 * real HTTP request.
 */
final class MyanimelistMetadataProviderTest extends TestCase
{
    /**
     * Build a provider instance with test settings.
     */
    private function makeProvider(): MyanimelistMetadataProvider
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        // Inject a fake timerSleep that uses usleep (blocking) instead of
        // Workerman\Timer::sleep() so the test can run without the workerman library.
        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('timerSleep');
        $prop->setAccessible(true);
        $prop->setValue(
            $provider,
            static function (float $seconds): void {
                usleep((int) ($seconds * 1_000_000));
            }
        );

        return $provider;
    }

    public function test_implements_shared_metadata_source_contract(): void
    {
        $provider = $this->makeProvider();

        $this->assertInstanceOf(MetadataSourceInterface::class, $provider);
        $this->assertSame('myanimelist', $provider->sourceName());
        // anime is not a media_items.type ENUM member; MAL answers for the
        // real stored types series + movie and matches by title.
        $this->assertSame(['series', 'movie'], $provider->supportedMediaTypes());
    }

    public function test_metadata_source_lookups_return_empty_for_invalid_external_id(): void
    {
        $provider = $this->makeProvider();

        // Invalid external ids short-circuit through the adapter's parseMalId()
        // guard to an empty result — no network call.
        $this->assertSame([], $provider->getDetails('not-a-mal-id'));
        $this->assertSame([], $provider->getDetails('0'));
        $this->assertSame([], $provider->getImages('not-a-mal-id'));
    }

    /**
     * Invoke a private method on the provider via Reflection.
     *
     * @param array<int, mixed> $args
     */
    private function invokePrivate(MyanimelistMetadataProvider $provider, string $method, array $args): mixed
    {
        $reflection = new \ReflectionClass($provider);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($provider, $args);
    }

    /**
     * Read a private property from the provider via Reflection.
     */
    private function readPrivate(MyanimelistMetadataProvider $provider, string $property): mixed
    {
        $reflection = new \ReflectionClass($provider);
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);

        return $reflectionProperty->getValue($provider);
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function test_subscribed_events_returns_empty_array(): void
    {
        $this->assertSame([], $this->makeProvider()->subscribedEvents());
    }

    public function test_on_enable_does_not_throw(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $provider = $this->makeProvider();
        // Should not throw even without MetadataManager present
        $provider->onEnable($container);
        $this->assertTrue(true);
    }

    /**
     * Regression guard for the production timeout bug: `onEnable()` used to
     * perform a live connectivity/credential check (a blocking-ish MAL API
     * call under the rate limiter) before registering with the host, so a
     * slow/unreachable MyAnimeList API made the admin "enable plugin"
     * request hang until the caller's own timeout fired ("The request timed
     * out"). Fixed by removing that call entirely (commit 337c355).
     *
     * Asserting "does not throw" alone would not have caught a
     * reintroduction of that call, since a successful-but-slow HTTP
     * response also does not throw. This test additionally asserts
     * `onEnable()` returns near-instantly: if it ever again reaches
     * {@see enforceRateLimit()} on an unthrottled first call, it would take
     * at least `RATE_LIMIT_INTERVAL_SEC` (1s via the injected blocking
     * `usleep` timerSleep) — this fails long before that.
     */
    public function test_on_enable_completes_without_network_io_or_rate_limit_delay(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $provider = $this->makeProvider();

        $start = microtime(true);
        $provider->onEnable($container);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            0.25,
            $elapsed,
            'onEnable() took ' . $elapsed . 's — it must never perform network I/O or wait out '
                . 'the rate limiter; either would surface to the admin UI as "the request timed out".',
        );
    }

    /**
     * registerWithMetadataManager is a no-op when manager exists in container
     * but does not have the registerProvider method. This exercises lines 247-248.
     */
    public function test_register_with_metadata_manager_is_noop_when_manager_lacks_register_provider(): void
    {
        $managerClass = 'Phlix\\Media\\Metadata\\MetadataManager';

        // A manager object that exists but lacks registerProvider method
        $brokenManager = new class {
            public function someOtherMethod(): void
            {
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => $id === $managerClass);
        $container->method('get')
            ->willReturnCallback(static function (string $id) use ($managerClass, $brokenManager) {
                if ($id === $managerClass) {
                    return $brokenManager;
                }
                throw new \RuntimeException('unexpected container id: ' . $id);
            });

        $provider = $this->makeProvider();

        $ref = new \ReflectionMethod($provider, 'registerWithMetadataManager');
        $ref->setAccessible(true);

        // Must not throw - should detect missing registerProvider and return early
        $ref->invoke($provider, $container);
        $this->assertTrue(true);
    }

    public function test_lookup_returns_empty_for_unparseable_filename(): void
    {
        // 'S01E01' strips to '' (< 2 chars) → lookup short-circuits before any HTTP.
        $this->assertSame([], $this->makeProvider()->lookup('/anime/S01E01.mkv'));
    }

    // -------------------------------------------------------------------------
    // extractAnimeName
    // -------------------------------------------------------------------------

    /**
     * @dataProvider filenameProvider
     */
    public function test_extracts_anime_name_from_various_release_naming_patterns(
        string $input,
        ?string $expectedTitle
    ): void {
        $result = $this->invokePrivate($this->makeProvider(), 'extractAnimeName', [$input]);

        $this->assertSame($expectedTitle, $result);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function filenameProvider(): array
    {
        return [
            'Sword Art Online S01E01 [GroupName].mkv' => [
                'Sword Art Online S01E01 [GroupName].mkv',
                'Sword Art Online',
            ],
            'Cowboy Bebop 01x24 [Coalgirls].avi' => [
                'Cowboy Bebop 01x24 [Coalgirls].avi',
                'Cowboy Bebop',
            ],
            'Your Name (2016) [1080p].mkv' => [
                'Your Name (2016) [1080p].mkv',
                'Your Name',
            ],
            'too short returns null' => [
                'S01E01.mkv',
                null,
            ],
            'dotted name: resolution strip before episode strip leaves no episode' => [
                'Neon.Genesis.Evangelion.01.720p.BluRay.x264.mkv',
                'Neon Genesis Evangelion',
            ],
            'year not treated as episode number' => [
                'Steins;Gate 2011',
                'Steins;Gate',
            ],
            'high episode number before resolution' => [
                'One.Piece.1000.1080p',
                'One Piece',
            ],
            'E13 episode format before resolution dimensions' => [
                'Fate.Zero.E13.1920x1080.mkv',
                'Fate Zero',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // parseSearchResponse
    // -------------------------------------------------------------------------

    public function test_parses_first_id_from_search_response(): void
    {
        $json = '{"data":[{"node":{"id":21,"title":"One Piece","main_picture":{"medium":"m.jpg","large":"l.jpg"}}},'
            . '{"node":{"id":999,"title":"Other"}}]}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'parseSearchResponse', [$decoded]);

        $this->assertSame(21, $result);
    }

    /**
     * @dataProvider emptySearchProvider
     */
    public function test_returns_null_for_empty_or_malformed_search(string $json): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'parseSearchResponse', [$decoded]);

        $this->assertNull($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptySearchProvider(): array
    {
        return [
            'empty data array'   => ['{"data":[]}'],
            'no data key'        => ['{"paging":{}}'],
            'node without id'    => ['{"data":[{"node":{"title":"x"}}]}'],
            'first not an array' => ['{"data":["nope"]}'],
        ];
    }

    // -------------------------------------------------------------------------
    // scoreSearchResults (B1: scored matching using alternative_titles)
    // -------------------------------------------------------------------------

    /**
     * When the query exactly matches an alternative title (not the main title),
     * that result must be selected even if it is not the first in MAL's ranking.
     */
    public function test_exact_match_on_alternative_title_is_found(): void
    {
        // Node 10087 has "Fate/Zero (TV)" as main title (prefix match, not exact).
        // Node 1210 has "Fate/Zero" as a synonym (exact match on synonym).
        // The query "Fate/Zero" should find the exact match on node 1210's synonym
        // even though node 10087 appears first and would also match (prefix).
        $json = '{"data":['
            . '{"node":{"id":10087,"title":"Fate/Zero (TV)","alternative_titles":{"synonyms":[]}}},'
            . '{"node":{"id":1210,"title":"Fate/stay night","alternative_titles":{"synonyms":["Fate/Zero","F/SN"]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Fate/Zero']);

        $this->assertSame(1210, $result);
    }

    /**
     * Exact match on the main title returns immediately without scoring others.
     */
    public function test_exact_match_on_main_title_returns_immediately(): void
    {
        $json = '{"data":['
            . '{"node":{"id":11,"title":"One Piece","alternative_titles":{"synonyms":[]}}},'
            . '{"node":{"id":21,"title":"One Punch Man","alternative_titles":{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'One Piece']);

        $this->assertSame(11, $result);
    }

    /**
     * Prefix matching: a longer matching prefix scores higher than a shorter one.
     * "Fate/stay night" (prefix 14 chars) should beat "Fate/stay" (prefix 9 chars).
     */
    public function test_prefix_match_longer_prefix_wins(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"Fate/stay night","alternative_titles":{"synonyms":[]}}},'
            . '{"node":{"id":2,"title":"Fate/stay","alternative_titles":{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Fate/stay night']);

        $this->assertSame(1, $result);
    }

    /**
     * Prefix scoring formula: 800 - |query_len - title_len|
     * Query "One Piece" (8 chars) vs "One Piece manga" (16 chars): 800 - 8 = 792
     * Query "One Piece" (8 chars) vs "One Piece" (8 chars): 800 - 0 = 800  (exact)
     * Query "One Piece" (8 chars) vs "One Piece TV" (13 chars): 800 - 5 = 795
     */
    public function test_prefix_scoring_formula(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"One Piece TV","alternative_titles":{"synonyms":[]}}},'
            . '{"node":{"id":2,"title":"One Piece anime","alternative_titles":{"synonyms":[]}}},'
            . '{"node":{"id":3,"title":"One Piece","alternative_titles":{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'One Piece']);

        // Exact match (id 3) returns immediately with highest confidence
        $this->assertSame(3, $result);
    }

    /**
     * Contains matching: the query appears somewhere inside the title.
     * Score = 600 - |query_len - title_len|
     * Query "Piece" (5 chars) in "One Piece anime adaptation" (23 chars): 600 - 18 = 582
     * Query "Piece" (5 chars) in "Dragon Piece" (11 chars): 600 - 6 = 594
     * The closer length match (id 2, 11 vs 23 chars) wins with 594 vs 582.
     */
    public function test_contains_match_favors_closer_length_match(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"One Piece anime adaptation","alternative_titles":{"synonyms":[]}}},'
            . '{"node":{"id":2,"title":"Dragon Piece","alternative_titles":{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Piece']);

        // id 2 wins: 600 - |5-11| = 594 vs id 1: 600 - |5-23| = 582
        $this->assertSame(2, $result);
    }

    /**
     * Alternative titles include English, Japanese, and synonyms.
     * The query may match the Japanese title when the main title is in English.
     */
    public function test_matches_japanese_alternative_title(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"Cowboy Bebop","alternative_titles":'
            . '{"en":"Cowboy Bebop","ja":"カウボーイビバップ","synonyms":["CB"]}}},'
            . '{"node":{"id":2,"title":"Something Else","alternative_titles":'
            . '{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'カウボーイビバップ']);

        $this->assertSame(1, $result);
    }

    /**
     * When no candidate has any title matching the query, null is returned.
     */
    public function test_no_match_returns_null(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"Unrelated Title","alternative_titles":{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'xyz no match']);

        $this->assertNull($result);
    }

    /**
     * Case-insensitive matching: "one piece" matches "One Piece".
     */
    public function test_exact_match_is_case_insensitive(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"One Piece","alternative_titles":{"synonyms":[]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'ONE PIECE']);

        $this->assertSame(1, $result);
    }

    /**
     * Empty data array returns null.
     */
    public function test_score_search_results_returns_null_for_empty_data(): void
    {
        $decoded = ['data' => []];

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Any Query']);

        $this->assertNull($result);
    }

    /**
     * Node without alternative_titles key is handled gracefully.
     */
    public function test_node_without_alternative_titles_handled_gracefully(): void
    {
        $json = '{"data":['
            . '{"node":{"id":1,"title":"Solo Leveling"}},'
            . '{"node":{"id":2,"title":"Other Anime","alternative_titles":{"synonyms":["Solo"]}}}'
            . ']}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Solo Leveling']);

        $this->assertSame(1, $result);
    }

    /**
     * scoreSearchResults handles non-array entries gracefully.
     * This exercises lines 830-838 (skips non-array entries).
     */
    public function test_score_search_results_skips_non_array_entries(): void
    {
        // Mixed array with non-array entries interspersed
        $decoded = [
            'data' => [
                'not an array', // line 830-831: not an array, skip
                ['node' => ['id' => 1, 'title' => 'Valid Entry', 'alternative_titles' => []]],
                123, // skip
                false, // skip
                ['node' => 'not an array'], // line 835-838: node not array, skip
            ],
        ];

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Valid Entry']);

        $this->assertSame(1, $result);
    }

    /**
     * scoreSearchResults skips entries without id field.
     * This exercises lines 840 (is_numeric check fails).
     */
    public function test_score_search_results_skips_entries_without_id(): void
    {
        $decoded = [
            'data' => [
                ['node' => ['title' => 'No ID', 'alternative_titles' => []]],
                ['node' => ['id' => 'not numeric', 'title' => 'Bad ID', 'alternative_titles' => []]],
                ['node' => ['id' => 5, 'title' => 'Good Entry', 'alternative_titles' => []]],
            ],
        ];

        $result = $this->invokePrivate($this->makeProvider(), 'scoreSearchResults', [$decoded, 'Good Entry']);

        $this->assertSame(5, $result);
    }

    // -------------------------------------------------------------------------
    // parseAnimeResponse
    // -------------------------------------------------------------------------

    public function test_parses_full_anime_details_response(): void
    {
        $json = self::fullDetailsJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$decoded]);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Cowboy Bebop', $result['title']);
        $this->assertSame('Cowboy Bebop', $result['en_title']);
        $this->assertSame('カウボーイビバップ', $result['ja_title']);
        $this->assertSame(['Cowboy Bebop (1998)'], $result['synonyms']);
        $this->assertSame(1998, $result['year']);
        $this->assertSame(['Action', 'Sci-Fi'], $result['genres']);
        $this->assertSame(8.75, $result['rating']);
        $this->assertSame(900000, $result['vote_count']);
        $this->assertSame('https://cdn.myanimelist.net/images/anime/4/19644l.jpg', $result['poster_url']);
        $this->assertSame(26, $result['episodes']);
        $this->assertSame('tv', $result['media_type']);
        $this->assertSame('finished_airing', $result['status']);
        $this->assertSame('Sunrise', $result['studio']);
        $this->assertSame(1440, $result['episode_length']);
    }

    public function test_parse_falls_back_to_medium_picture_when_no_large(): void
    {
        $json = '{"id":5,"title":"X","main_picture":{"medium":"m.jpg"}}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$decoded]);

        $this->assertSame('m.jpg', $result['poster_url']);
    }

    public function test_parse_returns_nulls_for_missing_optional_fields(): void
    {
        $json = '{"id":7,"title":"Bare Title"}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$decoded]);

        $this->assertSame(7, $result['id']);
        $this->assertSame('Bare Title', $result['title']);
        $this->assertNull($result['en_title']);
        $this->assertNull($result['ja_title']);
        $this->assertSame([], $result['synonyms']);
        $this->assertNull($result['synopsis']);
        $this->assertNull($result['year']);
        $this->assertSame([], $result['genres']);
        $this->assertNull($result['rating']);
        $this->assertNull($result['vote_count']);
        $this->assertNull($result['poster_url']);
        $this->assertNull($result['episodes']);
        $this->assertNull($result['media_type']);
        $this->assertNull($result['status']);
        $this->assertNull($result['studio']);
        $this->assertNull($result['episode_length']);
    }

    public function test_parse_returns_null_when_id_missing(): void
    {
        $json = '{"title":"No ID Here"}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        $result = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$decoded]);

        $this->assertNull($result);
    }

    public function test_parse_extracts_fanart_from_additional_pictures(): void
    {
        $json = '{"id":9,"title":"With Art","main_picture":{"large":"poster.jpg"},'
            . '"pictures":[{"medium":"fan-med.jpg","large":"fan-large.jpg"},{"large":"second.jpg"}]}';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$decoded]);

        // poster comes from main_picture; fanart from the first additional picture (large preferred)
        $this->assertSame('poster.jpg', $result['poster_url']);
        $this->assertSame('fan-large.jpg', $result['fanart_url']);
    }

    public function test_parse_fanart_falls_back_to_medium_then_null(): void
    {
        $withMedium = json_decode('{"id":1,"title":"X","pictures":[{"medium":"m.jpg"}]}', true);
        $noPictures = json_decode('{"id":2,"title":"Y"}', true);

        /** @var array<string, mixed> $a */
        $a = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$withMedium]);
        /** @var array<string, mixed> $b */
        $b = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$noPictures]);

        $this->assertSame('m.jpg', $a['fanart_url']);
        $this->assertNull($b['fanart_url']);
    }

    /**
     * extractFanartUrl returns null when first picture entry is not an array.
     * This exercises lines 1068-1069.
     */
    public function test_extract_fanart_url_returns_null_for_non_array_first_entry(): void
    {
        $raw = [
            'pictures' => [
                'not an array',
                ['large' => 'should-not-reach.jpg'],
            ],
        ];

        $result = $this->invokePrivate($this->makeProvider(), 'extractFanartUrl', [$raw]);

        $this->assertNull($result);
    }

    /**
     * extractFanartUrl returns null when pictures array is empty after first entry
     * that has no valid large/medium URL.
     * This exercises lines 1072-1079.
     */
    public function test_extract_fanart_url_returns_null_when_no_valid_url_in_first_entry(): void
    {
        $raw = [
            'pictures' => [
                ['width' => 100, 'height' => 200], // no large or medium
                ['large' => 'should-not-reach.jpg'],
            ],
        ];

        $result = $this->invokePrivate($this->makeProvider(), 'extractFanartUrl', [$raw]);

        $this->assertNull($result);
    }

    public function test_map_passes_through_fanart_url(): void
    {
        $parsed = [
            'id'             => 4,
            'title'          => 'Art Show',
            'en_title'       => null,
            'ja_title'       => null,
            'synonyms'       => [],
            'synopsis'       => null,
            'year'           => null,
            'genres'         => [],
            'rating'         => null,
            'vote_count'     => null,
            'poster_url'     => 'p.jpg',
            'fanart_url'     => 'backdrop.jpg',
            'episodes'       => 12,
            'media_type'     => 'tv',
            'status'         => 'finished_airing',
            'studio'         => null,
            'episode_length' => null,
        ];

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'mapToMetadataReturn', [$parsed]);

        $this->assertSame('backdrop.jpg', $result['fanart_url']);
    }

    // -------------------------------------------------------------------------
    // mapToMetadataReturn (full shape)
    // -------------------------------------------------------------------------

    public function test_maps_anime_response_to_metadata_return_shape(): void
    {
        $json = self::fullDetailsJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $parsed */
        $parsed = $this->invokePrivate($this->makeProvider(), 'parseAnimeResponse', [$decoded]);
        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'mapToMetadataReturn', [$parsed]);

        // All 16 keys present and in order
        $this->assertSame([
            'title',
            'original_name',
            'overview',
            'year',
            'genres',
            'rating',
            'vote_count',
            'poster_url',
            'fanart_url',
            'episodes',
            'type',
            'mal_id',
            'titles',
            'status',
            'runtime_ticks',
            'studio',
        ], array_keys($result));

        $this->assertSame('Cowboy Bebop', $result['title']);
        $this->assertSame('カウボーイビバップ', $result['original_name']);
        $this->assertSame('In the year 2071...', $result['overview']);
        $this->assertSame(1998, $result['year']);
        $this->assertSame(['Action', 'Sci-Fi'], $result['genres']);
        $this->assertSame(8.75, $result['rating']);
        $this->assertSame(900000, $result['vote_count']);
        $this->assertSame('https://cdn.myanimelist.net/images/anime/4/19644l.jpg', $result['poster_url']);
        $this->assertNull($result['fanart_url']);
        $this->assertSame(26, $result['episodes']);
        $this->assertSame('tv', $result['type']);
        $this->assertSame(1, $result['mal_id']);
        $this->assertContains('Cowboy Bebop', $result['titles']);
        $this->assertContains('カウボーイビバップ', $result['titles']);
        $this->assertContains('Cowboy Bebop (1998)', $result['titles']);
        $this->assertSame('Finished', $result['status']);
        $this->assertSame(1440 * 10_000_000, $result['runtime_ticks']);
        $this->assertSame('Sunrise', $result['studio']);
    }

    public function test_map_uses_title_as_original_name_when_no_japanese_title(): void
    {
        $parsed = [
            'id'             => 2,
            'title'          => 'Akira',
            'en_title'       => 'Akira',
            'ja_title'       => null,
            'synonyms'       => [],
            'synopsis'       => null,
            'year'           => 1988,
            'genres'         => [],
            'rating'         => null,
            'vote_count'     => null,
            'poster_url'     => null,
            'episodes'       => 1,
            'media_type'     => 'movie',
            'status'         => 'finished_airing',
            'studio'         => null,
            'episode_length' => null,
        ];

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'mapToMetadataReturn', [$parsed]);

        $this->assertSame('Akira', $result['original_name']);
        $this->assertSame('movie', $result['type']);
        $this->assertNull($result['runtime_ticks']);
        $this->assertSame(1, $result['episodes']);
    }

    public function test_map_nulls_zero_episode_count(): void
    {
        $parsed = [
            'id'             => 3,
            'title'          => 'Ongoing',
            'en_title'       => null,
            'ja_title'       => null,
            'synonyms'       => [],
            'synopsis'       => null,
            'year'           => null,
            'genres'         => [],
            'rating'         => null,
            'vote_count'     => null,
            'poster_url'     => null,
            'episodes'       => 0,
            'media_type'     => 'tv',
            'status'         => 'currently_airing',
            'studio'         => null,
            'episode_length' => null,
        ];

        /** @var array<string, mixed> $result */
        $result = $this->invokePrivate($this->makeProvider(), 'mapToMetadataReturn', [$parsed]);

        $this->assertNull($result['episodes']);
        $this->assertSame('Currently Airing', $result['status']);
    }

    // -------------------------------------------------------------------------
    // mapType
    // -------------------------------------------------------------------------

    /**
     * @dataProvider typeProvider
     */
    public function test_maps_media_type(?string $input, ?string $expected): void
    {
        $result = $this->invokePrivate($this->makeProvider(), 'mapType', [$input]);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function typeProvider(): array
    {
        return [
            'tv'        => ['tv', 'tv'],
            'movie'     => ['movie', 'movie'],
            'ova'       => ['ova', 'ova'],
            'special'   => ['special', 'special'],
            'ona'       => ['ona', 'ona'],
            'music'     => ['music', 'music'],
            'uppercase' => ['TV', 'tv'],
            'unknown passthrough lowercased' => ['unknown', 'unknown'],
            'null'      => [null, null],
            'empty'     => ['', null],
        ];
    }

    // -------------------------------------------------------------------------
    // mapStatus
    // -------------------------------------------------------------------------

    /**
     * @dataProvider statusProvider
     */
    public function test_maps_status_to_phlix_vocabulary(?string $input, ?string $expected): void
    {
        $result = $this->invokePrivate($this->makeProvider(), 'mapStatus', [$input]);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function statusProvider(): array
    {
        return [
            'finished'   => ['finished_airing', 'Finished'],
            'airing'     => ['currently_airing', 'Currently Airing'],
            'upcoming'   => ['not_yet_aired', 'Upcoming'],
            'uppercase'  => ['FINISHED_AIRING', 'Finished'],
            'unknown'    => ['something_else', null],
            'null'       => [null, null],
            'empty'      => ['', null],
        ];
    }

    // -------------------------------------------------------------------------
    // mapRuntimeTicks
    // -------------------------------------------------------------------------

    /**
     * @dataProvider runtimeProvider
     */
    public function test_maps_runtime_ticks(?int $seconds, ?int $expected): void
    {
        $result = $this->invokePrivate($this->makeProvider(), 'mapRuntimeTicks', [$seconds]);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function runtimeProvider(): array
    {
        return [
            '24 minutes' => [1440, 1440 * 10_000_000],
            'one second' => [1, 10_000_000],
            'zero'       => [0, null],
            'negative'   => [-5, null],
            'null'       => [null, null],
        ];
    }

    public function test_enforce_rate_limit_waits_out_recorded_back_off(): void
    {
        $provider = $this->makeProvider();

        // Record a short back-off via Reflection, then assert enforceRateLimit
        // sleeps it out and clears the deadline.
        $reflection = new \ReflectionClass($provider);
        $prop = $reflection->getProperty('retryAfterUntil');
        $prop->setAccessible(true);
        $deadline = microtime(true) + 0.15;
        $prop->setValue($provider, $deadline);

        $start = microtime(true);
        $this->invokePrivate($provider, 'enforceRateLimit', []);
        $elapsed = microtime(true) - $start;

        // It should have blocked until at least the deadline.
        $this->assertGreaterThanOrEqual(0.14, $elapsed);
        // And cleared the back-off so it is not re-applied next call.
        $this->assertSame(0.0, $prop->getValue($provider));
    }

    // -------------------------------------------------------------------------
    // setLogger
    // -------------------------------------------------------------------------

    public function test_set_logger_accepts_psr_logger(): void
    {
        $provider = $this->makeProvider();
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        // Should not throw
        $provider->setLogger($logger);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // onDisable
    // -------------------------------------------------------------------------

    public function test_on_disable_clears_cache(): void
    {
        $provider = $this->makeProvider();

        // Populate the cache via reflection
        $reflection = new \ReflectionClass($provider);
        $cacheProp = $reflection->getProperty('cache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($provider, ['test' => ['data' => 'value']]);

        $provider->onDisable();

        $this->assertSame([], $cacheProp->getValue($provider));
    }

    // -------------------------------------------------------------------------
    // scoreCandidateTitles (private helper)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider scoreCandidateTitlesProvider
     */
    public function test_score_candidate_titles(array $titles, string $queryLower, int $queryLen, int $expected): void
    {
        $result = $this->invokePrivate($this->makeProvider(), 'scoreCandidateTitles', [$titles, $queryLower, $queryLen]);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{array<int, string>, string, int, int}>
     */
    public static function scoreCandidateTitlesProvider(): array
    {
        return [
            'exact match returns -1' => [
                ['One Piece', 'ONe PIece'],
                'one piece',
                9,
                -1,
            ],
            'prefix match scores 800 minus length diff' => [
                ['One Piece TV'],
                'one piece',
                9,
                800 - 3, // title is 12, query is 9, diff is 3
            ],
            'prefix match longer title better' => [
                ['One Piece anime', 'One Piece TV'],
                'one piece',
                9,
                797, // 'One Piece TV' (12 chars): 800-3=797 beats 'One Piece anime' (13 chars): 800-4=796
            ],
            'contains match scores 600 minus length diff' => [
                ['Dragon One Piece'],
                'one piece',
                9,
                593, // title 16 vs query 9 = diff 7, so 600-7=593
            ],
            'no match returns 0' => [
                ['Totally Different'],
                'one piece',
                9,
                0,
            ],
            'empty titles returns 0' => [
                [],
                'one piece',
                9,
                0,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // fetchAnimeDetails (private, via probe)
    // -------------------------------------------------------------------------

    public function test_fetch_anime_details_returns_null_on_http_failure(): void
    {
        // Build provider with probe that returns null (HTTP failure)
        $provider = new class(['client_id' => 'test-id']) extends MyanimelistMetadataProvider {
            protected function httpGetJson(string $url): ?array
            {
                return null;
            }
        };

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('fetchAnimeDetails');
        $method->setAccessible(true);

        $result = $method->invoke($provider, 1);
        $this->assertNull($result);
    }

    public function test_fetch_anime_details_returns_parsed_response(): void
    {
        $expectedData = [
            'id' => 5,
            'title' => 'Test Anime',
            'main_picture' => ['large' => 'large.jpg', 'medium' => 'med.jpg'],
        ];

        $provider = new class(['client_id' => 'test-id'], $expectedData) extends MyanimelistMetadataProvider {
            private array $probeData;

            public function __construct(array $settings, array $probeData)
            {
                parent::__construct($settings);
                $this->probeData = $probeData;
            }

            protected function httpGetJson(string $url): ?array
            {
                return $this->probeData;
            }
        };

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('fetchAnimeDetails');
        $method->setAccessible(true);

        $result = $method->invoke($provider, 5);
        $this->assertIsArray($result);
        $this->assertSame(5, $result['id']);
        $this->assertSame('Test Anime', $result['title']);
    }

    // -------------------------------------------------------------------------
    // getHttpClient (private, via reflection)
    // -------------------------------------------------------------------------

    public function test_get_http_client_creates_client_on_first_call(): void
    {
        $provider = $this->makeProvider();

        $reflection = new \ReflectionClass($provider);
        $httpClientProp = $reflection->getProperty('httpClient');
        $httpClientProp->setAccessible(true);

        // Should be null initially
        $this->assertNull($httpClientProp->getValue($provider));

        $method = $reflection->getMethod('getHttpClient');
        $method->setAccessible(true);

        $client = $method->invoke($provider);

        // Should now be a Client instance
        $this->assertInstanceOf(\Workerman\Http\Client::class, $client);

        // Second call should return same instance (cached)
        $client2 = $method->invoke($provider);
        $this->assertSame($client, $client2);
    }

    // -------------------------------------------------------------------------
    // recordRetryAfterFromResponse (private)
    // -------------------------------------------------------------------------

    public function test_record_retry_after_from_response_parses_retry_after_header(): void
    {
        $provider = $this->makeProvider();

        $reflection = new \ReflectionClass($provider);
        $retryAfterProp = $reflection->getProperty('retryAfterUntil');
        $retryAfterProp->setAccessible(true);
        $retryAfterProp->setValue($provider, 0.0);

        $method = $reflection->getMethod('recordRetryAfterFromResponse');
        $method->setAccessible(true);

        // Create a mock response with Retry-After header
        $response = new \Workerman\Http\Response(200, ['Retry-After' => '5'], '');

        $method->invoke($provider, $response);

        $newValue = $retryAfterProp->getValue($provider);
        $this->assertGreaterThan(microtime(true) + 4.0, $newValue);
    }

    public function test_record_retry_after_uses_default_when_no_retry_after_header(): void
    {
        $provider = $this->makeProvider();

        $reflection = new \ReflectionClass($provider);
        $retryAfterProp = $reflection->getProperty('retryAfterUntil');
        $retryAfterProp->setAccessible(true);

        $before = microtime(true);
        $retryAfterProp->setValue($provider, 0.0);

        $method = $reflection->getMethod('recordRetryAfterFromResponse');
        $method->setAccessible(true);

        // Response with no Retry-After header
        $response = new \Workerman\Http\Response(200, [], '');

        $method->invoke($provider, $response);

        $newValue = $retryAfterProp->getValue($provider);
        // Should be set to approximately before + RATE_LIMIT_INTERVAL_SEC (1.0)
        $this->assertGreaterThanOrEqual($before + 0.9, $newValue);
    }

    public function test_record_retry_after_does_not_decrease_existing_backoff(): void
    {
        $provider = $this->makeProvider();

        $reflection = new \ReflectionClass($provider);
        $retryAfterProp = $reflection->getProperty('retryAfterUntil');
        $retryAfterProp->setAccessible(true);

        // Set a long backoff in the future
        $longBackoff = microtime(true) + 100.0;
        $retryAfterProp->setValue($provider, $longBackoff);

        $method = $reflection->getMethod('recordRetryAfterFromResponse');
        $method->setAccessible(true);

        // Response with short Retry-After
        $response = new \Workerman\Http\Response(200, ['Retry-After' => '1'], '');

        $method->invoke($provider, $response);

        // Should NOT have decreased the backoff
        $this->assertSame($longBackoff, $retryAfterProp->getValue($provider));
    }

    // -------------------------------------------------------------------------
    // lookup (public, comprehensive path testing)
    // -------------------------------------------------------------------------

    public function test_lookup_with_valid_filename_returns_metadata(): void
    {
        $detail = json_encode([
            'id' => 1,
            'title' => 'Cowboy Bebop',
            'main_picture' => ['large' => 'https://cdn.myanimelist.net/poster-l.jpg'],
            'alternative_titles' => ['en' => 'Cowboy Bebop', 'ja' => 'カウボーイビバップ'],
            'start_date' => '1998-04-03',
            'synopsis' => 'In the year 2071...',
            'mean' => 8.75,
            'num_scoring_users' => 900000,
            'genres' => [['id' => 1, 'name' => 'Action'], ['id' => 24, 'name' => 'Sci-Fi']],
            'num_episodes' => 26,
            'media_type' => 'tv',
            'status' => 'finished_airing',
            'studios' => [['id' => 14, 'name' => 'Sunrise']],
            'average_episode_duration' => 1440,
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        $searchResponse = new \Workerman\Http\Response(200, [], json_encode([
            'data' => [['node' => ['id' => 1, 'title' => 'Cowboy Bebop', 'alternative_titles' => []]]],
        ]) ?: '{}');
        $detailResponse = new \Workerman\Http\Response(200, [], $detail);

        // Build a provider that uses a fake HTTP client so no real network calls are made.
        $provider = new class(['client_id' => 'test-id'], $searchResponse, $detailResponse) extends MyanimelistMetadataProvider {
            private \Workerman\Http\Response $searchResponse;
            private \Workerman\Http\Response $detailResponse;

            public function __construct(array $settings, \Workerman\Http\Response $searchResponse, \Workerman\Http\Response $detailResponse)
            {
                parent::__construct($settings);
                $this->searchResponse = $searchResponse;
                $this->detailResponse = $detailResponse;
            }

            protected function httpGetJson(string $url): ?array
            {
                if (str_contains($url, '/anime?q=')) {
                    // Search endpoint
                    $body = $this->searchResponse->getBody()->getContents();
                    /** @var mixed $decoded */
                    $decoded = json_decode($body, true);
                    return is_array($decoded) ? $decoded : null;
                }
                // Detail endpoint
                $body = $this->detailResponse->getBody()->getContents();
                /** @var mixed $decoded */
                $decoded = json_decode($body, true);
                return is_array($decoded) ? $decoded : null;
            }
        };

        $result = $provider->lookup('/anime/Cowboy Bebop S01E01.mkv');

        $this->assertNotSame([], $result);
        $this->assertSame('Cowboy Bebop', $result['title']);
        $this->assertSame(1, $result['mal_id']);
    }

    public function test_lookup_when_search_returns_no_results(): void
    {
        $provider = new class(['client_id' => 'test-id']) extends MyanimelistMetadataProvider {
            protected function httpGetJson(string $url): ?array
            {
                return ['data' => []];
            }
        };

        $result = $provider->lookup('/anime/Nonexistent_Anime_xyz123.mkv');

        $this->assertSame([], $result);
    }

    /**
     * findIdByTitle with an empty or whitespace-only string returns null.
     * This exercises line 386-387.
     */
    public function test_find_id_by_title_returns_null_for_empty_string(): void
    {
        $provider = $this->makeProvider();

        $this->assertNull($provider->findIdByTitle(''));
        $this->assertNull($provider->findIdByTitle('   '));
        $this->assertNull($provider->findIdByTitle("\t\n"));
    }

    /**
     * lookup returns empty array when fetchAnimeDetails returns null
     * (anime found by ID but details request fails).
     * This exercises line 488-490.
     */
    public function test_lookup_returns_empty_when_fetch_anime_details_fails(): void
    {
        $provider = new class(['client_id' => 'test-id']) extends MyanimelistMetadataProvider {
            protected function httpGetJson(string $url): ?array
            {
                if (str_contains($url, '/anime?q=')) {
                    // Search returns an ID
                    return ['data' => [['node' => ['id' => 1, 'title' => 'Test', 'alternative_titles' => []]]]];
                }
                // Details fails
                return null;
            }
        };

        $result = $provider->lookup('/anime/Test Anime S01E01.mkv');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // adapter() (private, via reflection)
    // -------------------------------------------------------------------------

    public function test_adapter_returns_same_instance_on_repeated_calls(): void
    {
        $provider = $this->makeProvider();

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('adapter');
        $method->setAccessible(true);

        $adapter1 = $method->invoke($provider);
        $adapter2 = $method->invoke($provider);

        $this->assertSame($adapter1, $adapter2);
    }

    // -------------------------------------------------------------------------
    // Constructor - clock property
    // -------------------------------------------------------------------------

    public function test_constructor_sets_clock_closure(): void
    {
        $provider = $this->makeProvider();

        $reflection = new \ReflectionClass($provider);
        $clockProp = $reflection->getProperty('clock');
        $clockProp->setAccessible(true);

        $clock = $clockProp->getValue($provider);
        $this->assertIsCallable($clock);

        $before = microtime(true);
        $time = $clock();
        $after = microtime(true);

        $this->assertGreaterThanOrEqual($before, $time);
        $this->assertLessThanOrEqual($after, $time);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A representative full MAL anime-details JSON payload (Cowboy Bebop, id 1).
     */
    private static function fullDetailsJson(): string
    {
        return json_encode([
            'id'                       => 1,
            'title'                    => 'Cowboy Bebop',
            'main_picture'             => [
                'medium' => 'https://cdn.myanimelist.net/images/anime/4/19644.jpg',
                'large'  => 'https://cdn.myanimelist.net/images/anime/4/19644l.jpg',
            ],
            'alternative_titles'       => [
                'synonyms' => ['Cowboy Bebop (1998)'],
                'en'       => 'Cowboy Bebop',
                'ja'       => 'カウボーイビバップ',
            ],
            'start_date'               => '1998-04-03',
            'synopsis'                 => 'In the year 2071...',
            'mean'                     => 8.75,
            'num_scoring_users'        => 900000,
            'genres'                   => [
                ['id' => 1, 'name' => 'Action'],
                ['id' => 24, 'name' => 'Sci-Fi'],
            ],
            'num_episodes'             => 26,
            'media_type'               => 'tv',
            'status'                   => 'finished_airing',
            'studios'                  => [
                ['id' => 14, 'name' => 'Sunrise'],
            ],
            'average_episode_duration' => 1440,
            'rating'                   => 'r',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
