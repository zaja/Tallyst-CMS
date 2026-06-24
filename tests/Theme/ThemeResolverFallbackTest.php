<?php

namespace App\Tests\Theme;

use App\Entity\Theme;
use App\Repository\ThemeRepository;
use App\Theme\ThemeResolver;
use PHPUnit\Framework\TestCase;

/**
 * The "never break the front" safety net: if the active theme's folder is gone/broken (e.g. FTP-deleted
 * while active), ThemeResolver falls back to the (git-tracked, always-usable) default.
 */
class ThemeResolverFallbackTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/themeres_'.bin2hex(random_bytes(5));
        mkdir($this->tmp.'/themes/default/templates', 0777, true);
        file_put_contents($this->tmp.'/themes/default/theme.yaml', "name: default\n");
        file_put_contents($this->tmp.'/themes/default/templates/layout.html.twig', '<html></html>');
    }

    private function resolver(?Theme $active): ThemeResolver
    {
        $repo = $this->createStub(ThemeRepository::class);
        $repo->method('findActive')->willReturn($active);

        return new ThemeResolver($repo, $this->tmp, 'default');
    }

    public function testFallsBackToDefaultWhenActiveThemeFolderMissing(): void
    {
        $ghost = (new Theme('ghost'))->setActive(true); // no themes/ghost folder exists
        self::assertSame('default', $this->resolver($ghost)->getActiveThemeName());
    }

    public function testUsesActiveThemeWhenItExists(): void
    {
        $default = (new Theme('default'))->setActive(true);
        self::assertSame('default', $this->resolver($default)->getActiveThemeName());
    }

    public function testNoActiveRowUsesDefault(): void
    {
        self::assertSame('default', $this->resolver(null)->getActiveThemeName());
    }

    protected function tearDown(): void
    {
        foreach (['themes/default/templates/layout.html.twig', 'themes/default/theme.yaml'] as $f) {
            @unlink($this->tmp.'/'.$f);
        }
        @rmdir($this->tmp.'/themes/default/templates');
        @rmdir($this->tmp.'/themes/default');
        @rmdir($this->tmp.'/themes');
        @rmdir($this->tmp);
    }
}
