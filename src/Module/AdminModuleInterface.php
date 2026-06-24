<?php

namespace App\Module;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;

/**
 * Optional extension of ModuleInterface: a module implements this when it wants to surface entries
 * in the admin. Items are returned grouped BY SECTION KEY so the dashboard can place them in the
 * right Core section (e.g. Forme/Mediji under "Sadržaj", Narudžbe under "Prodaja") WITHOUT Core
 * referencing module controllers (which would break the dependency direction — modules depend on
 * Core, not the reverse). The module declares its own placement, like isCore().
 */
interface AdminModuleInterface extends ModuleInterface
{
    /** Section keys the dashboard knows how to place. */
    public const SECTION_CONTENT = 'content';
    public const SECTION_SALES = 'sales';

    /**
     * EasyAdmin menu items grouped by section key (e.g. self::SECTION_CONTENT). The dashboard
     * appends each group to the matching Core section; an unknown key is ignored.
     *
     * @return array<string, list<MenuItemInterface>>
     */
    public function getAdminMenuItems(): array;
}
