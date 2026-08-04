<?php

declare(strict_types=1);

namespace Survos\PoiBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\PoiBundle\Service\NominatimPoiCache;
use Survos\PoiBundle\Service\NominatimPoiClient;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\CachingHttpClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Points-of-interest search: given a literal name and a bounding box (the caller's job to
 * resolve — e.g. via survos/geonames-bundle's city lookup — this bundle takes no dependency on
 * how), search OpenStreetMap/Nominatim for matching churches, schools, shops, parks, etc.
 *
 * Two caching layers, because they cover different things:
 *   - CachingHttpClient (RFC 9111), wired the same way as loc-bundle/omeka-bundle. Verified live
 *     against nominatim.openstreetmap.org: it sends `Cache-Control: no-cache`, so this wrapper is
 *     presently a no-op there — wired anyway for consistency with the rest of mono and in case
 *     that ever changes (a self-hosted Nominatim instance, a different `base_url`, etc.).
 *   - NominatimPoiCache (JSONL, keyed by query+viewbox) — the layer that actually matters: an
 *     explicit "never ask Nominatim the same question twice" cache, independent of whatever
 *     headers the origin happens to send. See NominatimPoiClient.
 *
 * No persistence of resolved POIs themselves — {@see \Survos\PoiBundle\Dto\PoiCandidate::toDto()}
 * converts a result to a folio PoiDto (ContentType::POI), folio being the intended storage layer.
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosPoiBundle extends AbstractSurvosBundle
{
    private const DEFAULT_BASE_URL = 'https://nominatim.openstreetmap.org';
    private const DEFAULT_CACHE_MAX_TTL = 2_592_000; // 30 days — a POI's location rarely changes

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('nominatim_user_agent')
                ->info('Required by Nominatim usage policy: "AppName/version (https://your-site; contact@you)".')
                ->isRequired()
            ->end()
            ->scalarNode('base_url')
                ->defaultValue(self::DEFAULT_BASE_URL)
                ->info('Nominatim-compatible endpoint. Override for a self-hosted instance.')
            ->end()
            ->scalarNode('cache_path')
                ->defaultNull()
                ->info('JSONL query-cache path. Default: %kernel.project_dir%/var/poi-cache.jsonl.')
            ->end()
            ->booleanNode('cache_enabled')
                ->defaultTrue()
                ->info('Wrap the HTTP client in Symfony\'s RFC 9111 CachingHttpClient. Currently a no-op against the public Nominatim instance (it sends Cache-Control: no-cache) — the JSONL query cache is what actually avoids repeat requests. Set false only to debug raw request/response behavior.')
            ->end()
            ->integerNode('cache_max_ttl')
                ->defaultValue(self::DEFAULT_CACHE_MAX_TTL)
                ->min(1)
                ->info('Upper bound on how long a response is cached, even if the origin sends a longer max-age.')
            ->end()
            ->floatNode('min_request_interval')
                ->defaultValue(1.1)
                ->info('Minimum seconds between live (uncached) requests, per Nominatim\'s ~1 request/second usage policy.')
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set(NominatimPoiCache::class)
            ->arg('$cachePath', $config['cache_path'])
            ->arg('$projectDir', '%kernel.project_dir%');

        $clientId = 'poi.nominatim.client';
        if ($config['cache_enabled']) {
            $services->set('survos_poi.http_cache', FilesystemTagAwareAdapter::class)
                ->args(['poi_http', $config['cache_max_ttl'], '%kernel.cache_dir%/poi_http']);

            $services->set('survos_poi.caching_client', CachingHttpClient::class)
                ->args([
                    service('poi.nominatim.client'),
                    service('survos_poi.http_cache'),
                    // CachingHttpClient resolves URLs itself before delegating — it does NOT
                    // inherit the decorated client's own base_uri default option (see loc-bundle,
                    // same fix), so a relative request (every NominatimPoiClient call) throws
                    // "scheme is missing" without this.
                    ['base_uri' => $config['base_url']],
                    true,
                    $config['cache_max_ttl'],
                ]);
            $clientId = 'survos_poi.caching_client';
        }

        $services->set(NominatimPoiClient::class)
            ->arg('$http', service($clientId))
            ->arg('$minRequestInterval', $config['min_request_interval']);
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::prependExtension($container, $builder);

        $config = self::rawConfig($builder);

        $builder->prependExtensionConfig('framework', [
            'http_client' => [
                'scoped_clients' => [
                    'poi.nominatim.client' => [
                        'base_uri' => $config['base_url'] ?? self::DEFAULT_BASE_URL,
                        'headers' => [
                            'User-Agent' => $config['nominatim_user_agent'] ?? null,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** Reads `survos_poi.*` before the config tree is processed — the standard way to peek a bundle's own config in prependExtension. */
    private static function rawConfig(ContainerBuilder $builder): array
    {
        $merged = [];
        foreach ($builder->getExtensionConfig('survos_poi') as $config) {
            $merged = array_merge($merged, $config);
        }

        return $merged;
    }
}
