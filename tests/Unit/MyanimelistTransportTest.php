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
}
