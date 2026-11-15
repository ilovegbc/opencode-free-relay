<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Database;
use Relay\Support\Crypto;

final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT u.*, p.code AS plan_code, p.name AS plan_name, p.daily_requests, p.daily_tokens,
                    p.rpm, p.concurrency, p.allowed_models, p.discount_multiplier
             FROM users u LEFT JOIN plans p ON p.id = u.plan_id
             WHERE u.id = ?',
            [$id]
        );
    }

    public function findByLogin(string $login): ?array
    {
        return $this->db->fetch(
            'SELECT u.*, p.code AS plan_code, p.name AS plan_name, p.daily_requests, p.daily_tokens,
                    p.rpm, p.concurrency, p.allowed_models, p.discount_multiplier
             FROM users u LEFT JOIN plans p ON p.id = u.plan_id
             WHERE lower(u.username) = lower(?) OR lower(u.email) = lower(?)
             LIMIT 1',
            [$login, $login]
        );
    }

    public function create(array $data): array
    {
        $row = $this->db->fetch(
            'INSERT INTO users (username, email, password_hash, role, status, plan_id, balance, invited_by, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
             RETURNING *',
            [
                $data['username'],
                $data['email'] ?? null,
                $data['password_hash'],
                $data['role'] ?? 'user',
                $data['status'] ?? 'active',
                $data['plan_id'] ?? null,
                $data['balance'] ?? 0,
                $data['invited_by'] ?? null,
                json_encode($data['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
        if ($row === null) {
            throw new \RuntimeException('Could not create user.');
        }
        return $row;
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['username', 'email', 'password_hash', 'role', 'status', 'plan_id', 'balance', 'metadata', 'last_login_at', 'last_ip'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields)) {
                if ($field === 'metadata') {
                    $sets[] = 'metadata = ?::jsonb';
                    $params[] = json_encode($fields[$field], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $sets[] = $field . ' = ?';
                    $params[] = $fields[$field];
                }
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function recordLogin(int $id, string $ip): void
    {
        $this->db->execute('UPDATE users SET last_login_at = now(), last_ip = ? WHERE id = ?', [$ip, $id]);
    }

    /** @return list<array<string,mixed>> */
    public function paginate(string $search = '', int $limit = 50, int $offset = 0): array
    {
        $params = [];
        $where = '';
        if ($search !== '') {
            $where = 'WHERE lower(u.username) LIKE lower(?) OR lower(coalesce(u.email, \'\')) LIKE lower(?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        $params[] = $limit;
        $params[] = $offset;
        return $this->db->fetchAll(
            'SELECT u.*, p.code AS plan_code, p.name AS plan_name
             FROM users u LEFT JOIN plans p ON p.id = u.plan_id
             ' . $where . '
             ORDER BY u.id DESC LIMIT ? OFFSET ?',
            $params
        );
    }

    public function count(string $search = ''): int
    {
        $params = [];
        $where = '';
        if ($search !== '') {
            $where = 'WHERE lower(username) LIKE lower(?) OR lower(coalesce(email, \'\')) LIKE lower(?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }
        return (int) $this->db->scalar('SELECT count(*) FROM users ' . $where, $params);
    }

    public function adjustBalance(int $userId, float $delta, string $kind, string $note = '', ?int $actorId = null, string $reference = ''): float
    {
        return $this->db->transaction(function () use ($userId, $delta, $kind, $note, $actorId, $reference): float {
            $row = $this->db->fetch('SELECT balance FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if ($row === null) {
                throw new \RuntimeException('User not found.');
            }
            $new = (float) $row['balance'] + $delta;
            $this->db->execute('UPDATE users SET balance = ? WHERE id = ?', [$new, $userId]);
            $this->db->execute(
                'INSERT INTO credit_transactions (user_id, amount, balance_after, kind, reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $delta, $new, $kind, $reference !== '' ? $reference : null, $note, $actorId]
            );
            return $new;
        });
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return $this->db->fetch(
            'SELECT count(*) AS users,
                    count(*) FILTER (WHERE status = \'active\') AS active_users,
                    count(*) FILTER (WHERE created_at > now() - interval \'24 hours\') AS new_users_24h
             FROM users'
        ) ?? ['users' => 0, 'active_users' => 0, 'new_users_24h' => 0];
    }
}
