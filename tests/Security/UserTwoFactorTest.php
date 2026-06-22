<?php

namespace App\Tests\Security;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the User 2FA model: confirm-before-activate (a stored-but-unconfirmed
 * secret is inert), the TOTP configuration, and backup codes (hashed at rest, one-time).
 */
class UserTwoFactorTest extends TestCase
{
    public function testNotEnabledUntilSecretAndConfirmation(): void
    {
        $user = new User('a@test.local');
        self::assertFalse($user->isTotpAuthenticationEnabled(), 'no secret → off (existing users log in normally)');

        $user->setTotpSecret('ABCDEFGHIJKLMNOP');
        self::assertFalse($user->isTotpAuthenticationEnabled(), 'secret stored but unconfirmed → still inert');

        $user->setTotpEnabled(true);
        self::assertTrue($user->isTotpAuthenticationEnabled(), 'secret + confirmed → active');
    }

    public function testTotpConfigurationFollowsSecret(): void
    {
        $user = new User('a@test.local');
        self::assertNull($user->getTotpAuthenticationConfiguration(), 'no config without a secret');

        $user->setTotpSecret('ABCDEFGHIJKLMNOP');
        $config = $user->getTotpAuthenticationConfiguration();
        self::assertNotNull($config);
        self::assertSame('ABCDEFGHIJKLMNOP', $config->getSecret());
        self::assertSame(6, $config->getDigits());
        self::assertSame(30, $config->getPeriod());
    }

    public function testTotpUsernameIsEmail(): void
    {
        self::assertSame('user@test.local', (new User('user@test.local'))->getTotpAuthenticationUsername());
    }

    public function testBackupCodesAreHashedAndOneTime(): void
    {
        $user = new User('a@test.local');
        $user->setBackupCodesFromPlain(['ABCDE12345', 'FGHJK67890']);

        // Stored hashed — the plaintext never sits in the column.
        self::assertNotContains('ABCDE12345', $user->getBackupCodes() ?? []);

        self::assertTrue($user->isBackupCode('ABCDE12345'));
        self::assertFalse($user->isBackupCode('not-a-code'));

        $user->invalidateBackupCode('ABCDE12345');
        self::assertFalse($user->isBackupCode('ABCDE12345'), 'used code cannot be reused');
        self::assertTrue($user->isBackupCode('FGHJK67890'), 'other codes remain valid');
    }
}
