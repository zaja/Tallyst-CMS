<?php

namespace App\Tests\Command;

use App\Command\CreateUserCommand;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateUserCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester(new CreateUserCommand(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserRepository::class),
            $this->createStub(UserPasswordHasherInterface::class),
        ));
    }

    public function testRejectsAnUnknownRoleBeforeAnyPrompt(): void
    {
        $tester = $this->tester();
        $tester->execute(['email' => 'editor@test.local', '--role' => 'ROLE_SUPERHERO']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Invalid role', $tester->getDisplay());
    }

    public function testRejectsAnInvalidEmail(): void
    {
        $tester = $this->tester();
        $tester->execute(['email' => 'not-an-email', '--role' => 'ROLE_EDITOR']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
