<?php

declare(strict_types=1);

namespace Relay\Support;

use Relay\App;

final class Crypto
{
    public static function appKey(): string
    {
        $key = (string) App::config('app.key', '');
        if ($key === '' || str_contains($key, 'change-me')) {
            throw new \RuntimeException('APP_KEY is not configured. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        return $key;
    }

    public static function apiKey(): string
    {
        return 'sk-oc-' . b64url(random_bytes(30));
    }

    public static function hashApiKey(string $key): string
    {
        return hash_hmac('sha256', trim($key), self::appKey());
    }

    public static function keyPrefix(string $key): string
    {
        return substr($key, 0, 12);
    }

    public static function passwordHash(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;
        $hash = password_hash($password, $algo);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Could not hash password.');
        }
        return $hash;
    }

    public static function passwordVerify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function inviteCode(): string
    {
        return 'inv_' . b64url(random_bytes(12));
    }

    public static function redemptionCode(): string
    {
        return 'rdm_' . b64url(random_bytes(12));
    }

    public static function hashOpaque(string $value, string $purpose): string
    {
        return hash_hmac('sha256', $purpose . '|' . $value, self::appKey());
    }

    public static function hmac(string $value, string $purpose = 'general'): string
    {
        return hash_hmac('sha256', $purpose . '|' . $value, self::appKey());
    }
}
