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
            __DIR__.'/../MediaBundle.php',
        ]);
};
