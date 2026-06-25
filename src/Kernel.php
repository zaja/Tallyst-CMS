<?php

namespace App;

use App\Theme\ThemeTranslationPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    protected function build(ContainerBuilder $container): void
    {
        // Themes aren't Symfony bundles, so the translator doesn't scan them. This pass
        // registers each theme's own translation catalogs (themes/<name>/translations/).
        $container->addCompilerPass(new ThemeTranslationPass($this->getProjectDir()));
    }
}
