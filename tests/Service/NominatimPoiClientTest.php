<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\PoiBundle\Service\NominatimPoiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NominatimPoiClientTest extends TestCase
{
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

        $client = new NominatimPoiClient($mockClient, 'poi-bundle-tests/1.0 (test@example.com)');
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
        self::assertSame(3, $capturedOptions['query']['limit']);
        self::assertContains('User-Agent: poi-bundle-tests/1.0 (test@example.com)', $capturedOptions['headers'] ?? []);
    }

    #[Test]
    public function searchReturnsEmptyArrayWhenNothingMatches(): void
    {
        $mockClient = new MockHttpClient(fn (): MockResponse => new MockResponse('[]'));
        $client = new NominatimPoiClient($mockClient, 'poi-bundle-tests/1.0');

        self::assertSame([], $client->search('Nonexistent Place', [0, 0, 1, 1]));
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
        $client = new NominatimPoiClient($mockClient, 'poi-bundle-tests/1.0');
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
