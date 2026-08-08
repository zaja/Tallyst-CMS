<?php

namespace Tallyst\FormBuilder\Service;

use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Server-side guard that keeps a form with sales from being deleted out from under its orders.
 * Pure decision logic (like AdminLockoutGuard): returns a human message when the delete must be
 * blocked, or null when it's allowed.
 *
 * WHY this exists — ⚠ THE REASON CHANGED, the guard did not. It was written when
 * `fb_order.form_id` was `ON DELETE CASCADE`, so deleting a form made the DATABASE silently delete
 * every order placed through it: financial records gone, no PHP event, no trace. Since migration
 * Version20260807081500 that column is nullable + `ON DELETE SET NULL`, and an order carries its own
 * snapshots (product name, buyer's submitted data, MoR flag), so deleting a form no longer destroys
 * anything — the orders survive intact and keep reporting what they sold.
 *
 * So this is NO LONGER protection against data loss. What it still protects is the LINK between an
 * order and the form it came from, and the admin's own record-keeping: once the form is gone that
 * link is severed for good, the order's origin reads "—", and the form can no longer be inspected to
 * see what was actually being sold. That is a decision the site owner should make deliberately, not
 * discover afterwards — so the message still offers the better move: set the form to DRAFT, which
 * takes it off the site while keeping both the history and the link.
 *
 * ⚠ THE GUARD BELONGS IN THE CONTROLLER, NEVER IN THE REPOSITORY — do NOT "move the check deeper
 * for safety". `FormDefinitionRepository::remove()` MUST stay unguarded because the demo
 * uninstaller (`app:demo:seed --clear` → DemoSeedCommand::clearDemo) deletes flagged demo content
 * directly through the entity manager, bypassing the controller entirely; a guard down there would
 * break the uninstaller's ability to remove a demo form that has demo orders. The guard protects
 * the ADMIN ACTION, not the persistence layer.
 *
 * ⚠ THIS GUARD COUNTS ORDERS ONLY. A `messageCount()` was removed here (with its injected
 * FormSubmissionRepository) for the same reason ThemeDeletionGuard was deleted: nothing called it,
 * while two green unit tests read as proof that something was protected. Its docblock even claimed
 * it was "named exactly in the delete confirmation" — it was not. The forms list and the delete
 * confirmation get their message counts from `FormSubmissionRepository::countByFormIds()`, one bulk
 * query in FormBuilderController::index, and always did. Messages have never blocked a delete
 * (testFormWithMessagesButNoOrdersIsDeleted locks that), so the guard has no reason to count them.
 */
class FormDeletionGuard
{
    public function __construct(
        private readonly OrderRepository $orders,
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
}
