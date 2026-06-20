<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Service definitions for the FormBuilder module. Autowire + autoconfigure are on,
 * so services implementing tagged interfaces (ShortcodeInterface, ModuleInterface,
 * FormTypeInterface, ...) are registered automatically.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Tallyst\\FormBuilder\\', __DIR__.'/../')
        ->exclude([
            __DIR__.'/../Entity/',
            __DIR__.'/../Form/Model/',
            __DIR__.'/../FormBuilderBundle.php',
            __DIR__.'/../{Kernel.php}',
        ]);
};
