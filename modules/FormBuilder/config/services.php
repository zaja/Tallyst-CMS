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
            // config/ holds this DI file (imported explicitly by the bundle), NOT service classes.
            // Without excluding it, the PSR-4 scan derives a bogus class from config/services.php and
            // the PROD container compile fails ("Expected to find class Tallyst\FormBuilder\config\services").
            __DIR__.'/../config/',
            __DIR__.'/../FormBuilderBundle.php',
            __DIR__.'/../{Kernel.php}',
        ]);
};
