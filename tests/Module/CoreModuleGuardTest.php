<?php

namespace App\Tests\Module;

use App\Module\ModuleRegistry;
use App\Module\ModuleStateManager;
use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;
use Tallyst\FormBuilder\FormBuilderModule;
use Tallyst\Media\MediaModule;

/**
 * Core modules (FormBuilder, Media) declare themselves core and can't be disabled — the state
 * manager treats them as always-enabled, so a stored '0' (legacy/forged) self-heals and they can
 * never be stuck hidden from the admin menu / editor.
 */
class CoreModuleGuardTest extends TestCase
{
    public function testCoreModulesDeclareThemselvesCore(): void
    {
        self::assertTrue((new FormBuilderModule())->isCore());
        self::assertTrue((new MediaModule())->isCore());
    }

    public function testCoreIsAlwaysEnabledEvenWhenSettingSaysDisabled(): void
    {
        $settings = $this->createStub(SettingRepository::class);
        $settings->method('get')->willReturn('0'); // pretend everything was disabled in the DB
        $registry = new ModuleRegistry([new FormBuilderModule(), new MediaModule()]);
        $state = new ModuleStateManager($settings, $registry);

        self::assertTrue($state->isEnabled('form_builder'), 'core ignores the stored flag');
        self::assertTrue($state->isEnabled('media'), 'core ignores the stored flag');
    }

    public function testNonCoreRespectsTheStoredFlag(): void
    {
        $settings = $this->createStub(SettingRepository::class);
        $settings->method('get')->willReturn('0');
        $state = new ModuleStateManager($settings, new ModuleRegistry([]));

        self::assertFalse($state->isEnabled('some_optional_module'));
    }
}
