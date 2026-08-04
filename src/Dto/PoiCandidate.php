<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Dto;

use Survos\DataContracts\Dto\Item\PoiDto;

/**
 * One OpenStreetMap search result for a named point of interest — not yet persisted anywhere.
 * {@see self::toDto()} converts it to a {@see PoiDto} for folio storage (dataset:normalize →
 * Row, via ContentType::POI) — that's the intended persistence path, not a bundle-local table:
 * POI matches are bulk/automated output, the same shape every other folio content type already
 * has a home for, not a curated one-at-a-time library like survos/place-map-bundle's Place.
 */
final readonly class PoiCandidate
{
    public function __construct(
        public string $label,
        public float $lat,
        public float $lng,
        public string $osmType,
        public int $osmId,
        /** OSM class/type joined as "class/type", e.g. "amenity/place_of_worship", "amenity/school". */
        public ?string $category,
        /** Full raw Nominatim result — address parts, importance score, etc. */
        public array $raw,
    ) {
    }

    /**
     * @param string|null $matchedQuery the literal source tag/caption this candidate was searched
     *     for (e.g. "Catedral de Ancud"), so it survives alongside OSM's own name in $title
     * @param int|null $parentGeonameId the resolved city (survos/geonames-bundle geonameId) this
     *     candidate was searched within, joining POI back to the geonames hierarchy
     */
    public function toDto(?string $matchedQuery = null, ?int $parentGeonameId = null): PoiDto
    {
        $dto = new PoiDto();
        $dto->title = $this->label;
        $dto->latitude = $this->lat;
        $dto->longitude = $this->lng;
        $dto->category = $this->category;
        $dto->osmType = $this->osmType;
        $dto->osmId = $this->osmId;
        $dto->matchedQuery = $matchedQuery;
        $dto->parentGeonameId = $parentGeonameId;
        $dto->sourceUrl = sprintf('https://www.openstreetmap.org/%s/%d', $this->osmType, $this->osmId);

        return $dto;
    }
}
