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
                return null;
            }
        };

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
}
