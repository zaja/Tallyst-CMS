<?php

namespace App\Tests\Readiness;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The wording the OWNER actually reads when the ceiling has been refusing requests.
 *
 * ⚠ WHY A TEST FOR A SENTENCE. The first live look at this warning said "stopped sending sign-in
 * links 1 times" — every automated check was green, because nothing asserted on the finished
 * sentence. A readiness panel is read by somebody deciding whether their shop is in trouble; broken
 * grammar there reads as a broken product, on exactly the screen that has to sound reliable.
 *
 * ⚠ Croatian needs THREE forms, not two: 1/21/31 take "put", 2–4 take "puta", 5+ take "puta". A
 * two-form catalogue silently renders the wrong one, which is why every boundary is pinned here.
 */
class MemberLoginFloodMessageTest extends KernelTestCase
{
    private const string KEY = 'admin.readiness.login_flood.detail';

    private function trans(int $count, string $locale): string
    {
        self::bootKernel();
        /** @var TranslatorInterface $translator */
        $translator = static::getContainer()->get(TranslatorInterface::class);

        return $translator->trans(self::KEY, [
            '%count%' => $count,
            '%since%' => '01.08.2026. 09:00',
            '%last%' => '12.08.2026. 14:39',
        ], 'admin', $locale);
    }

    /** ⚠ The exact case that shipped wrong: one refusal must not read "1 times". */
    public function testASingleRefusalReadsAsEnglish(): void
    {
        $text = $this->trans(1, 'en');

        self::assertStringNotContainsString('1 times', $text);
        self::assertStringContainsString('once', $text);
        self::assertStringNotContainsString('|', $text, 'the plural forms must be resolved, not printed');
    }

    public function testSeveralRefusalsReadAsEnglish(): void
    {
        $text = $this->trans(7, 'en');

        self::assertStringContainsString('7 times', $text);
        self::assertStringNotContainsString('|', $text);
    }

    /** 1 and 21 share Croatian's "one" form — "1 put", "21 put", never "21 puta". */
    public function testCroatianSingularForms(): void
    {
        self::assertStringContainsString('1 put ', $this->trans(1, 'hr'));
        self::assertStringContainsString('21 put ', $this->trans(21, 'hr'));
    }

    /** 2–4 take "puta", and so does 5+ — but they must resolve to a form, never to the raw string. */
    public function testCroatianPluralForms(): void
    {
        foreach ([2, 3, 4, 5, 11, 340] as $n) {
            $text = $this->trans($n, 'hr');
            self::assertStringContainsString($n.' puta', $text, 'count '.$n);
            self::assertStringNotContainsString('|', $text, 'count '.$n);
        }
    }

    /** Both languages must actually say something — a missing key renders as the key itself. */
    public function testNeitherLanguageFallsBackToTheKey(): void
    {
        self::assertStringNotContainsString('admin.readiness', $this->trans(3, 'en'));
        self::assertStringNotContainsString('admin.readiness', $this->trans(3, 'hr'));
    }
}
