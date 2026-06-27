<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;

/**
 * Locks the runtime is_demo propagation: a submission through a DEMO form inherits is_demo=true
 * (so the uninstaller removes it), while a submission through a REAL form stays is_demo=false.
 * The flag is DERIVED from form.isDemo() in FormSubmitController, never hardcoded — so real
 * submissions/orders are never accidentally flagged demo.
 */
class FormSubmitDemoFlagTest extends WebTestCase
{
    /** @var int[] */
    private array $formIds = [];
    /** @var int[] */
    private array $pageIds = [];

    public function testSubmissionInheritsDemoFlagFromForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Demo form → submission flagged demo.
        [$demoFormId, $demoSlug] = $this->seedFormAndPage($em, true);
        $this->submit($client, $demoSlug);
        self::assertTrue($this->latestSubmissionIsDemo($em, $demoFormId), 'submission through a demo form must be is_demo=true');

        // Real form → submission NOT flagged demo (the derivation must not flag everything).
        [$realFormId, $realSlug] = $this->seedFormAndPage($em, false);
        $this->submit($client, $realSlug);
        self::assertFalse($this->latestSubmissionIsDemo($em, $realFormId), 'submission through a real form must stay is_demo=false');
    }

    private function submit($client, string $slug): void
    {
        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful('form page renders');
        $form = $crawler->filter('button.fb-submit')->form(['name' => 'Test', 'email' => 'test@example.com']);
        $client->submit($form);
    }

    /**
     * @return array{0: int, 1: string} [formId, pageSlug]
     */
    private function seedFormAndPage(EntityManagerInterface $em, bool $isDemo): array
    {
        $suffix = bin2hex(random_bytes(5));

        $form = (new FormDefinition())
            ->setName('Demo flag '.$suffix)
            ->setSlug('demo-flag-'.$suffix)
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setIsDemo($isDemo);
        $form->addField((new FormField())->setKey('name')->setLabel('Name')->setType(FormField::TYPE_TEXT)->setRequired(true)->setPosition(0));
        $form->addField((new FormField())->setKey('email')->setLabel('Email')->setType(FormField::TYPE_EMAIL)->setRequired(true)->setPosition(1));
        $em->persist($form);
        $em->flush();
        $formId = $form->getId();
        $this->formIds[] = $formId;

        $slug = 'fb-demo-flag-'.$suffix;
        $page = (new Page('FB demo flag '.$suffix, $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('[form id='.$formId.']');
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        return [$formId, $slug];
    }

    private function latestSubmissionIsDemo(EntityManagerInterface $em, int $formId): bool
    {
        $em->clear();
        /** @var FormSubmission|null $submission */
        $submission = $em->getRepository(FormSubmission::class)
            ->findOneBy(['form' => $formId], ['id' => 'DESC']);
        self::assertNotNull($submission, 'a submission must have been stored');

        return $submission->isDemo();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->formIds) {
            /** @var Connection $conn */
            $conn = static::getContainer()->get(Connection::class);
            foreach ($this->formIds as $formId) {
                $conn->executeStatement('DELETE FROM fb_submission WHERE form_id = ?', [$formId]);
                $conn->executeStatement('DELETE FROM fb_field WHERE form_id = ?', [$formId]);
                $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$formId]);
            }
            foreach ($this->pageIds as $pageId) {
                $conn->executeStatement('DELETE FROM page WHERE id = ?', [$pageId]);
            }
            $this->formIds = $this->pageIds = [];
        }

        parent::tearDown();
    }
}
