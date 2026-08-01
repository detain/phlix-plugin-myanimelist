<?php

/**
 * Unit Myanimelistmetadataprovideradaptertest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Myanimelist\Tests\Unit;

use Phlix\Media\Metadata\MetadataProviderInterface;
use Phlix\Myanimelist\MyanimelistMetadataProvider;
use Phlix\Myanimelist\MyanimelistMetadataProviderAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the host-interface bridge (Step Q1, Option A — bridge pattern).
 *
 * Asserts that:
 *  - the adapter satisfies the host MetadataProviderInterface contract and
 *    advertises the correct source name / provider aliases;
 *  - onEnable() resolves a MetadataManager from the host container and calls
 *    registerProvider() on it — i.e. the plugin's metadata is now wired into
 *    the host's consumption path (the gap Q1 was raised to close).
 *
 * Private MAL API responses are driven through a controlled probe subclass so
 * these tests need no network I/O.
 */
final class MyanimelistMetadataProviderAdapterTest extends TestCase
{
    /**
     * Build a provider with an in-object probe that returns controlled MAL
     * API responses without touching the network.
     *
     * @param array<string, array<string, mixed>|null> $probe Map of URL suffix
     *        => decoded JSON (or null for failure). The probe is checked first
     *        in httpGetJson() before the real cache/path logic runs.
     */
    private function makeProviderWithProbe(array $probe): MyanimelistMetadataProvider
    {
        // We need to inject the probe without touching private properties of
        // the parent. We use a named subclass so the class name is predictable.
        $provider = new class(['client_id' => 'test-id'], $probe) extends MyanimelistMetadataProvider {
            /** @var array<string, array<string, mixed>|null> */
            private array $probe;

            public function __construct(array $settings, array $probe)
            {
                parent::__construct($settings);
                $this->probe = $probe;
            }

            protected function httpGetJson(string $url): ?array
            {
                foreach ($this->probe as $prefix => $response) {
                    if (str_starts_with($url, $prefix)) {
                        return $response;
                    }
                }
                // No probe match — delegate to parent (uses injected fake HTTP client)
                return parent::httpGetJson($url);
            }
        };

        // Inject a fake HTTP client that matches probe URLs and returns canned responses
        // (same pattern as MyanimelistTransportTest uses). Also noop the timer so
        // enforceRateLimit() doesn't call Timer::sleep() which requires Workerman.
        $fakeClient = new class ($probe) extends \Workerman\Http\Client {
            /** @var array<string, array<string, mixed>|null> */
            private array $probe;

            /** @param array<string, array<string, mixed>|null> $probe */
            public function __construct(array $probe)
            {
                // Deliberately skip parent::__construct() — real ctor builds ConnectionPool.
                $this->probe = $probe;
            }

            public function request(string $url, array $options = []): mixed
            {
                foreach ($this->probe as $prefix => $response) {
                    if (str_starts_with($url, $prefix)) {
                        if ($response === null) {
                            if (isset($options['error'])) {
                                ($options['error'])(new \RuntimeException('probe error for ' . $url));
                            }
                            return null;
                        }
                        // Wrap probe data in a proper Response object with status 200
                        $body = json_encode($response) ?: '{}';
                        $statusCode = 200;
                        $headers = ['Content-Type' => 'application/json'];
                        $resp = new \Workerman\Http\Response($statusCode, $headers, $body);
                        if (isset($options['success'])) {
                            ($options['success'])($resp);
                        }
                        return null;
                    }
                }
                if (isset($options['error'])) {
                    ($options['error'])(new \RuntimeException('no probe for ' . $url));
                }
                return null;
            }
        };

        // Inject the fake client via reflection. Use the parent class name for the
        // private property since private properties are not inherited into the
        // anonymous subclass's reflection context.
        $httpClient = new \ReflectionProperty(MyanimelistMetadataProvider::class, 'httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        // Noop the rate-limit sleep and cooperative-wait tick
        $timerSleep = new \ReflectionProperty(MyanimelistMetadataProvider::class, 'timerSleep');
        $timerSleep->setAccessible(true);
        $timerSleep->setValue($provider, static function (float $s): void {
        });

        $waitTick = new \ReflectionProperty(MyanimelistMetadataProvider::class, 'waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        return $provider;
    }

    public function test_adapter_implements_host_metadata_provider_interface(): void
    {
        $provider = $this->makeProviderWithProbe([]);
        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        $this->assertInstanceOf(MetadataProviderInterface::class, $adapter);
    }

    public function test_get_source_name_returns_myanimelist(): void
    {
        $provider = $this->makeProviderWithProbe([]);
        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        $this->assertSame('myanimelist', $adapter->getSourceName());
        $this->assertSame(MyanimelistMetadataProviderAdapter::SOURCE_NAME, $adapter->getSourceName());
    }

    public function test_get_providers_returns_myanimelist_alias(): void
    {
        $provider = $this->makeProviderWithProbe([]);
        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        $this->assertSame(['myanimelist'], $adapter->getProviders());
    }

    public function test_search_with_no_results_returns_empty_array(): void
    {
        // Probe returns null (no match) for any search URL.
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime?q=' => null,
        ]);
        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        $this->assertSame([], $adapter->search('Nobody'));
    }

    public function test_get_details_with_invalid_external_id_returns_empty(): void
    {
        $provider = $this->makeProviderWithProbe([]);
        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        // Non-numeric / zero / empty ids must short-circuit before any network I/O.
        $this->assertSame([], $adapter->getDetails('not-a-number'));
        $this->assertSame([], $adapter->getDetails('0'));
        $this->assertSame([], $adapter->getDetails(''));
        $this->assertSame([], $adapter->getDetails('  '));
    }

    public function test_get_images_with_invalid_external_id_returns_empty(): void
    {
        $provider = $this->makeProviderWithProbe([]);
        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        $this->assertSame([], $adapter->getImages('not-a-number'));
    }

    /**
     * The core Q1 assertion: enabling the plugin must register an adapter that
     * implements the host contract with the host MetadataManager (resolved from
     * the host container) under the 'myanimelist' name for anime types.
     *
     * The "MetadataManager" here is a runtime stand-in object exposing the same
     * registerProvider(string, MetadataProviderInterface, array) signature; the
     * provider resolves it from a mocked PSR-11 container exactly as it would the
     * real one.
     */
    public function test_on_enable_registers_adapter_with_metadata_manager(): void
    {
        $managerClass = 'Phlix\\Media\\Metadata\\MetadataManager';

        $manager = new class {
            /** @var array{0: string, 1: object, 2: array<int, string>}|null */
            public ?array $registered = null;

            public function registerProvider(string $name, object $provider, array $supportedTypes = []): void
            {
                $this->registered = [$name, $provider, $supportedTypes];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => $id === $managerClass);
        $container->method('get')
            ->willReturnCallback(static function (string $id) use ($managerClass, $manager) {
                if ($id === $managerClass) {
                    return $manager;
                }
                throw new \RuntimeException('unexpected container id: ' . $id);
            });

        // Drive ONLY the registration step by invoking registerWithMetadataManager()
        // via reflection — this is the exact call onEnable() makes after the
        // connectivity check passes.
        $provider = $this->makeProviderWithProbe([
            '/anime?q=test&limit=1&fields=id' => ['data' => [['node' => ['id' => 1]]]],
        ]);
        $ref = new \ReflectionMethod($provider, 'registerWithMetadataManager');
        $ref->setAccessible(true);
        $ref->invoke($provider, $container);

        $this->assertNotNull($manager->registered, 'registerProvider() was not called');
        [$name, $registeredProvider, $types] = $manager->registered;

        $this->assertSame('myanimelist', $name);
        $this->assertInstanceOf(MyanimelistMetadataProviderAdapter::class, $registeredProvider);
        $this->assertInstanceOf(MetadataProviderInterface::class, $registeredProvider);
        $this->assertSame(['series', 'movie'], $types);
    }

    public function test_on_enable_registration_is_noop_when_manager_absent(): void
    {
        // Container without the MetadataManager entry: registration must be a
        // graceful no-op (plugin still usable, no throw).
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->expects($this->never())->method('get');

        $provider = $this->makeProviderWithProbe([]);
        $ref = new \ReflectionMethod($provider, 'registerWithMetadataManager');
        $ref->setAccessible(true);

        // Must not throw.
        $ref->invoke($provider, $container);
        $this->addToAssertionCount(1);
    }

    public function test_public_bridge_find_id_by_title(): void
    {
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime?q=Trigun&limit=10' => [
                'data' => [['node' => ['id' => 42, 'title' => 'Trigun']]],
            ],
        ]);

        $result = $provider->findIdByTitle('Trigun');
        $this->assertSame(42, $result);
    }

    public function test_public_bridge_fetch_anime_metadata_with_invalid_id_returns_empty(): void
    {
        $provider = $this->makeProviderWithProbe([]);

        $this->assertSame([], $provider->fetchAnimeMetadata(0));
        $this->assertSame([], $provider->fetchAnimeMetadata(-1));
    }

    // -------------------------------------------------------------------------
    // stringOr (private static helper)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider stringOrProvider
     */
    public function test_string_or(mixed $value, string $fallback, string $expected): void
    {
        $reflection = new \ReflectionClass(MyanimelistMetadataProviderAdapter::class);
        $method = $reflection->getMethod('stringOr');
        $method->setAccessible(true);

        $result = $method->invoke(null, $value, $fallback);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{mixed, string, string}>
     */
    public static function stringOrProvider(): array
    {
        return [
            'non-empty string returns value' => ['hello', 'fallback', 'hello'],
            'empty string returns fallback' => ['', 'fallback', 'fallback'],
            'null returns fallback' => [null, 'fallback', 'fallback'],
            'zero returns fallback' => [0, 'fallback', 'fallback'],
            'false returns fallback' => [false, 'fallback', 'fallback'],
            'int returns fallback' => [42, 'fallback', 'fallback'],
            'whitespace only returns fallback' => ['   ', 'fallback', 'fallback'],
        ];
    }

    // -------------------------------------------------------------------------
    // parseMalId (private static helper)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider parseMalIdProvider
     */
    public function test_parse_mal_id(string $input, ?int $expected): void
    {
        $reflection = new \ReflectionClass(MyanimelistMetadataProviderAdapter::class);
        $method = $reflection->getMethod('parseMalId');
        $method->setAccessible(true);

        $result = $method->invoke(null, $input);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function parseMalIdProvider(): array
    {
        return [
            'valid positive id' => ['1', 1],
            'larger valid id' => ['12345', 12345],
            'zero returns null' => ['0', null],
            'negative returns null' => ['-1', null],
            'empty string returns null' => ['', null],
            'whitespace returns null' => ['  ', null],
            'non-numeric returns null' => ['abc', null],
            'mixed alphanumeric returns null' => ['123abc', null],
            'float string returns null' => ['1.5', null],
            'id with leading zeros' => ['007', 7],
        ];
    }

    // -------------------------------------------------------------------------
    // search edge cases
    // -------------------------------------------------------------------------

    public function test_search_when_fetch_anime_metadata_returns_empty_details(): void
    {
        // Provider returns MAL ID but no details
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime?q=' => [
                'data' => [['node' => ['id' => 42, 'title' => 'Trigun', 'alternative_titles' => []]]],
            ],
            '/v2/anime/42' => null, // fetch fails
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);
        $result = $adapter->search('Trigun');

        // Should still return a usable stub with just id and title
        $this->assertCount(1, $result);
        $this->assertSame('42', $result[0]['id']);
        $this->assertSame('Trigun', $result[0]['title']);
        $this->assertArrayNotHasKey('overview', $result[0]);
        $this->assertArrayNotHasKey('poster_path', $result[0]);
    }

    public function test_search_with_details_having_no_synopsis_or_poster(): void
    {
        // Details exist but have no overview or poster_path
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime?q=' => [
                'data' => [['node' => ['id' => 5, 'title' => 'Minimal Anime', 'alternative_titles' => []]]],
            ],
            '/v2/anime/5' => [
                'id' => 5,
                'title' => 'Minimal Anime',
                'main_picture' => null,
                'synopsis' => null,
            ],
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);
        $result = $adapter->search('Minimal Anime');

        $this->assertCount(1, $result);
        $this->assertSame('5', $result[0]['id']);
        $this->assertSame('Minimal Anime', $result[0]['title']);
        $this->assertArrayNotHasKey('overview', $result[0]);
        $this->assertArrayNotHasKey('poster_path', $result[0]);
    }

    // -------------------------------------------------------------------------
    // getImages edge cases
    // -------------------------------------------------------------------------

    public function test_get_images_returns_empty_when_no_poster_or_fanart(): void
    {
        $provider = $this->makeProviderWithProbe([
            '/v2/anime/10' => [
                'id' => 10,
                'title' => 'No Images Anime',
                'main_picture' => null,
                'poster_url' => null,
                'fanart_url' => null,
            ],
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);
        $result = $adapter->getImages('10');

        $this->assertSame([], $result);
    }

    public function test_get_images_returns_poster_only_when_no_fanart(): void
    {
        // Probe must return raw MAL API format (main_picture, pictures) that
        // fetchAnimeDetails parses via parseAnimeResponse, THEN mapToMetadataReturn
        // transforms to poster_url/fanart_url.
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime/11' => [
                'id' => 11,
                'title' => 'Poster Only',
                'main_picture' => ['large' => 'https://example.com/poster.jpg'],
                'pictures' => [],
            ],
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);
        $result = $adapter->getImages('11');

        $this->assertArrayHasKey('poster', $result);
        $this->assertArrayNotHasKey('fanart', $result);
        $this->assertSame('https://example.com/poster.jpg', $result['poster'][0]['url']);
    }

    public function test_get_images_returns_fanart_only_when_no_poster(): void
    {
        // Probe must return raw MAL API format - fanart comes from pictures[0].large
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime/12' => [
                'id' => 12,
                'title' => 'Fanart Only',
                'main_picture' => null,
                'pictures' => [['large' => 'https://example.com/fanart.jpg', 'medium' => 'https://example.com/fanart-med.jpg']],
            ],
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);
        $result = $adapter->getImages('12');

        $this->assertArrayNotHasKey('poster', $result);
        $this->assertArrayHasKey('fanart', $result);
        $this->assertSame('https://example.com/fanart.jpg', $result['fanart'][0]['url']);
    }

    // -------------------------------------------------------------------------
    // getDetails edge cases
    // -------------------------------------------------------------------------

    public function test_get_details_returns_metadata_when_fetch_succeeds(): void
    {
        // Probe must return raw MAL API format that fetchAnimeDetails parses
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime/99' => [
                'id' => 99,
                'title' => 'Detailed Anime',
                'main_picture' => ['large' => 'https://example.com/poster.jpg'],
                'alternative_titles' => ['en' => 'Detailed Anime EN'],
                'start_date' => '2020-04-03',
                'synopsis' => 'A detailed synopsis.',
                'mean' => 8.5,
                'num_scoring_users' => 50000,
                'genres' => [['id' => 1, 'name' => 'Action']],
                'num_episodes' => 12,
                'media_type' => 'tv',
                'status' => 'finished_airing',
                'studios' => [['id' => 1, 'name' => 'Studio A']],
                'average_episode_duration' => 1200,
            ],
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);
        $result = $adapter->getDetails('99');

        $this->assertNotSame([], $result);
        $this->assertSame('Detailed Anime', $result['title']);
        $this->assertSame(8.5, $result['rating']);
    }

    // -------------------------------------------------------------------------
    // onDisable via provider
    // -------------------------------------------------------------------------

    public function test_adapter_works_after_provider_on_disable(): void
    {
        $provider = $this->makeProviderWithProbe([
            'https://api.myanimelist.net/v2/anime/1' => [
                'id' => 1,
                'title' => 'Test',
                'main_picture' => ['large' => 'p.jpg'],
                'pictures' => [],
            ],
        ]);

        $adapter = new MyanimelistMetadataProviderAdapter($provider);

        // Clear cache via onDisable
        $provider->onDisable();

        // Adapter should still work (it fetches fresh data)
        $result = $adapter->getImages('1');
        $this->assertArrayHasKey('poster', $result);
    }
}
