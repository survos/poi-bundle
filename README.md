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
    # Required by Nominatim's usage policy — identify your app + a contact. No API key: the
    # public Nominatim instance is free and unauthenticated, this header IS the "who's asking".
    nominatim_user_agent: '%env(default::POI_NOMINATIM_USER_AGENT)%'
```

## Caching

Two layers, because they cover different failure modes:

- **`NominatimPoiCache`** (JSONL, keyed by `query` + `viewbox`) — the one that actually matters.
  Every result is appended to `%kernel.project_dir%/var/poi-cache.jsonl` (configurable via
  `cache_path`); a repeated query, even from a fresh process reading the same file, is served
  from disk and never touches the network. This is what makes it safe to run against a
  many-thousand-tag pipeline without violating Nominatim's usage policy (no repeated identical
  queries) — and it's resilient to Nominatim itself being unavailable (verified live: the public
  instance 403'd a request from this session's environment; a cache is the only thing standing
  between "add one more tag" and "re-fetch everything").
- **`CachingHttpClient`** (RFC 9111), wired the same way as `loc-bundle`/`omeka-bundle`, gated by
  `cache_enabled` (default true). Checked live against `nominatim.openstreetmap.org`: it sends
  `Cache-Control: no-cache`, so this layer is currently a no-op there — wired anyway for
  consistency with the rest of mono, and in case a self-hosted Nominatim instance (`base_url`)
  ever sends real cache headers.

Live (uncached) requests are also throttled to `min_request_interval` seconds apart (default
`1.1`), per Nominatim's ~1 request/second policy.

A shared `CachingHttpClient` factory in `survos/fetch-bundle` — so this ~15-line wiring block
isn't duplicated per-bundle — is a reasonable follow-up, not done here; this bundle's copy matches
`loc-bundle`'s existing pattern exactly.

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
