<?php

namespace App\Tests\Theme;

use App\Entity\Theme;
use App\Repository\ThemeRepository;
use App\Theme\ThemeDeletionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stops the front-end from being bricked: the active theme and the only theme can't be deleted.
 */
class ThemeDeletionGuardTest extends TestCase
{
    private function guard(int $themeCount): ThemeDeletionGuard
    {
        $themes = $this->createStub(ThemeRepository::class);
        $themes->method('count')->willReturn($themeCount);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new ThemeDeletionGuard($themes, $translator);
    }

    private function theme(bool $active): Theme
    {
        return (new Theme())->setName('demo')->setActive($active);
    }

    public function testCannotDeleteActiveTheme(): void
    {
        // Active is blocked even when others exist.
        self::assertNotNull($this->guard(3)->blockDelete($this->theme(true)));
    }

    public function testCannotDeleteOnlyTheme(): void
    {
        self::assertNotNull($this->guard(1)->blockDelete($this->theme(false)));
    }

    public function testCanDeleteInactiveThemeWhenOthersRemain(): void
    {
        self::assertNull($this->guard(2)->blockDelete($this->theme(false)));
    }

    public function testCannotDeleteDefaultEvenWhenInactiveWithOthers(): void
    {
        $default = (new Theme())->setName('default')->setActive(false);
        self::assertNotNull($this->guard(3)->blockDelete($default), 'default is never deletable');
    }
}
