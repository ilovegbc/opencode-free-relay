<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Crypto;
use Relay\Support\Database;

final class CodeRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{code:string,row:array<string,mixed>} */
    public function createInvite(array $data, ?int $actorId): array
    {
        $code = Crypto::inviteCode();
        $row = $this->db->fetch(
            'INSERT INTO invite_codes (code_hash, label, plan_id, created_by, max_uses, expires_at, active)
             VALUES (?, ?, ?, ?, 1, ?::timestamptz, ?)
             RETURNING *',
            [
                Crypto::hashOpaque($code, 'invite'),
                $data['label'] ?? '',
                $data['plan_id'] ?? null,
                $actorId,
                $data['expires_at'] ?? null,
                !empty($data['active']) ? 'true' : 'false',
            ]
        );
        if ($row === null) {
            throw new \RuntimeException('Could not create invite code.');
        }
        return ['code' => $code, 'row' => $row];
    }

    public function findInvite(string $code): ?array
    {
        return $this->db->fetch(
            'SELECT i.*, p.code AS plan_code, p.name AS plan_name
             FROM invite_codes i LEFT JOIN plans p ON p.id = i.plan_id
             WHERE i.code_hash = ?',
            [Crypto::hashOpaque(trim($code), 'invite')]
        );
    }

    public function consumeInvite(string $code): ?array
    {
        return $this->db->transaction(function () use ($code): ?array {
            $row = $this->db->fetch(
                'SELECT i.*, p.code AS plan_code, p.name AS plan_name
                 FROM invite_codes i LEFT JOIN plans p ON p.id = i.plan_id
                 WHERE i.code_hash = ? FOR UPDATE OF i',
                [Crypto::hashOpaque(trim($code), 'invite')]
            );
            if ($row === null || !$row['active']) {
                return null;
            }
            if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
                return null;
            }
            if ((int) $row['used_count'] >= (int) $row['max_uses']) {
                return null;
            }
            $this->db->execute('UPDATE invite_codes SET used_count = used_count + 1 WHERE id = ?', [$row['id']]);
            return $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function invites(): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS plan_name, u.username AS creator
             FROM invite_codes i LEFT JOIN plans p ON p.id = i.plan_id LEFT JOIN users u ON u.id = i.created_by
             ORDER BY i.id DESC'
        );
    }

    public function disableInvite(int $id): void
    {
        $this->db->execute('UPDATE invite_codes SET active = false WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function invitesForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT i.*, p.name AS plan_name
             FROM invite_codes i LEFT JOIN plans p ON p.id = i.plan_id
             WHERE i.created_by = ?
             ORDER BY i.id DESC',
            [$userId]
        );
    }

    public function disableInviteForUser(int $id, int $userId): bool
    {
        return $this->db->execute(
            'UPDATE invite_codes SET active = false WHERE id = ? AND created_by = ?',
            [$id, $userId]
        ) > 0;
    }
}
