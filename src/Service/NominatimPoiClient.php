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
 * one.
 *
 * $http is SurvosPoiBundle's scoped client (base_uri + User-Agent baked in via
 * prependExtension()), optionally wrapped in Symfony's RFC 9111 CachingHttpClient — see that
 * bundle class for why that wrapper is currently a no-op against Nominatim specifically.
 * $cache is the layer that actually matters: a query is only ever sent live once per
 * (query, viewbox) for the lifetime of the cache file, satisfying Nominatim's usage policy of
 * ~1 request/second and no repeated identical queries. Live requests are throttled to
 * $minRequestInterval regardless.
 *
 * @see https://nominatim.org/release-docs/latest/api/Search/
 */
final class NominatimPoiClient
{
    /** Always fetched regardless of the caller's requested limit, so a cached entry never needs
     *  re-fetching just because a later call asks for more candidates than a previous one did. */
    private const FETCH_LIMIT = 10;

    private static ?float $lastRequestAt = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly NominatimPoiCache $cache,
        private readonly float $minRequestInterval = 1.1,
    ) {
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $viewbox [west, south, east, north] —
     *                                                               `bounded=1` restricts results to this box rather than merely preferring it
     *
     * @return list<PoiCandidate>
     */
    public function search(string $query, array $viewbox, int $limit = 5): array
    {
        $raw = $this->cache->get($query, $viewbox);
        if (null === $raw) {
            $raw = $this->fetch($query, $viewbox);
            $this->cache->set($query, $viewbox, $raw);
        }

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
            array_slice($raw, 0, $limit),
        );
    }

    /** @return list<array<string,mixed>> */
    private function fetch(string $query, array $viewbox): array
    {
        $this->throttle();

        $response = $this->http->request('GET', '/search', [
            'query' => [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => self::FETCH_LIMIT,
                'viewbox' => implode(',', $viewbox),
                'bounded' => 1,
            ],
        ]);

        return $response->toArray(false);
    }

    /** Waits out the remainder of $minRequestInterval since the last LIVE request, if needed. */
    private function throttle(): void
    {
        if (null !== self::$lastRequestAt) {
            $wait = $this->minRequestInterval - (microtime(true) - self::$lastRequestAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        self::$lastRequestAt = microtime(true);
    }
}
