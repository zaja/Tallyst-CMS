<?php

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * Every Stimulus controller the PUBLIC templates ask for must be registered in the front bootstrap.
 *
 * ⚠ WHY THIS EXISTS. Splitting the front and admin JavaScript is an operation in which whatever used
 * to be SHARED disappears silently. On 2026-07-05 the front stopped loading the admin bundle (a real
 * and worthwhile saving, ~118 KiB), and `formbuilder--conditions` went with it — it was registered
 * only in the admin bootstrap, while the public form template still asked for it. Conditional fields
 * stopped working on every public form and stayed broken for six weeks.
 *
 * ⚠ NO PHP TEST COULD HAVE CAUGHT IT, because the SERVER was right the whole time: it evaluated the
 * conditions correctly, refused to require a hidden field and dropped its value. The damage was
 * entirely on the visitor's side — a question they could not answer and could not skip. Green server
 * tests plus a controller that is still referenced somewhere in the codebase reads exactly like a
 * healthy feature.
 *
 * ⚠ IT READS THE TEMPLATES RATHER THAN LISTING CONTROLLERS. A list would only ever contain the ones
 * whoever wrote it remembered — the same root cause as the raw-translation-key escapes. A controller
 * added to a theme next year is covered without anybody thinking about it.
 */
class FrontStimulusRegistrationTest extends TestCase
{
    private const string BOOTSTRAP = 'assets/front_bootstrap.js';

    private function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Templates that can be rendered for a visitor: themes, and any module or app template that is
     * not under an `admin/` folder or the EasyAdmin layout override.
     *
     * @return list<string>
     */
    private function frontTemplates(): array
    {
        $files = [];
        foreach (['themes', 'modules', 'templates'] as $dir) {
            $path = $this->projectDir().'/'.$dir;
            if (!is_dir($path)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }

                $relative = str_replace($this->projectDir().'/', '', str_replace('\\', '/', $file->getPathname()));
                if (str_contains($relative, '/admin/') || str_contains($relative, 'bundles/EasyAdmin')) {
                    continue;
                }

                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * Controller identifier => the templates that ask for it, from both `data-controller` and the
     * `identifier#method` half of `data-action`.
     *
     * @return array<string, list<string>>
     */
    private function controllersUsedOnTheFront(): array
    {
        $used = [];
        foreach ($this->frontTemplates() as $relative) {
            $source = (string) file_get_contents($this->projectDir().'/'.$relative);

            if (preg_match_all('/data-controller="([^"]+)"/', $source, $matches)) {
                foreach ($matches[1] as $value) {
                    foreach (preg_split('/\s+/', trim($value)) ?: [] as $identifier) {
                        if ('' !== $identifier) {
                            $used[$identifier][] = $relative;
                        }
                    }
                }
            }

            if (preg_match_all('/data-action="([^"]+)"/', $source, $matches)) {
                foreach ($matches[1] as $value) {
                    if (preg_match_all('/([a-z0-9_-]+(?:--[a-z0-9_-]+)?)#/i', $value, $actions)) {
                        foreach ($actions[1] as $identifier) {
                            $used[$identifier][] = $relative;
                        }
                    }
                }
            }
        }

        return $used;
    }

    /** @return list<string> */
    private function registeredInFrontBootstrap(): array
    {
        $source = (string) file_get_contents($this->projectDir().'/'.self::BOOTSTRAP);
        preg_match_all("/app\.register\('([^']+)'/", $source, $matches);

        return $matches[1];
    }

    /** ⚠ THE ONE THAT CLOSES IT: asked for on the front ⇒ registered on the front. */
    public function testEveryControllerTheFrontAsksForIsRegisteredThere(): void
    {
        $used = $this->controllersUsedOnTheFront();
        self::assertNotEmpty($used, 'the template scan came back empty — it is measuring nothing');

        $registered = $this->registeredInFrontBootstrap();
        self::assertNotEmpty($registered, 'the bootstrap scan came back empty — it is measuring nothing');

        $missing = [];
        foreach ($used as $identifier => $templates) {
            if (!\in_array($identifier, $registered, true)) {
                $missing[] = \sprintf('%s (asked for by %s)', $identifier, implode(', ', array_unique($templates)));
            }
        }

        self::assertSame(
            [],
            $missing,
            \sprintf(
                "A public template asks for a Stimulus controller the front bootstrap does not register:\n  %s\n\n".
                "Nothing fails at runtime — the feature just silently stops working in the browser, and the ".
                "server keeps behaving correctly, so no other test can see it.\nRegister it in %s (front ".
                "controllers only, and only if it pulls no admin dependencies), then run asset-map:compile.",
                implode("\n  ", $missing),
                self::BOOTSTRAP,
            ),
        );
    }

    /**
     * ⚠ AND THE SPLIT MUST STAY A SPLIT. The saving that caused this bug is real and worth keeping:
     * the public site must never pull the editor bundle. A front controller may only bring
     * dependencies that are already there.
     */
    public function testTheFrontBootstrapPullsNothingFromTheAdminChain(): void
    {
        $source = (string) file_get_contents($this->projectDir().'/'.self::BOOTSTRAP);

        foreach (['tiptap', 'chart.js', 'filepond', 'stimulus_bootstrap', 'prosemirror'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                preg_replace('#^\s*\*.*$#m', '', $source) ?? $source,
                'the front bundle must not reach into the admin/editor chain',
            );
        }
    }
}
