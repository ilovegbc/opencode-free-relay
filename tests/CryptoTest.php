<?php

declare(strict_types=1);

namespace Relay\Tests;

use Relay\Support\Crypto;

final class CryptoTest extends TestCase
{
    public function run(): void
    {
        $key = Crypto::apiKey();
        $this->assertTrue(str_starts_with($key, 'sk-oc-'), 'API key prefix');
        $this->assertTrue(strlen($key) > 30, 'API key length');
        $this->assertSame(Crypto::hashApiKey($key), Crypto::hashApiKey($key), 'Hash is deterministic');
        $this->assertSame(64, strlen(Crypto::hashApiKey($key)), 'SHA-256 hex length');
        $this->assertNotSame(Crypto::hashApiKey($key), Crypto::hashApiKey($key . 'x'), 'Hash changes with key');

        $password = 'correct horse battery staple';
        $hash = Crypto::passwordHash($password);
        $this->assertTrue(Crypto::passwordVerify($password, $hash), 'Password verifies');
        $this->assertFalse(Crypto::passwordVerify('wrong', $hash), 'Wrong password rejected');

        $invite = Crypto::inviteCode();
        $this->assertTrue(str_starts_with($invite, 'inv_'), 'Invite prefix');
        $this->assertSame(Crypto::hashOpaque($invite, 'invite'), Crypto::hashOpaque($invite, 'invite'), 'Opaque hash deterministic');
        $this->assertNotSame(Crypto::hashOpaque($invite, 'invite'), Crypto::hashOpaque($invite, 'redemption'), 'Purpose separation');

        $redeem = Crypto::redemptionCode();
        $this->assertTrue(str_starts_with($redeem, 'rdm_'), 'Redemption prefix');
    }
}
