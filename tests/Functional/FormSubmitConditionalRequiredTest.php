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
 * End-to-end lock for the conditional-required behaviour: the real HTTP submit path
 * (controller → SubmissionValidator → visibleKeys → response), which the unit test can't
 * reach. A required field hidden by its condition must let the form submit; the same field
 * shown but empty must block it.
 */
class FormSubmitConditionalRequiredTest extends WebTestCase
{
    private ?int $formId = null;
    private ?int $pageId = null;

    public function testHiddenRequiredSubmitsButVisibleRequiredEmptyIsBlocked(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $slug = $this->seedFormAndPage($em);

        // 1) a != yes → b (required) is hidden → submit succeeds, one submission stored.
        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful('form page renders');
        $form = $crawler->filter('button.fb-submit')->form(['a' => 'no', 'b' => '']);
        $client->submit($form);
        self::assertSame(1, $this->submissionCount($em), 'hidden required field must not block submit');

        // 2) a == yes → b (required) is visible but empty → blocked, no new submission.
        $crawler = $client->request('GET', '/'.$slug);
        $form = $crawler->filter('button.fb-submit')->form(['a' => 'yes', 'b' => '']);
        $client->submit($form);
        self::assertSame(1, $this->submissionCount($em), 'visible empty required field must block submit');
    }

    private function seedFormAndPage(EntityManagerInterface $em): string
    {
        $suffix = bin2hex(random_bytes(5));

        $form = (new FormDefinition())
            ->setName('Cond test '.$suffix)
            ->setSlug('cond-test-'.$suffix)
            ->setStatus(FormDefinition::STATUS_PUBLISHED);
        $form->addField((new FormField())->setKey('a')->setLabel('A')->setType(FormField::TYPE_TEXT)->setRequired(false)->setPosition(0));
        $form->addField((new FormField())->setKey('b')->setLabel('B')->setType(FormField::TYPE_TEXT)->setRequired(true)
            ->setPosition(1)
            ->setConditions(['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'a', 'operator' => 'equals', 'value' => 'yes']]]));
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $slug = 'fb-cond-'.$suffix;
        $page = (new Page('FB cond '.$suffix, $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('[form id='.$form->getId().']');
        $em->persist($page);
        $em->flush();
        $this->pageId = $page->getId();

        return $slug;
    }

    private function submissionCount(EntityManagerInterface $em): int
    {
        return (int) $em->getRepository(FormSubmission::class)->count(['form' => $this->formId]);
    }

    protected function tearDown(): void
    {
        if (null !== $this->formId) {
            /** @var Connection $conn */
            $conn = static::getContainer()->get(Connection::class);
            $conn->executeStatement('DELETE FROM fb_submission WHERE form_id = ?', [$this->formId]);
            $conn->executeStatement('DELETE FROM fb_field WHERE form_id = ?', [$this->formId]);
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$this->formId]);
            if (null !== $this->pageId) {
                $conn->executeStatement('DELETE FROM page WHERE id = ?', [$this->pageId]);
            }
            $this->formId = $this->pageId = null;
        }

        parent::tearDown();
    }
}
