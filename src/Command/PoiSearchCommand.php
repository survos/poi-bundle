<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Command;

use Survos\PoiBundle\Dto\PoiCandidate;
use Survos\PoiBundle\Service\NominatimPoiClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Search OpenStreetMap for a named point of interest within a bounding box — a manual/scriptable
 * entry point onto NominatimPoiClient, mirroring place-map-bundle's `place:import`. Unlike that
 * command, this one does not persist anything itself: `--as-dto` prints the resolved PoiDto
 * (folio's storage shape — see PoiCandidate::toDto()) as JSON, ready to feed a dataset pipeline.
 */
#[AsCommand('poi:search', 'Search OpenStreetMap for a named point of interest within a bounding box')]
final class PoiSearchCommand
{
    public function __construct(
        private readonly NominatimPoiClient $nominatim,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Literal name to search for, e.g. "Catedral de Ancud"')] string $query,
        #[Option('Bounding box to search within: west,south,east,north')] string $viewbox,
        #[Option('Max candidates to show')] int $limit = 5,
        #[Option('Print the top candidate as a folio PoiDto (JSON) instead of a table')] bool $asDto = false,
        #[Option('geonameId of the resolved parent city, attached to --as-dto output')] ?int $geoname = null,
    ): int {
        $box = array_map('floatval', explode(',', $viewbox));
        if (count($box) !== 4) {
            $io->error('--viewbox must be "west,south,east,north"');

            return Command::FAILURE;
        }

        $candidates = $this->nominatim->search($query, $box, $limit);
        if ($candidates === []) {
            $io->warning(sprintf('No OpenStreetMap results for "%s" within the given box.', $query));

            return Command::SUCCESS;
        }

        if ($asDto) {
            $dto = $candidates[0]->toDto($query, $geoname);
            $io->writeln((string) json_encode($dto->toMeili(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->table(
            ['label', 'category', 'osm', 'lat', 'lng'],
            array_map(
                static fn (PoiCandidate $c): array => [$c->label, $c->category ?? '—', "{$c->osmType}/{$c->osmId}", $c->lat, $c->lng],
                $candidates,
            ),
        );

        return Command::SUCCESS;
    }
}
