<?php

/**
 * Unit MyanimelistTransportTest.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Myanimelist\Tests\Unit;

use Phlix\Myanimelist\MyanimelistMetadataProvider;
use PHPUnit\Framework\TestCase;
use Workerman\Http\Client;
use Workerman\Http\Response;

/**
 * Consequence tests for the HTTP transport path.
 *
 * These are the tests that were MISSING while the transport was broken: the
 * suite was green because every prior test stubbed `httpGetJson()`, so the
 * `Fiber::start()` misuse (which returned the first-suspend value, never the
 * closure's return, so the decoded body never reached the caller) was
 * invisible. Here we drive the REAL `httpGetJson()` / `requestAndWait()` with a
 * fake async client and assert the decoded JSON body actually reaches the
 * caller and populates `getDetails()`.
 */
final class MyanimelistTransportTest extends TestCase
{
    /**
     * Build a provider whose HTTP client is a fake that fires the `success`
     * callback synchronously with a canned {@see Response} chosen by matching
     * a substring of the request URL. Rate-limit + wait sleeps are no-ops so
     * the test is deterministic and instant.
     *
     * @param array<string, Response> $responses URL-substring => Response.
     */
    private function makeProvider(array $responses): MyanimelistMetadataProvider
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $fakeClient = new class ($responses) extends Client {
            /** @var array<string, Response> */
            private array $responses;

            /** @param array<string, Response> $responses */
            public function __construct(array $responses)
            {
                // Deliberately skip parent::__construct(): the real ctor builds
                // a ConnectionPool + coroutine Channel that need a running loop.
                $this->responses = $responses;
            }

            public function request(string $url, array $options = []): mixed
            {
                foreach ($this->responses as $needle => $response) {
                    if (str_contains($url, $needle)) {
                        ($options['success'])($response);
                        return null;
                    }
                }
                if (isset($options['error'])) {
                    ($options['error'])(new \RuntimeException('no stub for ' . $url));
                }
                return null;
            }
        };

        $ref = new \ReflectionClass($provider);

        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        // No-op the rate-limit sleep and the cooperative-wait tick so the test
        // neither blocks nor depends on an event loop (the fake client resolves
        // the callback synchronously, so `done` is already true).
        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        return $provider;
    }

    /**
     * Invoke a protected/private method via reflection.
     *
     * @param array<int, mixed> $args
     */
    private function invoke(MyanimelistMetadataProvider $provider, string $method, array $args): mixed
    {
        $m = new \ReflectionMethod($provider, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($provider, $args);
    }

    /**
     * THE regression guard: with a mocked client returning a known 200 JSON
     * body, httpGetJson() must RETURN that decoded body — not null, and not the
     * value passed to the (defunct) fiber suspend. Under the old
     * `return $fiber->start();` code this array is never produced on the first
     * call, so this assertion is RED.
     */
    public function test_http_get_json_returns_decoded_body_on_2xx(): void
    {
        $body = '{"id":1,"title":"Cowboy Bebop","mean":8.75}';
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(200, [], $body),
        ]);

        $result = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1']);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('Cowboy Bebop', $result['title']);
        $this->assertSame(8.75, $result['mean']);
    }

    /**
     * The decoded body is cached under md5(url) so a repeat call is served from
     * memory (and, critically, the FIRST call already returned it).
     */
    public function test_http_get_json_caches_and_serves_repeat_call(): void
    {
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(200, [], '{"id":42,"title":"Trigun"}'),
        ]);

        $first = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/42']);
        $second = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/42']);

        $this->assertIsArray($first);
        $this->assertSame(42, $first['id']);
        $this->assertSame($first, $second);
    }

    /**
     * A non-2xx error body must never be decoded-and-returned as if it were
     * real data.
     */
    public function test_http_get_json_returns_null_on_non_2xx(): void
    {
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(404, [], '{"error":"not_found"}'),
        ]);

        $result = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/999999']);

        $this->assertNull($result);
    }

    /**
     * A malformed (non-JSON) 2xx body yields null rather than a bogus value.
     */
    public function test_http_get_json_returns_null_on_invalid_json(): void
    {
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(200, [], 'not json at all'),
        ]);

        $result = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1']);

        $this->assertNull($result);
    }

    /**
     * End-to-end through the public MetadataSourceInterface: getDetails() with a
     * real MAL detail payload returns POPULATED, mapped fields. This is the
     * caller-visible consequence of the transport actually yielding data.
     */
    public function test_get_details_returns_populated_fields_end_to_end(): void
    {
        $detail = json_encode([
            'id'                       => 1,
            'title'                    => 'Cowboy Bebop',
            'main_picture'             => ['large' => 'https://cdn.myanimelist.net/poster-l.jpg'],
            'alternative_titles'       => ['en' => 'Cowboy Bebop', 'ja' => 'カウボーイビバップ'],
            'start_date'               => '1998-04-03',
            'synopsis'                 => 'In the year 2071...',
            'mean'                     => 8.75,
            'num_scoring_users'        => 900000,
            'genres'                   => [['id' => 1, 'name' => 'Action'], ['id' => 24, 'name' => 'Sci-Fi']],
            'num_episodes'             => 26,
            'media_type'               => 'tv',
            'status'                   => 'finished_airing',
            'studios'                  => [['id' => 14, 'name' => 'Sunrise']],
            'average_episode_duration' => 1440,
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        $provider = $this->makeProvider([
            '/v2/anime/1?' => new Response(200, [], $detail),
        ]);

        $result = $provider->getDetails('1');

        $this->assertNotSame([], $result, 'getDetails() returned empty — transport did not yield data');
        $this->assertSame('Cowboy Bebop', $result['title']);
        $this->assertSame('In the year 2071...', $result['overview']);
        $this->assertSame(1998, $result['year']);
        $this->assertSame(8.75, $result['rating']);
        $this->assertSame(900000, $result['vote_count']);
        $this->assertSame(['Action', 'Sci-Fi'], $result['genres']);
        $this->assertSame(26, $result['episodes']);
        $this->assertSame('Sunrise', $result['studio']);
        $this->assertSame('https://cdn.myanimelist.net/poster-l.jpg', $result['poster_url']);
        $this->assertSame(1, $result['mal_id']);
    }

    /**
     * End-to-end through search(): resolve a title to a MAL id (search endpoint)
     * then enrich it (details endpoint). Exercises two sequential transport
     * calls in one flow.
     */
    public function test_search_resolves_and_enriches_end_to_end(): void
    {
        $search = '{"data":[{"node":{"id":1,"title":"Cowboy Bebop","alternative_titles":{"synonyms":[]}}}]}';
        $detail = json_encode([
            'id'           => 1,
            'title'        => 'Cowboy Bebop',
            'main_picture' => ['large' => 'https://cdn.myanimelist.net/poster-l.jpg'],
            'synopsis'     => 'In the year 2071...',
            'media_type'   => 'tv',
        ]) ?: '{}';

        $provider = $this->makeProvider([
            '/v2/anime?q=' => new Response(200, [], $search),
            '/v2/anime/1?' => new Response(200, [], $detail),
        ]);

        $results = $provider->search('Cowboy Bebop');

        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['id']);
        $this->assertSame('Cowboy Bebop', $results[0]['title']);
        $this->assertArrayHasKey('overview', $results[0]);
        $this->assertSame('In the year 2071...', $results[0]['overview']);
        $this->assertArrayHasKey('poster_path', $results[0]);
        $this->assertSame('https://cdn.myanimelist.net/poster-l.jpg', $results[0]['poster_path']);
    }

    /**
     * A 429 response records a Retry-After back-off and returns null (no data
     * from the throttle body).
     */
    public function test_http_get_json_records_back_off_on_429(): void
    {
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(429, ['Retry-After' => '2'], '{}'),
        ]);

        $result = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1']);
        $this->assertNull($result);

        $ref = new \ReflectionProperty($provider, 'retryAfterUntil');
        $ref->setAccessible(true);
        $this->assertGreaterThan(0.0, $ref->getValue($provider), 'Retry-After back-off was not recorded');
    }

    /**
     * The error callback receives a non-Throwable and creates a RuntimeException.
     * This exercises lines 586-590.
     */
    public function test_request_and_wait_error_callback_with_non_throwable(): void
    {
        $fakeClient = new class extends Client {
            public function request(string $url, array $options = []): mixed
            {
                if (isset($options['error'])) {
                    // Pass a non-Throwable value - should be wrapped in RuntimeException
                    ($options['error'])('connection refused');
                }
                return null;
            }
        };

        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $ref = new \ReflectionClass($provider);
        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $result = $this->invoke($provider, 'requestAndWait', ['https://api.myanimelist.net/v2/anime/1']);

        // Should return null because error was recorded
        $this->assertNull($result);
    }

    /**
     * The HTTP request throws an exception - should be caught and return null.
     * This exercises lines 593-598.
     */
    public function test_request_and_wait_returns_null_on_request_exception(): void
    {
        $fakeClient = new class extends Client {
            public function request(string $url, array $options = []): mixed
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $ref = new \ReflectionClass($provider);
        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $result = $this->invoke($provider, 'requestAndWait', ['https://api.myanimelist.net/v2/anime/1']);

        $this->assertNull($result);
    }

    /**
     * The cooperative wait loop runs until maxWait is reached when callback
     * never fires. This exercises lines 604-609.
     * Note: With a no-op wait tick the loop runs fast but still exercises the code path.
     */
    public function test_request_and_wait_cooperative_wait_loop_timeout(): void
    {
        // A client that NEVER fires any callback - simulates a lost response
        $fakeClient = new class extends Client {
            public function request(string $url, array $options = []): mixed
            {
                // Simply do nothing - no success, no error callback
                return null;
            }
        };

        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $ref = new \ReflectionClass($provider);
        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $result = $this->invoke($provider, 'requestAndWait', ['https://api.myanimelist.net/v2/anime/1']);

        // Should return null after timeout
        $this->assertNull($result);
    }

    /**
     * C32 anti-phantom law: request options must NEVER carry a `verify_ssl`
     * key, with the toggle either way.
     *
     * The old code wrote `$options['verify_ssl'] = false` when
     * `use_ssl_verification` was false — a key the vendored
     * workerman/http-client never reads anywhere (see
     * {@see test_vendored_transport_consumes_context_and_never_verify_ssl}),
     * making the advertised control inert. The toggle is now honored at the
     * client/pool layer via the TLS stream context, so the phantom request
     * option is gone on BOTH paths; this pins that it can never return.
     */
    public function test_request_and_wait_never_carries_phantom_verify_ssl_option(): void
    {
        foreach ([[], ['use_ssl_verification' => false], ['use_ssl_verification' => true]] as $extra) {
            $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id'] + $extra);

            $ref = new \ReflectionClass($provider);
            $httpClient = $ref->getProperty('httpClient');
            $httpClient->setAccessible(true);

            // Track the options that get passed to request()
            $fakeClient = new class extends Client {
                private array $capturedOptions = [];

                public function request(string $url, array $options = []): mixed
                {
                    $this->capturedOptions = $options;
                    if (isset($options['success'])) {
                        ($options['success'])(new Response(200, [], '{}'));
                    }
                    return null;
                }

                public function getCapturedOptions(): array
                {
                    return $this->capturedOptions;
                }
            };

            $httpClient->setValue($provider, $fakeClient);

            $noopFloat = $ref->getProperty('timerSleep');
            $noopFloat->setAccessible(true);
            $noopFloat->setValue($provider, static function (float $s): void {
            });

            $waitTick = $ref->getProperty('waitTick');
            $waitTick->setAccessible(true);
            $waitTick->setValue($provider, static function (): void {
            });

            $this->invoke($provider, 'requestAndWait', ['https://api.myanimelist.net/v2/anime/1']);

            $captured = $fakeClient->getCapturedOptions();
            $this->assertArrayNotHasKey(
                'verify_ssl',
                $captured,
                'verify_ssl is an inert phantom option; TLS posture belongs to the client context'
            );
        }
    }

    /**
     * Test that HTTP request returns null on 503 (service unavailable).
     * This exercises lines 642-648 (handleResponseObject returns null on non-2xx).
     */
    public function test_http_get_json_returns_null_on_503(): void
    {
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(503, [], '{"error":"service_unavailable"}'),
        ]);

        $result = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1']);

        $this->assertNull($result);
    }

    /**
     * Test that handleResponseObject caches nothing on non-2xx.
     * After a 503, a subsequent successful call should not return the error body.
     */
    public function test_http_get_json_does_not_cache_error_response(): void
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $callCount = 0;
        $fakeClient = new class ($callCount) extends Client {
            private int $callCount = 0;
            private int $responseIndex = 0;

            public function __construct(int $initialCount)
            {
                $this->callCount = $initialCount;
            }

            public function request(string $url, array $options = []): mixed
            {
                $this->callCount++;
                if ($this->responseIndex === 0) {
                    // First call: 503 error
                    $this->responseIndex++;
                    if (isset($options['error'])) {
                        ($options['error'])(new \RuntimeException('503'));
                    }
                } else {
                    // Second call: success
                    if (isset($options['success'])) {
                        ($options['success'])(new Response(200, [], '{"id":1,"title":"Test"}'));
                    }
                }
                return null;
            }

            public function getCallCount(): int
            {
                return $this->callCount;
            }
        };

        $ref = new \ReflectionClass($provider);
        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        // First call returns 503 -> null
        $result1 = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1']);
        $this->assertNull($result1);

        // Second call with same URL should make a fresh request (not cached error)
        $result2 = $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1']);
        $this->assertNotNull($result2);
        $this->assertSame(1, $result2['id']);
    }

    /**
     * C1 wire regression: the X-MAL-CLIENT-ID header must reach the serialized
     * request under its REAL name.
     *
     * The vendored client (workerman/http-client v3.1.3 Request::end()) applies
     * `options['headers']` with `foreach ($headers as $key => $value)
     * withHeader($key, $value)`, and walkor/psr7 performs no header-name
     * validation. A LIST-form header array therefore stores integer offsets as
     * the header names, so Workerman\Psr7\str() emits junk lines such as
     * `0: X-MAL-CLIENT-ID: <id>` and MAL never sees the client id at all —
     * every production request shipped unauthenticated while the whole suite
     * stayed green (no prior test exercised request assembly). This test drives
     * the real requestAndWait(), then runs the captured options through the
     * exact serialization path the vendored client uses, pinning the wire bytes.
     */
    public function test_request_and_wait_sends_client_id_header_under_its_real_name(): void
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $fakeClient = new class extends Client {
            private array $capturedOptions = [];

            public function request(string $url, array $options = []): mixed
            {
                $this->capturedOptions = $options;
                if (isset($options['success'])) {
                    ($options['success'])(new Response(200, [], '{}'));
                }
                return null;
            }

            public function getCapturedOptions(): array
            {
                return $this->capturedOptions;
            }
        };

        $ref = new \ReflectionClass($provider);
        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $this->invoke($provider, 'requestAndWait', ['https://api.myanimelist.net/v2/anime/1']);

        $captured = $fakeClient->getCapturedOptions();

        // Shape law: options['headers'] must be a real-name => value map,
        // never a list of pre-joined "Name: value" strings.
        $this->assertSame([
            'X-MAL-CLIENT-ID' => 'test-id',
            'Accept' => 'application/json',
        ], $captured['headers']);

        // Wire law: serialize through the same path the vendored client takes
        // (Request::end() withHeader loop + Workerman\Psr7\str()).
        $request = new \Workerman\Psr7\Request('GET', 'https://api.myanimelist.net/v2/anime/1');
        foreach ($captured['headers'] as $key => $value) {
            $request->withHeader($key, $value);
        }
        $wire = \Workerman\Psr7\str($request);

        $this->assertStringContainsString("\r\nX-MAL-CLIENT-ID: test-id\r\n", $wire);
        $this->assertStringContainsString("\r\nAccept: application/json\r\n", $wire);
        $this->assertStringNotContainsString("0: X-MAL-CLIENT-ID", $wire);
    }

    /**
     * Build the provider's REAL lazily-constructed HTTP client and drive it
     * through the vendored ConnectionPool::create() exactly as a pooled HTTPS
     * dial would, returning the ssl stream context of the connection object
     * the library actually hands to AsyncTcpConnection.
     *
     * This is the "REAL side" of the C32 advertised-vs-real bug class: the
     * toggle no longer merely exists in plugin config, it must alter the
     * context bytes the workerman pool merges over its insecure defaults and
     * dials with. The address is loopback on the discard port (9) so no
     * external network is touched; the async dial result is never awaited —
     * only the constructed connection's context is asserted, then destroyed.
     *
     * @param array<string, mixed> $settings          Provider settings.
     * @param \Psr\Log\LoggerInterface|null $logger   Optional spy, attached
     *     before the client is built so warnings on the insecure path land.
     *
     * @return array<string, mixed> The ssl section of the dial context.
     */
    private function dialedSslContext(array $settings, ?\Psr\Log\LoggerInterface $logger = null): array
    {
        $provider = new MyanimelistMetadataProvider($settings);
        if ($logger !== null) {
            $provider->setLogger($logger);
        }

        $client = $this->invoke($provider, 'getHttpClient', []);

        $poolProp = new \ReflectionProperty(Client::class, '_connectionPool');
        $poolProp->setAccessible(true);
        $pool = $poolProp->getValue($client);
        $this->assertIsObject($pool);

        $previousEvent = \Workerman\Worker::$globalEvent;
        \Workerman\Worker::$globalEvent = new \Workerman\Events\Select();
        try {
            $create = new \ReflectionMethod($pool, 'create');
            $create->setAccessible(true);
            $connection = $create->invoke($pool, 'tcp://127.0.0.1:9', true, '');
            $this->assertInstanceOf(\Workerman\Connection\AsyncTcpConnection::class, $connection);

            $contextProp = new \ReflectionProperty(
                \Workerman\Connection\AsyncTcpConnection::class,
                'socketContext'
            );
            $contextProp->setAccessible(true);
            /** @var array<string, mixed> $socketContext */
            $socketContext = $contextProp->getValue($connection);

            $connection->destroy();

            return $socketContext;
        } finally {
            \Workerman\Worker::$globalEvent = $previousEvent;
        }
    }

    /**
     * C32 REAL-side regression pin: by default the dialed pooled connection
     * carries a strict ssl context — certificate verification ON.
     *
     * The vendored ConnectionPool::create() defaults to verify_peer=false,
     * verify_peer_name=false, allow_self_signed=true and only SHALLOW-merges
     * the client constructor's `context` over them (the whole `ssl` section is
     * replaced), so the plugin must supply a COMPLETE strict section for the
     * control to reach the socket. Pre-fix this test is RED: the client was
     * built with no context at all and every pooled MAL HTTPS connection —
     * carrying the client_id credential — dialed unverified.
     */
    public function test_default_tls_setting_reaches_dialed_pooled_connection_as_strict_context(): void
    {
        $ssl = $this->dialedSslContext(['client_id' => 'test-id']);

        $this->assertSame([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => 'api.myanimelist.net',
            ],
        ], $ssl);
    }

    /**
     * C32 REAL-side regression pin: with use_ssl_verification=false the
     * INSECURE context reaches the dialed pooled connection (documented
     * self-hosted-proxy escape hatch) and the plugin logs a warning — the
     * silent-inert-toggle class of bug can never return.
     */
    public function test_disabled_tls_setting_reaches_dialed_connection_as_insecure_context_and_warns(): void
    {
        $warnings = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string|\Stringable $message) use (&$warnings): void {
                $warnings[] = (string) $message;
            }
        );

        $ssl = $this->dialedSslContext(
            ['client_id' => 'test-id', 'use_ssl_verification' => false],
            $logger
        );

        $this->assertSame([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => 'api.myanimelist.net',
            ],
        ], $ssl, 'toggle=false must reach the dial as an explicit lax ssl section');

        $this->assertCount(1, $warnings, 'disabling verification must log exactly one warning');
        $this->assertStringContainsString('use_ssl_verification', $warnings[0]);
    }

    /**
     * C32 root-cause tripwire over the locked vendor package: the string
     * `verify_ssl` appears nowhere in workerman/http-client (why the old
     * request option was inert), while the client-constructor `context` the
     * fix now uses is genuinely consumed by ConnectionPool::create().
     * If a future vendor bump introduces `verify_ssl`, this reddens on
     * purpose to force a re-review of where the toggle belongs.
     */
    public function test_vendored_transport_consumes_context_and_never_verify_ssl(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/vendor/workerman/http-client/src';
        $this->assertDirectoryIsReadable($srcDir);

        $files = glob($srcDir . '/*.php');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files, 'no workerman/http-client sources found to scan');

        $poolSeen = false;
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString(
                'verify_ssl',
                $source,
                "library read-path changed: $file now mentions verify_ssl"
            );
            if (basename($file) === 'ConnectionPool.php') {
                $poolSeen = true;
                $this->assertStringContainsString(
                    "\$this->options['context']",
                    $source,
                    'ConnectionPool must still consume the client-constructor context option'
                );
                $this->assertStringContainsString(
                    "'verify_peer' => false",
                    $source,
                    'ConnectionPool insecure default moved - re-verify the plugin strict override'
                );
            }
        }
        $this->assertTrue($poolSeen, 'ConnectionPool.php missing from the scan set');
    }

    /**
     * M-MYANIMELIST-07 law: the credential header must never be re-issued on a
     * redirect. The vendored workerman/http-client v3.1.3 defaults to
     * `allow_redirects => ['max' => 5]` and Request::redirect() re-issues the
     * SAME options array — including our `headers` with X-MAL-CLIENT-ID — to
     * whatever Location the server named, cross-origin included (Client.php
     * queues the follow-up; Request::end() then applies options['headers'] on
     * the NEW host). The provider must explicitly refuse redirects.
     *
     * RED before the fix: the captured options carry no allow_redirects, so the
     * library default follows the 3xx and the follow-up request arrives at the
     * hostile host still holding the credential.
     */
    public function test_request_and_wait_refuses_redirects_so_the_client_id_never_leaves_the_api_host(): void
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $fakeClient = new class extends Client {
            private array $capturedOptions = [];

            public function request(string $url, array $options = []): mixed
            {
                $this->capturedOptions = $options;
                if (isset($options['success'])) {
                    ($options['success'])(new Response(200, [], '{}'));
                }
                return null;
            }

            public function getCapturedOptions(): array
            {
                return $this->capturedOptions;
            }
        };

        $ref = new \ReflectionClass($provider);
        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $noopFloat = $ref->getProperty('timerSleep');
        $noopFloat->setAccessible(true);
        $noopFloat->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $this->invoke($provider, 'requestAndWait', ['https://api.myanimelist.net/v2/anime/1']);

        $captured = $fakeClient->getCapturedOptions();

        // Option pin: the provider states the refusal explicitly.
        $this->assertSame(
            ['max' => 0],
            $captured['allow_redirects'] ?? null,
            'requestAndWait must send allow_redirects => [max => 0]; the library default (max 5)'
                . ' re-issues X-MAL-CLIENT-ID to any redirect target, cross-origin included'
        );

        // REAL-library pin: with the captured options, the FIRST 3xx must make
        // Workerman\Http\Request::redirect() refuse (false) — the follow-up
        // request object is never even constructed, and the 3xx response then
        // surfaces to the success callback (Client.php `!$new_request` branch),
        // where handleResponseObject() treats it as a non-2xx failure.
        $request = (new \Workerman\Http\Request('https://api.myanimelist.net/v2/anime/1'))
            ->setOptions($captured);
        $this->assertFalse(
            \Workerman\Http\Request::redirect(
                $request,
                new Response(302, ['Location' => 'https://evil.example.test/steal'], '')
            ),
            'A 3xx must not produce a follow-up request under the provider options'
        );

        // Control pinning the vulnerability itself (documented leak vector):
        // with only the library default options, redirect() DOES build the
        // follow-up aimed at the hostile host, and its options still carry the
        // credential header — which Request::end() applies there before send.
        // If this ever returns false, the vendor changed its default and the
        // explicit refusal above should be re-examined, so fail loudly.
        $naive = (new \Workerman\Http\Request('https://api.myanimelist.net/v2/anime/1'))
            ->setOptions(['headers' => ['X-MAL-CLIENT-ID' => 'test-id']]);
        $leak = \Workerman\Http\Request::redirect(
            $naive,
            new Response(302, ['Location' => 'https://evil.example.test/steal'], '')
        );
        $this->assertInstanceOf(
            \Workerman\Http\Request::class,
            $leak,
            'vendored redirect defaults changed — re-verify the allow_redirects law above'
        );
        $this->assertSame('evil.example.test', $leak->getUri()->getHost());
        $this->assertSame(
            'test-id',
            $leak->getOptions()['headers']['X-MAL-CLIENT-ID'] ?? '(absent)',
            'the follow-up options no longer carry the credential — vendor behavior changed'
        );
    }

    /**
     * Consequence guard for the refusal above: when the transport hands a bare
     * 3xx to the success callback (exactly what happens with redirects
     * refused), httpGetJson() must treat it as a failure — null, uncached —
     * never decoding a redirect body as data.
     */
    public function test_http_get_json_returns_null_on_3xx(): void
    {
        $provider = $this->makeProvider([
            '/anime/1' => new Response(302, ['Location' => 'https://evil.example.test/x'], '{"id":1}'),
        ]);

        $this->assertNull(
            $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/1'])
        );
    }

    /**
     * Resident-worker cache freshness law (M-MYANIMELIST-08): a cached body is
     * served only inside the TTL window; past it the URL is refetched and the
     * entry replaced. The clock is injected via a by-reference `use (&$now)`
     * closure so no test ever sleeps.
     */
    public function test_cache_entry_is_fresh_within_the_ttl_and_refetched_after(): void
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $now = 1_760_000_000.0;

        $fakeClient = new class () extends Client {
            public int $requests = 0;

            public function __construct()
            {
                // Skip the parent ctor: it builds a ConnectionPool + coroutine
                // Channel that need a running event loop.
            }

            public function request(string $url, array $options = []): mixed
            {
                $this->requests++;
                ($options['success'])(new Response(200, [], '{"id":1,"title":"Generation ' . $this->requests . '"}'));

                return null;
            }
        };

        $ref = new \ReflectionClass($provider);

        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $clock = $ref->getProperty('clock');
        $clock->setAccessible(true);
        $clock->setValue($provider, static function () use (&$now): float {
            return $now;
        });

        $timerSleep = $ref->getProperty('timerSleep');
        $timerSleep->setAccessible(true);
        $timerSleep->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $url = 'https://api.myanimelist.net/v2/anime/1';

        $first = $this->invoke($provider, 'httpGetJson', [$url]);
        $this->assertSame('Generation 1', $first['title']);
        $this->assertSame(1, $fakeClient->requests);

        // +3540s: still inside the 3600s window — served from cache, zero requests.
        $now += 3540.0;
        $cached = $this->invoke($provider, 'httpGetJson', [$url]);
        $this->assertSame('Generation 1', $cached['title']);
        $this->assertSame(1, $fakeClient->requests);

        // +60s more: past the window — refetched, and the fresh body replaces the entry.
        $now += 60.0;
        $refetched = $this->invoke($provider, 'httpGetJson', [$url]);
        $this->assertSame('Generation 2', $refetched['title']);
        $this->assertSame(2, $fakeClient->requests);

        // And the replacement is cached again: a third call at the same time is free.
        $again = $this->invoke($provider, 'httpGetJson', [$url]);
        $this->assertSame('Generation 2', $again['title']);
        $this->assertSame(2, $fakeClient->requests);
    }

    /**
     * The cache is capped: a write beyond CACHE_MAX_ENTRIES (512 — literal pin,
     * rotate with the constant) evicts the oldest-inserted entry.
     */
    public function test_cache_is_capped_with_oldest_entry_evicted(): void
    {
        $provider = $this->makeProvider([
            'api.myanimelist.net' => new Response(200, [], '{"id":7,"title":"Fresh"}'),
        ]);

        $ref = new \ReflectionClass($provider);
        $cacheProp = $ref->getProperty('cache');
        $cacheProp->setAccessible(true);

        $now = microtime(true);
        $seed = [];
        for ($i = 0; $i < 512; $i++) {
            $seed['seed-' . $i] = [['seed' => $i], $now];
        }
        $cacheProp->setValue($provider, $seed);

        $this->invoke($provider, 'httpGetJson', ['https://api.myanimelist.net/v2/anime/7']);

        /** @var array<string, array{0: array<string, mixed>, 1: float}> $cache */
        $cache = $cacheProp->getValue($provider);
        $this->assertCount(512, $cache);
        $this->assertArrayNotHasKey('seed-0', $cache);
        $this->assertArrayHasKey('seed-511', $cache);
        $this->assertArrayHasKey(md5('https://api.myanimelist.net/v2/anime/7'), $cache);
    }

    /**
     * NSFW default-deny posture (M-MYANIMELIST-08): the plugin never sends the
     * `nsfw`/`nsfw_all` search parameters, so MAL's API-side default omits
     * adult entries from results. Pinned at the wire: the actual request URL
     * carries no nsfw parameter at all.
     */
    public function test_search_request_omits_nsfw_parameters(): void
    {
        $provider = new MyanimelistMetadataProvider(['client_id' => 'test-id']);

        $fakeClient = new class () extends Client {
            public ?string $lastUrl = null;

            public function __construct()
            {
            }

            public function request(string $url, array $options = []): mixed
            {
                $this->lastUrl = $url;
                ($options['success'])(new Response(200, [], '{"data":[]}'));

                return null;
            }
        };

        $ref = new \ReflectionClass($provider);

        $httpClient = $ref->getProperty('httpClient');
        $httpClient->setAccessible(true);
        $httpClient->setValue($provider, $fakeClient);

        $timerSleep = $ref->getProperty('timerSleep');
        $timerSleep->setAccessible(true);
        $timerSleep->setValue($provider, static function (float $s): void {
        });

        $waitTick = $ref->getProperty('waitTick');
        $waitTick->setAccessible(true);
        $waitTick->setValue($provider, static function (): void {
        });

        $this->assertNull($this->invoke($provider, 'findIdByTitleInternal', ['Some Title']));

        $this->assertNotNull($fakeClient->lastUrl);
        $this->assertStringNotContainsString('nsfw', (string) $fakeClient->lastUrl);
        $this->assertStringContainsString('q=', (string) $fakeClient->lastUrl);
    }
}
