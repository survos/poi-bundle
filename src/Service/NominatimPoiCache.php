<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Service;

/**
 * Persistent (query, viewbox) -> raw Nominatim result cache, backed by a JSONL file — the same
 * append-only, human-readable format the rest of the Survos pipeline already uses for
 * intermediate data. Nominatim explicitly sends `Cache-Control: no-cache` (verified against the
 * live API), so RFC 9111 HTTP caching buys nothing here; this is the layer that actually avoids
 * repeating a query already resolved in a previous run — the whole point, since Nominatim's usage
 * policy expects callers not to hammer it with the same lookups over and over.
 *
 * Loaded once into memory on first use (fine at the scale this runs at — tens of thousands of
 * rows, not millions); new entries are appended to disk immediately so a crashed/interrupted run
 * doesn't lose already-paid-for lookups.
 */
final class NominatimPoiCache
{
    /** @var array<string, list<array<string,mixed>>>|null */
    private ?array $entries = null;

    /** @var resource|null */
    private $handle;

    public function __construct(
        private readonly ?string $cachePath,
        private readonly string $projectDir,
    ) {
    }

    /** @return list<array<string,mixed>>|null null = not cached (a real miss); [] = cached empty result */
    public function get(string $query, array $viewbox): ?array
    {
        $this->load();

        return $this->entries[self::key($query, $viewbox)] ?? null;
    }

    /** @param list<array<string,mixed>> $rawResults */
    public function set(string $query, array $viewbox, array $rawResults): void
    {
        $this->load();
        $this->entries[self::key($query, $viewbox)] = $rawResults;

        $handle = $this->handle ??= $this->openForAppend();
        fwrite($handle, json_encode([
            'query' => $query,
            'viewbox' => $viewbox,
            'results' => $rawResults,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    public function path(): string
    {
        if (null !== $this->cachePath && '' !== trim($this->cachePath)) {
            return $this->cachePath;
        }

        return $this->projectDir.'/var/poi-cache.jsonl';
    }

    /** Rounded so float-formatting differences (-73.90 vs -73.9) don't produce false misses. */
    private static function key(string $query, array $viewbox): string
    {
        $box = implode(',', array_map(static fn (float $v): string => number_format($v, 5), $viewbox));

        return sha1($query.'|'.$box);
    }

    private function load(): void
    {
        if (null !== $this->entries) {
            return;
        }

        $this->entries = [];
        $path = $this->path();
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['query'], $row['viewbox'], $row['results'])) {
                continue;
            }
            $this->entries[self::key((string) $row['query'], (array) $row['viewbox'])] = $row['results'];
        }
    }

    /** @return resource */
    private function openForAppend()
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create POI cache directory: %s', $dir));
        }

        $handle = fopen($path, 'ab');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Unable to open POI cache for writing: %s', $path));
        }

        return $handle;
    }

    public function __destruct()
    {
        if (null !== $this->handle) {
            fclose($this->handle);
        }
    }
}
