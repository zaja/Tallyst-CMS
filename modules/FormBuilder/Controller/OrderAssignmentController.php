<?php

namespace Tallyst\FormBuilder\Controller;

use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Repository\OrderRepository;

/**
 * Attaches an order to a customer account by hand.
 *
 * ⚠ THIS IS A RECOVERY PATH, NOT A CONVENIENCE, and it is why it shipped in the first version
 * rather than later. Two things bring an admin here, and the second is the heavier one:
 *
 *  1. The buyer mistyped their address at the payment provider, so the sale sits under an address
 *    that is nobody's and will never be claimed. One order, one confused customer.
 *  2. The buyer LOST ACCESS TO THEIR MAILBOX before changing the address on their account. With no
 *    password and no second factor, the e-mail link is the only credential there is — so their
 *    account is locked for good and every purchase in it is out of reach. This screen is the only
 *    way back, and without it the answer to that customer is "nothing can be done".
 *
 * Assigning does not touch the order's own record of the sale: the snapshot columns, the address it
 * was paid with, and the money all stay exactly as they were. Only ownership changes.
 */
#[Route('/admin/order-assignment', defaults: ['dashboardControllerFqcn' => 'App\Controller\Admin\DashboardController'])]
#[IsGranted('ROLE_ADMIN')]
class OrderAssignmentController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'form_builder_order_assignment', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->render('@FormBuilder/admin/order_assignment.html.twig', [
            'orders' => $this->orders->findUnassigned(),
            'q' => $q,
            // Only searched on demand: showing every account by default would be a list of the
            // shop's customers on a screen that exists to fix one order.
            'matches' => '' === $q ? [] : $this->customers->search($q),
        ]);
    }

    #[Route('/assign', name: 'form_builder_order_assign', methods: ['POST'])]
    public function assign(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('order_assign', (string) $request->request->get('_token'))) {
            // Without this, a link in an e-mail opened by a logged-in admin could move somebody's
            // purchase into a stranger's account.
            $this->addFlash('danger', $this->translator->trans('admin.flash.csrf', [], 'admin'));

            return $this->redirectToRoute('form_builder_order_assignment');
        }

        $order = $this->orders->find((int) $request->request->get('order'));
        $customer = $this->customers->find((int) $request->request->get('customer'));

        if (null === $order || null === $customer) {
            $this->addFlash('danger', $this->translator->trans('admin.order_assignment.flash.not_found', [], 'admin'));

            return $this->redirectToRoute('form_builder_order_assignment');
        }

        $order->setCustomer($customer);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('admin.order_assignment.flash.assigned', [
            '%order%' => (string) $order->getId(),
            '%email%' => $customer->getEmail(),
        ], 'admin'));

        return $this->redirectToRoute('form_builder_order_assignment');
    }
}
