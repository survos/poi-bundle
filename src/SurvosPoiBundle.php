<?php

declare(strict_types=1);

namespace Survos\PoiBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\PoiBundle\Service\NominatimPoiClient;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Points-of-interest search: given a literal name and a bounding box (the caller's job to
 * resolve — e.g. via survos/geonames-bundle's city lookup — this bundle takes no dependency on
 * how), search OpenStreetMap/Nominatim for matching churches, schools, shops, parks, etc.
 * No persistence of its own — {@see \Survos\PoiBundle\Dto\PoiCandidate::toDto()} converts a
 * result to a folio PoiDto (ContentType::POI), folio being the intended storage layer.
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosPoiBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('nominatim_user_agent')
                ->info('Required by Nominatim usage policy: "AppName/version (https://your-site; contact@you)".')
                ->isRequired()
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $builder->autowire(NominatimPoiClient::class)
            ->setArgument('$userAgent', $config['nominatim_user_agent'])
            ->setPublic(false);
    }
}
