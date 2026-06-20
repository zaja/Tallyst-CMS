<?php

namespace Tallyst\FormBuilder;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * FormBuilder — the first real Tallyst module. It is a lean Symfony bundle and is
 * meant as the template every future module copies.
 *
 * It self-registers two things so the app side stays minimal:
 *  - its Doctrine entity mapping (attribute driver over Entity/),
 *  - its service definitions (config/services.php).
 *
 * The remaining app-side wiring (autoload, bundles.php, routes import, asset_mapper
 * path, stimulus registration) is documented in CLAUDE.md → "Adding a new module".
 */
class FormBuilderBundle extends AbstractBundle
{
    /**
     * This bundle lives directly in modules/FormBuilder (no src/ subdir), so pin
     * the path explicitly; otherwise AbstractBundle's heuristic mis-resolves it
     * (affecting services import, Doctrine mapping dir and the @FormBuilder Twig
     * namespace).
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Map this bundle's entities without touching config/packages/doctrine.yaml.
        $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
            ['Tallyst\\FormBuilder\\Entity'],
            [$this->getPath().'/Entity'],
        ));
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import($this->getPath().'/config/services.php');
    }
}
