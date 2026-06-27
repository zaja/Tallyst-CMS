<?php

namespace App\Tests\Command;

use App\Entity\Category;
use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Locks the FLAG-based uninstaller (app:demo:seed --clear → clearDemo): it removes EXACTLY the
 * rows carrying is_demo=true — including a runtime Order placed through a demo form — while
 * content marked is_demo=false (real, or demo whose flag was removed) survives untouched. This is
 * the surgical-deletion guarantee the whole flag model exists for.
 */
class DemoSeedClearTest extends KernelTestCase
{
    /** @var int[] */
    private array $cleanup = [];

    public function testClearRemovesOnlyFlaggedRows(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));

        // Demo content (is_demo=true) — must be removed.
        $demoPage = $this->persist($em, (new Page('Demo '.$suffix, 'demo-clear-'.$suffix))->setIsDemo(true));
        $demoCat = $this->persist($em, (new Category('Demo cat '.$suffix, 'demo-cat-'.$suffix))->setIsDemo(true));
        $demoForm = $this->persist($em, (new FormDefinition())->setName('Demo form '.$suffix)->setSlug('demo-form-'.$suffix)->setIsDemo(true));
        // A runtime order placed through the demo form inherits the flag → must be removed too.
        $demoOrder = $this->persist($em, (new Order())->setForm($demoForm)->setAmountMinor(4900)->setIsDemo(true));

        // Real content (is_demo=false) — must survive.
        $realPage = $this->persist($em, (new Page('Real '.$suffix, 'real-clear-'.$suffix)));
        $realForm = $this->persist($em, (new FormDefinition())->setName('Real form '.$suffix)->setSlug('real-form-'.$suffix));
        $realOrder = $this->persist($em, (new Order())->setForm($realForm)->setAmountMinor(1000));

        $ids = [
            'demoPage' => $demoPage->getId(), 'demoCat' => $demoCat->getId(),
            'demoForm' => $demoForm->getId(), 'demoOrder' => $demoOrder->getId(),
            'realPage' => $realPage->getId(), 'realForm' => $realForm->getId(), 'realOrder' => $realOrder->getId(),
        ];
        $this->cleanup = [$ids['realPage'], $ids['realForm'], $ids['realOrder']];

        // Run the uninstall path.
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:demo:seed'));
        $tester->execute(['--clear' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $em->clear();

        // Demo rows gone.
        self::assertNull($em->find(Page::class, $ids['demoPage']), 'demo page must be deleted');
        self::assertNull($em->find(Category::class, $ids['demoCat']), 'demo category must be deleted');
        self::assertNull($em->find(FormDefinition::class, $ids['demoForm']), 'demo form must be deleted');
        self::assertNull($em->find(Order::class, $ids['demoOrder']), 'demo order (through a demo form) must be deleted');

        // Real rows survive.
        self::assertNotNull($em->find(Page::class, $ids['realPage']), 'real page must survive');
        self::assertNotNull($em->find(FormDefinition::class, $ids['realForm']), 'real form must survive');
        self::assertNotNull($em->find(Order::class, $ids['realOrder']), 'real order must survive');
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
