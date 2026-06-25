<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Tallyst\\Media\\', __DIR__.'/../')
        ->exclude([
            __DIR__.'/../Entity/',
            // config/ holds this DI file (imported explicitly by the bundle), NOT service classes —
            // excluding it keeps the PROD container compile from failing on the PSR-4 scan.
            __DIR__.'/../config/',
            __DIR__.'/../MediaBundle.php',
        ]);
};
