<?php

namespace App\Tests\FormBuilder;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\FormDeletionGuard;

/**
 * A form with ANY order can't be deleted. This once stopped the orders themselves from being
 * destroyed (`fb_order.form_id` was ON DELETE CASCADE); since migration Version20260807081500 they
 * survive on their own snapshots, and what the rule now protects is the link between a sale and the
 * form it came from — severed irreversibly by a delete.
 *
 * Pure decision logic (same shape as AdminLockoutGuardTest) — the
 * route-level enforcement is locked separately by tests/Functional/FormDeleteGuardTest.
 */
class FormDeletionGuardTest extends TestCase
{
    private function guard(int $orders, int $messages = 0): FormDeletionGuard
    {
        $orderRepo = $this->createStub(OrderRepository::class);
        $orderRepo->method('countForForm')->willReturn($orders);

        $submissionRepo = $this->createStub(FormSubmissionRepository::class);
        $submissionRepo->method('countForForm')->willReturn($messages);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new FormDeletionGuard($orderRepo, $submissionRepo, $translator);
    }

    /** A persisted form — the guard short-circuits to 0 for an unsaved one (no id to count by). */
    private function form(): FormDefinition
    {
        $form = new FormDefinition();
        $ref = new \ReflectionProperty(FormDefinition::class, 'id');
        $ref->setValue($form, 42);

        return $form;
    }

    public function testFormWithOrdersIsBlocked(): void
    {
        self::assertSame('admin.form.guard.has_orders', $this->guard(1)->blockDelete($this->form()));
    }

    /**
     * ANY status counts — a `pending` order (abandoned checkout) is still the trace of a payment
     * attempt. The guard asks the repository for a plain count, never a status-filtered one.
     */
    public function testASingleOrderIsEnoughToBlock(): void
    {
        self::assertNotNull($this->guard(1)->blockDelete($this->form()));
        self::assertNotNull($this->guard(37)->blockDelete($this->form()));
    }

    public function testFormWithoutOrdersIsAllowed(): void
    {
        self::assertNull($this->guard(0)->blockDelete($this->form()));
    }

    /** Messages alone never block — they only change the confirmation text. */
    public function testMessagesAloneDoNotBlock(): void
    {
        self::assertNull($this->guard(0, 12)->blockDelete($this->form()));
    }

    public function testCountsAreExposedForTheListAndConfirmation(): void
    {
        $guard = $this->guard(3, 12);
        self::assertSame(3, $guard->orderCount($this->form()));
        self::assertSame(12, $guard->messageCount($this->form()));
    }

    /** An unsaved form has no id to count by — must not hit the repository, must not block. */
    public function testUnsavedFormIsAllowedAndCountsZero(): void
    {
        $guard = $this->guard(99, 99);
        $unsaved = new FormDefinition();
        self::assertSame(0, $guard->orderCount($unsaved));
        self::assertSame(0, $guard->messageCount($unsaved));
        self::assertNull($guard->blockDelete($unsaved));
    }
}
