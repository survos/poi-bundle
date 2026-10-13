<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\PoiBundle\Service\NominatimPoiCache;
use Survos\PoiBundle\Service\NominatimPoiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NominatimPoiClientTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/poi-bundle-tests-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    private function cache(): NominatimPoiCache
    {
        return new NominatimPoiCache(cachePath: $this->cacheDir.'/poi-cache.jsonl', projectDir: $this->cacheDir);
    }

    #[Test]
    public function searchBuildsBoundedViewboxRequestAndParsesCandidates(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;
        $mockClient = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
                $capturedUrl = $url;
                $capturedOptions = $options;

                return new MockResponse(json_encode([
                    [
                        'display_name' => 'Catedral de Ancud, Ancud, Chile',
                        'lat' => '-41.8697',
                        'lon' => '-73.8286',
                        'osm_type' => 'way',
                        'osm_id' => 123456,
                        'class' => 'amenity',
                        'type' => 'place_of_worship',
                    ],
                ]));
            },
        );

        $client = new NominatimPoiClient($mockClient, $this->cache(), minRequestInterval: 0.0);
        $candidates = $client->search('Catedral de Ancud', [-73.9, -41.9, -73.7, -41.8], limit: 3);

        self::assertCount(1, $candidates);
        self::assertSame('Catedral de Ancud, Ancud, Chile', $candidates[0]->label);
        self::assertSame(-41.8697, $candidates[0]->lat);
        self::assertSame(-73.8286, $candidates[0]->lng);
        self::assertSame('way', $candidates[0]->osmType);
        self::assertSame(123456, $candidates[0]->osmId);
        self::assertSame('amenity/place_of_worship', $candidates[0]->category);

        self::assertStringContainsString('/search', (string) $capturedUrl);
        self::assertSame('Catedral de Ancud', $capturedOptions['query']['q']);
        self::assertSame(1, $capturedOptions['query']['bounded']);
        self::assertSame('-73.9,-41.9,-73.7,-41.8', $capturedOptions['query']['viewbox']);
        // Always fetches the internal FETCH_LIMIT superset, not the caller's --limit — see
        // NominatimPoiClient::FETCH_LIMIT docblock (a later, larger request can still be served
        // from cache instead of re-fetching).
        self::assertSame(10, $capturedOptions['query']['limit']);
    }

    #[Test]
    public function searchReturnsEmptyArrayWhenNothingMatches(): void
    {
        $mockClient = new MockHttpClient(fn (): MockResponse => new MockResponse('[]'));
        $client = new NominatimPoiClient($mockClient, $this->cache(), minRequestInterval: 0.0);

        self::assertSame([], $client->search('Nonexistent Place', [0, 0, 1, 1]));
    }

    #[Test]
    public function repeatedSearchIsServedFromCacheNotTheNetwork(): void
    {
        $requestCount = 0;
        $mockClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse(json_encode([
                [
                    'display_name' => 'Plaza de Ancud',
                    'lat' => '-41.87',
                    'lon' => '-73.83',
                    'osm_type' => 'node',
                    'osm_id' => 987,
                    'class' => 'leisure',
                    'type' => 'park',
                ],
            ]));
        });

        // Same NominatimPoiCache instance across two clients, mirroring two separate pipeline
        // runs sharing one poi-cache.jsonl file on disk.
        $cache = $this->cache();
        $first = new NominatimPoiClient($mockClient, $cache, minRequestInterval: 0.0);
        $first->search('Plaza de Ancud', [-73.9, -41.9, -73.7, -41.8]);

        $second = new NominatimPoiClient($mockClient, $cache, minRequestInterval: 0.0);
        $candidates = $second->search('Plaza de Ancud', [-73.9, -41.9, -73.7, -41.8]);

        self::assertSame(1, $requestCount, 'the second search() call must be served from the JSONL cache, not the network');
        self::assertSame('Plaza de Ancud', $candidates[0]->label);

        // And a fresh NominatimPoiCache instance loading the SAME file (a genuinely new process)
        // still hits the cache, not the network.
        $thirdCache = new NominatimPoiCache(cachePath: $this->cacheDir.'/poi-cache.jsonl', projectDir: $this->cacheDir);
        $third = new NominatimPoiClient($mockClient, $thirdCache, minRequestInterval: 0.0);
        $third->search('Plaza de Ancud', [-73.9, -41.9, -73.7, -41.8]);
        self::assertSame(1, $requestCount);
    }

    #[Test]
    public function toDtoCarriesMatchedQueryAndParentGeonameThrough(): void
    {
        $mockClient = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([
            [
                'display_name' => 'Plaza de Ancud',
                'lat' => '-41.87',
                'lon' => '-73.83',
                'osm_type' => 'node',
                'osm_id' => 987,
                'class' => 'leisure',
                'type' => 'park',
            ],
        ])));
        $client = new NominatimPoiClient($mockClient, $this->cache(), minRequestInterval: 0.0);
        $candidate = $client->search('Plaza de Ancud', [-73.9, -41.9, -73.7, -41.8])[0];

        $dto = $candidate->toDto('Plaza de Ancud', parentGeonameId: 3899539);

        self::assertSame('Plaza de Ancud', $dto->title);
        self::assertSame('Plaza de Ancud', $dto->matchedQuery);
        self::assertSame(3899539, $dto->parentGeonameId);
        self::assertSame('leisure/park', $dto->category);
        self::assertSame('node', $dto->osmType);
        self::assertSame(987, $dto->osmId);
        self::assertSame('https://www.openstreetmap.org/node/987', $dto->sourceUrl);
    }
}
