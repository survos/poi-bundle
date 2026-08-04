# POI Bundle

`survos/poi-bundle` resolves named points of interest — churches, schools, shops, parks — from
OpenStreetMap/Nominatim, scoped to a known geographic context (a bounding box). It's the third
companion in a small family of place-resolution bundles, each solving one layer:

- [`survos/geonames-bundle`](../geonames-bundle) — country → admin1 → admin2 → city, by name,
  including locale-specific alternate names. "What city is this?"
- [`survos/place-map-bundle`](../place-map-bundle) — a curated point/polygon library, one
  Nominatim query at a time (`place:import "Geneva, Switzerland"`). "Render this known place on a map."
- `survos/poi-bundle` (this bundle) — bulk/automated matching of a literal name ("Catedral de
  Ancud") against OSM features *within* an already-resolved city. "What specific building is this?"

See [survos/mono#28](https://github.com/survos/mono/issues/28) for how the split was decided, and
[fortepan-us/kronofoto#48](https://github.com/fortepan-us/kronofoto/issues/48) for prior art
already in the ecosystem (kronofoto's own OSM point-feature importer, `pano`'s old local Overpass
mirror).

## What it does

```bash
php bin/console poi:search "Catedral de Ancud" --viewbox=-73.9,-41.9,-73.7,-41.8
```

```
 -------------------------------- ------------------------- ------------ --------- ---------
  label                            category                  osm          lat       lng
 -------------------------------- ------------------------- ------------ --------- ---------
  Catedral de Ancud, Ancud, Chile  amenity/place_of_worship  way/123456   -41.8697  -73.8286
 -------------------------------- ------------------------- ------------ --------- ---------
```

The bounding box is the caller's responsibility to resolve — e.g. from
`geonames-bundle`'s `GeoService::find('Ancud, Chile')` — this bundle takes no dependency on
geonames-bundle or any other place-resolution source. That's the point of the split: compose them
in the consuming app/pipeline, don't couple the bundles to each other.

## Configuration

```yaml
survos_poi:
    # Required by Nominatim's usage policy — identify your app + a contact.
    nominatim_user_agent: '%env(default::PLACE_MAP_USER_AGENT)%'
```

## Storage: folio, not a bundle-local table

Unlike `place-map-bundle`'s `Place` entity, this bundle does **not** persist anything itself. POI
matching is bulk/automated output — the same shape every other folio content type already has a
home for — not a curated one-at-a-time library. `--as-dto` prints the top candidate as a folio
`PoiDto` (`ContentType::POI`, `survos/data-contracts`), ready to feed a `dataset:normalize`
pipeline:

```bash
php bin/console poi:search "Catedral de Ancud" --viewbox=-73.9,-41.9,-73.7,-41.8 --as-dto --geoname=3899539
```

```json
{
    "title": "Catedral de Ancud, Ancud, Chile",
    "latitude": -41.8697,
    "longitude": -73.8286,
    "category": "amenity/place_of_worship",
    "osmType": "way",
    "osmId": 123456,
    "matchedQuery": "Catedral de Ancud",
    "parentGeonameId": 3899539,
    "sourceUrl": "https://www.openstreetmap.org/way/123456"
}
```

`PoiDto` (`Survos\DataContracts\Dto\Item\PoiDto`) extends `BaseItemDto` like every other content
type — `$title`/`$latitude`/`$longitude`/`$city`/`$state`/`$country` are inherited; `$category`,
`$osmType`/`$osmId` (provenance), `$matchedQuery` (the literal source text, kept separate from
OSM's own name), and `$parentGeonameId` (the join back to geonames-bundle's hierarchy) are new.
`PoiCandidate::toDto()` is the conversion — see `src/Dto/PoiCandidate.php`.

## Status

Built for [survos/mono#26](https://github.com/survos/mono/issues/26)'s tag-splitting pilot
(Enterreno's `Etiquetas` field mixes place names — "Catedral de Ancud", "Plaza de Ancud" — into
its facet tags). Not yet wired into that pipeline; `NominatimPoiClient` + `PoiDto` are the two
pieces it needs, both real and tested, but nothing calls this bundle from `md`'s ingest yet.

Not done: candidate ranking/scoring (this bundle returns Nominatim's own result order, unranked —
see the original spec in [survos/mono#28](https://github.com/survos/mono/issues/28) for what a
real matcher would need), and no bulk local OSM extract import (kronofoto's
`import_osm_points.py` is the reference if/when that's needed instead of live per-query search).
