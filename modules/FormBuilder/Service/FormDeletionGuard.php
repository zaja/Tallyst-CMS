<?php

namespace Tallyst\FormBuilder\Service;

use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Server-side guard that stops a form's ORDER HISTORY from being destroyed by deleting the form.
 * Pure decision logic (like AdminLockoutGuard): returns a human message when the delete must be
 * blocked, or null when it's allowed.
 *
 * WHY this exists: `fb_order.form_id` is `ON DELETE CASCADE` (migration Version20260620213521),
 * so deleting a form makes the DATABASE silently delete every order placed through it — financial
 * records gone, with no PHP event and no trace. The recommended alternative is offered in the
 * message: set the form to DRAFT to take it off the site while keeping its history.
 *
 * ⚠ THE GUARD BELONGS IN THE CONTROLLER, NEVER IN THE REPOSITORY — do NOT "move the check deeper
 * for safety". `FormDefinitionRepository::remove()` MUST stay unguarded because the demo
 * uninstaller (`app:demo:seed --clear` → DemoSeedCommand::clearDemo) deletes flagged demo content
 * directly through the entity manager, bypassing the controller entirely; a guard down there would
 * break the uninstaller's ability to remove a demo form that has demo orders. The guard protects
 * the ADMIN ACTION, not the persistence layer.
 */
class FormDeletionGuard
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly FormSubmissionRepository $submissions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return string|null block message (admin domain), or null if the delete is allowed
     */
    public function blockDelete(FormDefinition $form): ?string
    {
        // ANY order blocks, regardless of status — a `pending` order is still the trace of a payment
        // attempt, and "this form has orders → it can't be deleted" is a rule the site owner can
        // actually hold in their head (a status-dependent rule is not).
        $orders = $this->orderCount($form);
        if ($orders > 0) {
            return $this->translator->trans('admin.form.guard.has_orders', ['%count%' => $orders], 'admin');
        }

        return null;
    }

    /** Orders placed through this form (any status). 0 for an unsaved form. */
    public function orderCount(FormDefinition $form): int
    {
        return null === $form->getId() ? 0 : $this->orders->countForForm($form);
    }

    /** Messages received through this form — named exactly in the delete confirmation. */
    public function messageCount(FormDefinition $form): int
    {
        return null === $form->getId() ? 0 : $this->submissions->countForForm($form);
    }
}
