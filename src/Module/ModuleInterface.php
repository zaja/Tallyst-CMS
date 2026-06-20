<?php

namespace App\Module;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by each optional module so it appears in the admin module registry.
 * A module is a lean Symfony bundle (see CLAUDE.md); this interface is only its
 * metadata/identity surface — routing, services and entities come from the bundle.
 *
 * Tagging lives on the interface (not in services.yaml _instanceof) so that
 * modules defined in their own bundles are auto-tagged, not just App\ services.
 */
#[AutoconfigureTag('app.module')]
interface ModuleInterface
{
    /** Machine name, unique (e.g. "form_builder"). */
    public function getName(): string;

    /** Human-readable label (e.g. "Form Builder"). */
    public function getLabel(): string;

    public function getVersion(): string;

    public function getDescription(): string;
}
