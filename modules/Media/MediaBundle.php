<?php

namespace Tallyst\Media;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Media — the second real Tallyst module, built from the documented "adding a module"
 * pattern (see CLAUDE.md). Foundational infrastructure (uploads/images), default-enabled.
 *
 * Like every module it self-registers its Doctrine mapping + services and pins
 * getPath() to __DIR__. NOTE: Media also WRAPS third-party bundles (Vich, Liip) — those
 * are registered app-side in config/bundles.php with their config/packages/*.yaml.
 */
class MediaBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(DoctrineOrmMappingsPass::createAttributeMappingDriver(
            ['Tallyst\\Media\\Entity'],
            [$this->getPath().'/Entity'],
        ));
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import($this->getPath().'/config/services.php');
    }
}
