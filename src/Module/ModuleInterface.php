<?php

namespace App\Module;

/**
 * Implemented by each optional module so it appears in the admin module registry.
 * A module is a lean Symfony bundle (see CLAUDE.md); this interface is only its
 * metadata/identity surface — routing, services and entities come from the bundle.
 */
interface ModuleInterface
{
    /** Machine name, unique (e.g. "form_builder"). */
    public function getName(): string;

    /** Human-readable label (e.g. "Form Builder"). */
    public function getLabel(): string;

    public function getVersion(): string;

    public function getDescription(): string;
}
