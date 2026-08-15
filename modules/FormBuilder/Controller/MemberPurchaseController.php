<?php

namespace Tallyst\FormBuilder\Controller;

use App\Entity\Member;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * One purchase, as the person who made it sees it.
 *
 * ⚠ WHY THIS PAGE EXISTS AT ALL: somebody who lost the confirmation e-mail has no other way back to
 * their licence key or invoice. That is the ONLY reason this screen is opened, so those two are what
 * it is built around — everything else on it is context.
 *
 * Lives in FormBuilder because FormBuilder owns orders. Core renders the account page and the blocks
 * on it without ever learning what an Order is, and that boundary holds here too: this controller is
 * the module's own, sitting on a Core-owned firewall path.
 *
 * ⚠ WHAT THIS PAGE DELIBERATELY DOES NOT SHOW — each of these is a DECISION, not an omission, and
 * `MemberPurchasePrivacyTest` is the wall that keeps them out:
 *  - payment identifiers (session id, payment intent, capture id) — internal plumbing, and a
 *    reference the buyer could quote at a provider who will not talk to them anyway;
 *  - the address and country the purchase was made from — we hold it for fraud and tax reasons, it
 *    tells the buyer nothing, and showing somebody their own IP invites questions we do not want;
 *  - what the provider settled, its fees, the split — that is the OWNER'S ledger. A buyer reading
 *    how much of their money the shop actually kept is a conversation nobody asked for;
 *  - a link into the provider's portal (Stripe, Dodo) — the buyer must not be handed off to an
 *    interface the site owner does not control and cannot support;
 *  - ⚠ NO cancel or refund button. A refund is a CONVERSATION, not an action: a button turns a
 *    frustrated buyer into a refund instead of into a solved problem. And for a Merchant-of-Record
 *    sale the refund is legally the provider's to make, so a button here would misrepresent who
 *    decides. The help line takes its place.
 */
class MemberPurchaseController extends AbstractController
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    /**
     * ⚠ SOMEBODY ELSE'S ORDER MUST ANSWER EXACTLY LIKE ONE THAT DOES NOT EXIST. Not "this is not
     * yours" — that phrasing confirms the order is real and belongs to somebody, which is the one
     * fact a stranger walking the ids is trying to learn. Both are a plain 404.
     */
    #[Route('/account/purchase/{id}', name: 'form_builder_member_purchase', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        /** @var Member $member */
        $member = $this->getUser();

        $order = $this->orders->find($id);
        if (null === $order || $order->getMember() !== $member) {
            throw $this->createNotFoundException();
        }

        return $this->render('@FormBuilder/member/purchase.html.twig', [
            'order' => $order,
            'fields' => $this->labelledFields($order->getSubmittedFields()),
        ]);
    }

    /**
     * Turns the stored field keys into something a person can read: `full_name` → "Full name".
     *
     * ⚠ HONEST LIMIT, worth knowing before "improving" this. The order snapshots the buyer's VALUES,
     * keyed by field key; the LABELS they actually saw at the till were never snapshotted and are not
     * recoverable. So this is a readable approximation, not the original wording.
     *
     * ⚠ It deliberately does NOT read the live form to get real labels. That would look better while
     * the form exists and change or vanish the moment the owner edits or deletes it — an order is a
     * historical record, and everything displayed on one comes from its own snapshot. A deleted form
     * would also silently empty this list, which is exactly the failure the snapshot was introduced
     * to end.
     *
     * @param array<string, string> $fields
     *
     * @return array<string, string>
     */
    private function labelledFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if ('' === trim($value)) {
                continue; // an empty answer is not worth a row
            }

            $label = ucfirst(trim(str_replace(['_', '-'], ' ', $key)));
            $out['' === $label ? $key : $label] = $value;
        }

        return $out;
    }
}
