<?php

namespace App\Tests\Command;

use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Locks "make demo permanent" (app:demo:seed --unflag): it clears is_demo across all entities at once
 * (so a demo form and its demo orders become real TOGETHER), and — the whole point — the now-permanent
 * content then SURVIVES an uninstall (--clear) because there is no flag left for the uninstaller to match.
 */
class DemoSeedUnflagTest extends KernelTestCase
{
    /** @var int[] */
    private array $cleanup = [];

    public function testUnflagMakesContentPermanentAndSurvivesUninstall(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));

        $page = $this->persist($em, (new Page('Demo '.$suffix, 'demo-unflag-'.$suffix))->setIsDemo(true));
        $form = $this->persist($em, (new FormDefinition())->setName('Demo form '.$suffix)->setSlug('demo-unflag-form-'.$suffix)->setIsDemo(true));
        $order = $this->persist($em, (new Order())->setForm($form)->setAmountMinor(4900)->setIsDemo(true));
        $pageId = $page->getId();
        $formId = $form->getId();
        $orderId = $order->getId();
        $this->cleanup = [$pageId, $formId, $orderId];

        $application = new Application(self::$kernel);

        // 1) Unflag → every flag flips to false (form + its order become real together).
        $unflag = new CommandTester($application->find('app:demo:seed'));
        $unflag->execute(['--unflag' => true]);
        self::assertSame(Command::SUCCESS, $unflag->getStatusCode());

        $em->clear();
        self::assertFalse($em->find(Page::class, $pageId)->isDemo(), 'page flag cleared');
        self::assertFalse($em->find(FormDefinition::class, $formId)->isDemo(), 'form flag cleared');
        self::assertFalse($em->find(Order::class, $orderId)->isDemo(), 'order flag cleared (followed the form)');

        // 2) Uninstall → the now-permanent content survives (no flag to match).
        $clear = new CommandTester($application->find('app:demo:seed'));
        $clear->execute(['--clear' => true]);
        self::assertSame(Command::SUCCESS, $clear->getStatusCode());

        $em->clear();
        self::assertNotNull($em->find(Page::class, $pageId), 'unflagged page must survive uninstall');
        self::assertNotNull($em->find(FormDefinition::class, $formId), 'unflagged form must survive uninstall');
        self::assertNotNull($em->find(Order::class, $orderId), 'unflagged order must survive uninstall');
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persist(EntityManagerInterface $em, object $entity): object
    {
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    protected function tearDown(): void
    {
        if ([] !== $this->cleanup && self::$kernel !== null) {
            $conn = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            [$pageId, $formId, $orderId] = $this->cleanup;
            $conn->executeStatement('DELETE FROM fb_order WHERE id = ?', [$orderId]);
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$formId]);
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$pageId]);
            $this->cleanup = [];
        }

        parent::tearDown();
    }
}
