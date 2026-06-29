<?php

declare(strict_types=1);

namespace Phlix\Myanimelist\Tests\Unit;

use Phlix\Myanimelist\MyanimelistMetadataProvider;
use PHPUnit\Framework\TestCase;

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
        return new MyanimelistMetadataProvider(['client_id' => 'test-id']);
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
            'dotted name keeps trailing episode when resolution follows' => [
                'Neon.Genesis.Evangelion.01.720p.BluRay.x264.mkv',
                'Neon Genesis Evangelion 01',
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
    // B6: onEnable fast-fail on rejected Client ID (401)
    // -------------------------------------------------------------------------

    /**
     * Builds a provider whose credential-probe result we control directly so
     * onEnable can be tested without network access.
     *
     * The child shadows the parent's private httpGetJson so the test can return
     * whatever status code the test case needs.
     */
    private function makeProviderWithControlledProbe(): MyanimelistMetadataProvider
    {
        return new class(['client_id' => 'test-id']) extends MyanimelistMetadataProvider {
            private ?array $probeResult = null;

            public function setProbeResult(?array $result): void
            {
                $this->probeResult = $result;
            }

            protected function httpGetJson(string $url): ?array
            {
                return $this->probeResult;
            }
        };
    }

    /**
     * onEnable must throw RuntimeException when the credential probe returns
     * null (401 Unauthorized / rejected Client ID).
     *
     * This is the core B6 fast-fail contract: a 401 from the probe means the
     * Client ID is bad, not that MAL is unreachable.
     */
    public function test_on_enable_throws_when_credential_probe_returns_null_on_401(): void
    {
        $provider = $this->makeProviderWithControlledProbe();
        // B5: handleResponse returns null for any non-2xx (including 401).
        $provider->setProbeResult(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected (401 Unauthorized)');

        $provider->onEnable($this->createMock(\Psr\Container\ContainerInterface::class));
    }

    /**
     * onEnable must NOT throw when the credential probe returns 200 with valid
     * MAL search data (even if the data array is empty).
     */
    public function test_on_enable_does_not_throw_when_credential_probe_returns_200(): void
    {
        $provider = $this->makeProviderWithControlledProbe();
        // A successful 200 probe response (empty data still means MAL is reachable).
        $provider->setProbeResult(['data' => []]);

        // Should not throw — MAL is reachable and Client ID is accepted.
        $provider->onEnable($this->createMock(\Psr\Container\ContainerInterface::class));

        // If we reach here without exception, the test passes.
        $this->assertTrue(true);
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
