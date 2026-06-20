<?php

namespace App\Module;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;

/**
 * Optional extension of ModuleInterface: a module implements this when it wants to
 * surface entries in the admin. The dashboard renders these under the "Moduli"
 * section (only for enabled modules). Modules without an admin UI just implement
 * the base ModuleInterface. This is the clean hook FormBuilder will use later.
 */
interface AdminModuleInterface extends ModuleInterface
{
    /**
     * EasyAdmin menu items to add under the admin "Moduli" section.
     *
     * @return iterable<MenuItemInterface>
     */
    public function getAdminMenuItems(): iterable;
}
