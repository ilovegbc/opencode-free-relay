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
            'INSERT INTO invite_codes (code_hash, label, plan_id, created_by, max_uses, initial_credits, expires_at, active)
             VALUES (?, ?, ?, ?, ?, ?, ?::timestamptz, ?)
             RETURNING *',
            [
                Crypto::hashOpaque($code, 'invite'),
                $data['label'] ?? '',
                $data['plan_id'] ?? null,
                $actorId,
                max(1, (int) ($data['max_uses'] ?? 1)),
                $data['initial_credits'] ?? 0,
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
                 WHERE i.code_hash = ? FOR UPDATE',
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

    /** @return array{code:string,row:array<string,mixed>} */
    public function createRedemption(array $data, ?int $actorId): array
    {
        $code = Crypto::redemptionCode();
        $row = $this->db->fetch(
            'INSERT INTO redemption_codes (code_hash, credits, max_uses, expires_at, active, note, created_by)
             VALUES (?, ?, ?, ?::timestamptz, ?, ?, ?)
             RETURNING *',
            [
                Crypto::hashOpaque($code, 'redemption'),
                $data['credits'],
                max(1, (int) ($data['max_uses'] ?? 1)),
                $data['expires_at'] ?? null,
                !empty($data['active']) ? 'true' : 'false',
                $data['note'] ?? '',
                $actorId,
            ]
        );
        if ($row === null) {
            throw new \RuntimeException('Could not create redemption code.');
        }
        return ['code' => $code, 'row' => $row];
    }

    /** @return list<array<string,mixed>> */
    public function redemptions(): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, u.username AS creator FROM redemption_codes r
             LEFT JOIN users u ON u.id = r.created_by ORDER BY r.id DESC'
        );
    }

    public function disableRedemption(int $id): void
    {
        $this->db->execute('UPDATE redemption_codes SET active = false WHERE id = ?', [$id]);
    }

    public function redeem(string $code, int $userId): ?array
    {
        return $this->db->transaction(function () use ($code, $userId): ?array {
            $row = $this->db->fetch(
                'SELECT * FROM redemption_codes WHERE code_hash = ? FOR UPDATE',
                [Crypto::hashOpaque(trim($code), 'redemption')]
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
            $used = $this->db->fetch('SELECT 1 FROM redemption_uses WHERE code_id = ? AND user_id = ?', [$row['id'], $userId]);
            if ($used !== null) {
                return null;
            }
            $credits = (float) $row['credits'];
            $balance = $this->db->fetch('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($balance === null) {
                return null;
            }
            $newBalance = (float) $balance['balance'] + $credits;
            $this->db->execute('UPDATE users SET balance = ? WHERE id = ?', [$newBalance, $userId]);
            $this->db->execute(
                'INSERT INTO redemption_uses (code_id, user_id, credits) VALUES (?, ?, ?)',
                [$row['id'], $userId, $credits]
            );
            $this->db->execute('UPDATE redemption_codes SET used_count = used_count + 1 WHERE id = ?', [$row['id']]);
            $this->db->execute(
                'INSERT INTO credit_transactions (user_id, amount, balance_after, kind, reference, note)
                 VALUES (?, ?, ?, \'redeem\', ?, ?)',
                [$userId, $credits, $newBalance, 'redemption:' . $row['id'], 'Redeemed a code']
            );
            return ['credits' => $credits, 'balance' => $newBalance];
        });
    }
}
