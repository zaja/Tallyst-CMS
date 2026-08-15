<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    /**
     * Make the INDEX (list) Edit + Delete row actions ICON-ONLY (a FA icon, no text label) with a
     * `title` tooltip + `aria-label` (a11y — EA adds neither for a label-less action). Compact +
     * consistent across all lists. Only PAGE_INDEX is touched, so the detail/edit page actions keep
     * their labels. `update()` STACKS (reads the current action, applies the callable), so a
     * controller may add its own further `update()` on Delete (e.g. User's lockout displayIf).
     */
    private function iconOnlyRowActions(Actions $actions, TranslatorInterface $translator): Actions
    {
        $edit = $translator->trans('admin.action.edit', [], 'admin');
        $delete = $translator->trans('admin.action.delete', [], 'admin');

        return $actions
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $a): Action => $a
                ->setIcon('fa-solid fa-pen')->setLabel(false)->asTextLink()
                ->setHtmlAttributes(['title' => $edit, 'aria-label' => $edit]))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $a): Action => $a
                ->setIcon('fa-solid fa-trash')->setLabel(false)
                ->setHtmlAttributes(['title' => $delete, 'aria-label' => $delete]));
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
     * Index = ICON-ONLY (fa-eye + title/aria), consistent with the other row actions; the Edit page
     * keeps the LABEL (its action bar sits next to labelled Save/Back buttons).
     *
     * @param callable(object): string    $url
     * @param null|callable(object): bool $isLive
     */
    private function addPreviewAction(Actions $actions, TranslatorInterface $translator, callable $url, ?callable $isLive = null): Actions
    {
        $title = $translator->trans('admin.action.preview', [], 'admin');

        $indexPreview = Action::new('preview', 'admin.action.preview', 'fa-solid fa-eye')
            ->setLabel(false)
            ->asTextLink()
            ->linkToUrl($url)
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener', 'title' => $title, 'aria-label' => $title]);

        $editPreview = Action::new('preview', 'admin.action.preview', 'fa-solid fa-eye')
            ->linkToUrl($url)
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener']);

        if (null !== $isLive) {
            $indexPreview = $indexPreview->displayIf($isLive);
            $editPreview = $editPreview->displayIf($isLive);
        }

        return $actions
            ->add(Crud::PAGE_INDEX, $indexPreview)
            ->add(Crud::PAGE_EDIT, $editPreview);
    }

    /**
     * A ChoiceFilter whose CHOICE LABELS are translated in the `admin` domain.
     *
     * ⚠ USE THIS INSTEAD OF ChoiceFilter::new() WHENEVER THE CHOICE LABELS ARE TRANSLATION KEYS.
     * EasyAdmin's own `ChoiceFilter::new()` hardcodes `translation_domain => 'EasyAdminBundle'` on
     * the filter form, so an `admin.*` key handed to it is looked up in EA's catalogue, is not found,
     * and is printed VERBATIM — the filter dropdown shows "admin.order.status.paid" instead of
     * "Awaiting delivery". Nothing errors and the list itself keeps rendering its badges correctly,
     * which is why this survived unnoticed: the damage is only visible inside the filter panel.
     *
     * ⚠ It sets the domain on the VALUE field only (`value_type_options`), never on the filter as a
     * whole. The filter also renders EA's own comparison operators ("equals", "not equals") from the
     * EasyAdminBundle catalogue — switching the whole form to `admin` would fix our labels and break
     * those instead.
     *
     * @param array<string, mixed> $choices label key => stored value
     */
    private function translatedChoiceFilter(string $property, string $label, array $choices): ChoiceFilter
    {
        return ChoiceFilter::new($property, $label)
            ->setChoices($choices)
            ->setFormTypeOption('value_type_options.choice_translation_domain', 'admin');
    }
}
