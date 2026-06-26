<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

/**
 * Shared admin-list polish for the CRUD controllers (v1.2.0): row actions shown inline instead of
 * hidden in the "⋮" dropdown, a "back to list" button on New/Edit, and an optional "preview" link
 * (opens the live front page in a new tab) for entities that have a public URL.
 *
 * Labels live in the `admin` domain (`admin.action.*`). Used by Core CRUDs and the Media module CRUD
 * (modules depend on Core, so the App trait is reachable).
 */
trait AdminCrudPolishTrait
{
    /** Show row actions as inline buttons instead of the collapsed "⋮" dropdown. */
    private function inlineRowActions(Crud $crud): Crud
    {
        return $crud->showEntityActionsInlined();
    }

    /** A "back to list" button on the New and Edit pages (EA has none by default). */
    private function addBackToListAction(Actions $actions): Actions
    {
        $back = Action::new('backToList', 'admin.action.back_to_list', 'fa fa-arrow-left')
            ->linkToCrudAction(Action::INDEX);

        return $actions
            ->add(Crud::PAGE_NEW, $back)
            ->add(Crud::PAGE_EDIT, $back);
    }

    /**
     * A "preview" link (opens the live page in a new tab) on the Index and Edit pages. Only for
     * entities with a public URL; $url builds it from the entity, $isLive optionally hides it
     * (e.g. unpublished drafts that would 404 on the front).
     *
     * @param callable(object): string    $url
     * @param null|callable(object): bool $isLive
     */
    private function addPreviewAction(Actions $actions, callable $url, ?callable $isLive = null): Actions
    {
        $preview = Action::new('preview', 'admin.action.preview', 'fa fa-up-right-from-square')
            ->linkToUrl($url)
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener']);

        if (null !== $isLive) {
            $preview = $preview->displayIf($isLive);
        }

        return $actions
            ->add(Crud::PAGE_INDEX, $preview)
            ->add(Crud::PAGE_EDIT, $preview);
    }
}
