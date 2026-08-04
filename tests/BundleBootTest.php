<?php

declare(strict_types=1);

namespace Survos\PoiBundle\Tests;

use Survos\PoiBundle\Command\PoiSearchCommand;
use Survos\PoiBundle\Service\NominatimPoiCache;
use Survos\PoiBundle\Service\NominatimPoiClient;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\HttpKernel\KernelInterface;

/** Boots the real container through SurvosPoiBundle's actual loadExtension()/prependExtension() —
 *  unlike NominatimPoiClientTest, which constructs the service by hand and never exercises the
 *  CachingHttpClientFactory wiring or the scoped 'poi.nominatim.client' registration at all. */
final class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testContainerCompilesAndServicesResolve(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        self::assertInstanceOf(NominatimPoiClient::class, $container->get(NominatimPoiClient::class));
        self::assertInstanceOf(NominatimPoiCache::class, $container->get(NominatimPoiCache::class));
    }

    public function testCommandIsRegistered(): void
    {
        self::bootKernel();
        /** @var KernelInterface $kernel */
        $kernel = static::$kernel;

        $application = new Application($kernel);
        self::assertContains('poi:search', array_keys($application->all()));

        static::getContainer()->get(PoiSearchCommand::class);
    }
}
