<?php

namespace App\Tests\Command;

use App\Command\Disable2faCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CLI escape hatch clears a user's 2FA so they can log in with the password alone.
 */
class Disable2faCommandTest extends TestCase
{
    public function testClearsTwoFactorForKnownUser(): void
    {
        $user = (new User('locked@test.local'))
            ->setTotpSecret('ABCDEFGHIJKLMNOP')
            ->setTotpEnabled(true)
            ->setBackupCodesFromPlain(['ABCDE12345']);

        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $tester = new CommandTester(new Disable2faCommand($em, $users));
        $tester->execute(['email' => 'locked@test.local']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNull($user->getTotpSecret());
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getBackupCodes());
    }

    public function testFailsForUnknownUser(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $tester = new CommandTester(new Disable2faCommand($em, $users));
        $tester->execute(['email' => 'nobody@test.local']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
