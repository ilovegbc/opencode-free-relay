<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Crypto;
use Relay\Support\Database;

final class ApiKeyRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{key:string,row:array<string,mixed>} */
    public function create(int $userId, string $name, ?string $expiresAt = null): array
    {
        $full = Crypto::apiKey();
        $row = $this->db->fetch(
            'INSERT INTO api_keys (user_id, name, prefix, key_hash, expires_at)
             VALUES (?, ?, ?, ?, ?)
             RETURNING *',
            [$userId, $name !== '' ? $name : 'default', Crypto::keyPrefix($full), Crypto::hashApiKey($full), $expiresAt]
        );
        if ($row === null) {
            throw new \RuntimeException('Could not create API key.');
        }
        return ['key' => $full, 'row' => $row];
    }

    public function findByHash(string $hash): ?array
    {
        return $this->db->fetch(
            'SELECT k.*, u.username, u.email, u.role, u.status AS user_status, u.plan_id, u.balance,
                    p.code AS plan_code, p.name AS plan_name, p.daily_requests, p.daily_tokens, p.rpm,
                    p.concurrency, p.allowed_models, p.discount_multiplier
             FROM api_keys k
             JOIN users u ON u.id = k.user_id
             LEFT JOIN plans p ON p.id = u.plan_id
             WHERE k.key_hash = ?',
            [$hash]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->fetchAll('SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM api_keys WHERE id = ?', [$id]);
    }

    public function revoke(int $id, int $userId): bool
    {
        return $this->db->execute(
            'UPDATE api_keys SET status = \'revoked\', revoked_at = now() WHERE id = ? AND user_id = ? AND status = \'active\'',
            [$id, $userId]
        ) > 0;
    }

    public function revokeAsAdmin(int $id): bool
    {
        return $this->db->execute(
            'UPDATE api_keys SET status = \'revoked\', revoked_at = now() WHERE id = ? AND status = \'active\'',
            [$id]
        ) > 0;
    }

    public function touch(int $id): void
    {
        $this->db->execute('UPDATE api_keys SET last_used_at = now(), request_count = request_count + 1 WHERE id = ?', [$id]);
    }

    public function activeCountForUser(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT count(*) FROM api_keys WHERE user_id = ? AND status = \'active\' AND (expires_at IS NULL OR expires_at > now())',
            [$userId]
        );
    }
}
