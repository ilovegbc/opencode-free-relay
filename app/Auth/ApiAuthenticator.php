<?php

declare(strict_types=1);

namespace Relay\Auth;

use Relay\Http\AuthenticationException;
use Relay\Http\Request;
use Relay\Repositories\ApiKeyRepository;
use Relay\Support\Crypto;

final class ApiAuthenticator
{
    public function __construct(private ApiKeyRepository $keys)
    {
    }

    /**
     * @return array{key:array<string,mixed>,user:array<string,mixed>}
     */
    public function authenticate(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null || !str_starts_with($token, 'sk-')) {
            throw new AuthenticationException();
        }
        $row = $this->keys->findByHash(Crypto::hashApiKey($token));
        if ($row === null) {
            throw new AuthenticationException();
        }
        if (($row['status'] ?? '') !== 'active') {
            throw new AuthenticationException('This API key has been revoked.');
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            throw new AuthenticationException('This API key has expired.');
        }
        if (($row['user_status'] ?? '') !== 'active') {
            throw new AuthenticationException('The account linked to this key is not active.');
        }

        $user = [
            'id' => $row['user_id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'role' => $row['role'],
            'status' => $row['user_status'],
            'plan_id' => $row['plan_id'],
            'balance' => $row['balance'],
            'plan_code' => $row['plan_code'],
            'plan_name' => $row['plan_name'],
            'daily_requests' => $row['daily_requests'],
            'daily_tokens' => $row['daily_tokens'],
            'rpm' => $row['rpm'],
            'concurrency' => $row['concurrency'],
            'allowed_models' => pg_text_array($row['allowed_models']),
            'discount_multiplier' => $row['discount_multiplier'],
        ];
        $key = [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'prefix' => $row['prefix'],
        ];
        return ['key' => $key, 'user' => $user];
    }
}
