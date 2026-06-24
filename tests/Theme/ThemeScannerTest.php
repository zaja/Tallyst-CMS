<?php

namespace App\Tests\Theme;

use App\Repository\ThemeRepository;
use App\Theme\ThemeResolver;
use App\Theme\ThemeScanner;
use PHPUnit\Framework\TestCase;

/**
 * Auto-detection over a fixture themes/ dir: only theme.yaml folders count; validity follows the
 * layout (own or via parent); a missing parent is flagged; thumbnails detected; never crashes.
 */
class ThemeScannerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/themescan_'.bin2hex(random_bytes(5));
        $themes = $this->tmp.'/themes';

        // default: valid (own layout) + thumbnail
        $this->writeTheme($themes, 'default', "name: default\nlabel: Default\nauthor: Tallyst\n", true);
        file_put_contents($themes.'/default/theme.png', 'x');
        // child: no own layout, parent default → valid via chain
        $this->writeTheme($themes, 'child', "name: child\nlabel: Child\nparent: default\n", false);
        // orphan: parent points at a missing theme → parentMissing + invalid
        $this->writeTheme($themes, 'orphan', "label: Orphan\nparent: ghost\n", false);
        // nolayout: yaml but no layout, no parent → invalid
        $this->writeTheme($themes, 'nolayout', "label: No Layout\n", false);
        // notheme: a folder WITHOUT theme.yaml → skipped
        mkdir($themes.'/notheme', 0777, true);
        file_put_contents($themes.'/notheme/readme.txt', 'x');
    }

    private function writeTheme(string $themes, string $name, string $yaml, bool $withLayout): void
    {
        mkdir($themes.'/'.$name.'/templates', 0777, true);
        file_put_contents($themes.'/'.$name.'/theme.yaml', $yaml);
        if ($withLayout) {
            file_put_contents($themes.'/'.$name.'/templates/layout.html.twig', '<html></html>');
        }
    }

    private function scanner(): ThemeScanner
    {
        $repo = $this->createStub(ThemeRepository::class);
        $repo->method('findActive')->willReturn(null); // → resolver active = default

        return new ThemeScanner(new ThemeResolver($repo, $this->tmp, 'default'));
    }

    /** @return array<string, array<string, mixed>> keyed by name */
    private function scanByName(): array
    {
        $out = [];
        foreach ($this->scanner()->scan() as $t) {
            $out[$t['name']] = $t;
        }

        return $out;
    }

    public function testSkipsFoldersWithoutThemeYaml(): void
    {
        self::assertArrayNotHasKey('notheme', $this->scanByName());
    }

    public function testDefaultIsValidActiveWithThumbnail(): void
    {
        $d = $this->scanByName()['default'];
        self::assertTrue($d['valid']);
        self::assertTrue($d['active']);
        self::assertTrue($d['isDefault']);
        self::assertTrue($d['hasThumbnail']);
    }

    public function testChildIsValidViaParentChain(): void
    {
        $c = $this->scanByName()['child'];
        self::assertTrue($c['valid'], 'inherits layout from parent');
        self::assertSame('default', $c['parent']);
        self::assertFalse($c['parentMissing']);
    }

    public function testMissingParentFlaggedAndInvalid(): void
    {
        $o = $this->scanByName()['orphan'];
        self::assertTrue($o['parentMissing']);
        self::assertFalse($o['valid']);
    }

    public function testNoLayoutIsInvalid(): void
    {
        $n = $this->scanByName()['nolayout'];
        self::assertFalse($n['valid']);
        self::assertFalse($n['parentMissing']);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ('.' === $e || '..' === $e) {
                continue;
            }
            $p = $dir.'/'.$e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
