<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Database;

final class PlanRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE active = true' : '';
        return $this->db->fetchAll('SELECT * FROM plans ' . $where . ' ORDER BY sort_order, id');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM plans WHERE id = ?', [$id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->fetch('SELECT * FROM plans WHERE code = ?', [$code]);
    }

    public function defaultPlan(): ?array
    {
        return $this->db->fetch('SELECT * FROM plans WHERE is_default = true AND active = true LIMIT 1')
            ?? $this->db->fetch('SELECT * FROM plans WHERE active = true ORDER BY sort_order, id LIMIT 1');
    }

    public function create(array $data): array
    {
        $row = $this->db->fetch(
            'INSERT INTO plans (code, name, description, price_credits, daily_requests, daily_tokens, rpm, concurrency,
                                allowed_models, discount_multiplier, is_default, active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING *',
            [
                $data['code'],
                $data['name'],
                $data['description'] ?? '',
                $data['price_credits'] ?? 0,
                $data['daily_requests'] ?? 200,
                $data['daily_tokens'] ?? 5000000,
                $data['rpm'] ?? 30,
                $data['concurrency'] ?? 3,
                $this->pgTextArray($data['allowed_models'] ?? null),
                $data['discount_multiplier'] ?? 1,
                !empty($data['is_default']) ? 'true' : 'false',
                !empty($data['active']) ? 'true' : 'false',
                $data['sort_order'] ?? 0,
            ]
        );
        if ($row === null) {
            throw new \RuntimeException('Could not create plan.');
        }
        if (!empty($data['is_default'])) {
            $this->makeDefault((int) $row['id']);
        }
        return $row;
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['code', 'name', 'description', 'price_credits', 'daily_requests', 'daily_tokens', 'rpm', 'concurrency', 'discount_multiplier', 'is_default', 'active', 'sort_order'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = $field . ' = ?';
                $params[] = is_bool($data[$field]) ? ($data[$field] ? 'true' : 'false') : $data[$field];
            }
        }
        if (array_key_exists('allowed_models', $data)) {
            $sets[] = 'allowed_models = ?::text[]';
            $params[] = $this->pgTextArray($data['allowed_models']);
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->execute('UPDATE plans SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        if (!empty($data['is_default'])) {
            $this->makeDefault($id);
        }
    }

    private function makeDefault(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $this->db->execute('UPDATE plans SET is_default = false WHERE id <> ? AND is_default', [$id]);
            $this->db->execute('UPDATE plans SET is_default = true WHERE id = ?', [$id]);
        });
    }

    public function userCount(int $planId): int
    {
        return (int) $this->db->scalar('SELECT count(*) FROM users WHERE plan_id = ?', [$planId]);
    }

    private function pgTextArray(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }
        $values = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $values), static fn ($v) => $v !== ''));
        if ($values === []) {
            return '{}';
        }
        return '{' . implode(',', array_map(static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"', $values)) . '}';
    }
}
