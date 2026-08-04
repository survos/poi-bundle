<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Service;

use Survos\PoiBundle\Dto\PoiCandidate;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Searches OpenStreetMap's Nominatim for named points of interest within a bounding box —
 * e.g. "Catedral de Ancud" restricted to Ancud's viewbox, rather than a free-floating global
 * search. This bundle deliberately takes no dependency on how that box was resolved (a city
 * lookup, a saved place, whatever) — that's the caller's job; this client only searches within
 * one. Nominatim's usage policy requires a descriptive User-Agent and no more than ~1
 * request/second — this client does not batch/parallelize, callers should throttle between calls.
 *
 * @see https://nominatim.org/release-docs/latest/api/Search/
 */
final class NominatimPoiClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $userAgent,
        private readonly string $baseUrl = 'https://nominatim.openstreetmap.org',
    ) {
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $viewbox [west, south, east, north] —
     *     `bounded=1` restricts results to this box rather than merely preferring it.
     * @return list<PoiCandidate>
     */
    public function search(string $query, array $viewbox, int $limit = 5): array
    {
        $response = $this->http->request('GET', $this->baseUrl . '/search', [
            'query' => [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => $limit,
                'viewbox' => implode(',', $viewbox),
                'bounded' => 1,
            ],
            'headers' => ['User-Agent' => $this->userAgent],
        ]);

        return array_map(
            static fn (array $result): PoiCandidate => new PoiCandidate(
                label: (string) $result['display_name'],
                lat: (float) $result['lat'],
                lng: (float) $result['lon'],
                osmType: (string) $result['osm_type'],
                osmId: (int) $result['osm_id'],
                category: isset($result['class'], $result['type'])
                    ? sprintf('%s/%s', $result['class'], $result['type'])
                    : null,
                raw: $result,
            ),
            $response->toArray(false),
        );
    }
}
