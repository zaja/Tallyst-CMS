<?php

namespace App\Tests\Settings;

use App\Settings\SettingsEncryptor;
use PHPUnit\Framework\TestCase;

class SettingsEncryptorTest extends TestCase
{
    private function encryptor(): SettingsEncryptor
    {
        return new SettingsEncryptor(sodium_crypto_secretbox_keygen());
    }

    public function testRoundTrip(): void
    {
        $enc = $this->encryptor();
        $secret = 'hunter2-😀-#$%';

        $cipher = $enc->encrypt($secret);

        self::assertNotSame($secret, $cipher, 'stored value must not be plaintext');
        self::assertSame($secret, $enc->decrypt($cipher));
    }

    public function testNonceMakesCiphertextNonDeterministic(): void
    {
        $enc = $this->encryptor();

        self::assertNotSame($enc->encrypt('same'), $enc->encrypt('same'), 'fresh nonce per encryption');
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        $cipher = $this->encryptor()->encrypt('secret');
        $other = new SettingsEncryptor(sodium_crypto_secretbox_keygen());

        $this->expectException(\RuntimeException::class);
        $other->decrypt($cipher);
    }

    public function testInvalidKeyLengthThrowsClearError(): void
    {
        $bad = new SettingsEncryptor('too-short');

        $this->expectException(\LogicException::class);
        $bad->encrypt('x');
    }
}
