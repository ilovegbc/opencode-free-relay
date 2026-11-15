<?php

declare(strict_types=1);

namespace Relay\Repositories;

use Relay\Support\Database;
use Relay\Support\Ipv6;

final class IpPoolRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll('SELECT * FROM relay_pool_stats ORDER BY id');
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return $this->db->fetchAll('SELECT * FROM ip_pools WHERE enabled = true ORDER BY weight DESC, id');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM ip_pools WHERE id = ?', [$id]);
    }

    public function create(array $data): array
    {
        $parsed = Ipv6::parseCidr((string) $data['cidr']);
        $this->assertNoOverlap($parsed['cidr']);
        $row = $this->db->fetch(
            'INSERT INTO ip_pools (name, cidr, enabled, weight, lease_ttl_seconds, interface_name, notes)
             VALUES (?, ?::cidr, ?, ?, ?, ?, ?)
             RETURNING *',
            [
                $data['name'],
                $parsed['cidr'],
                !empty($data['enabled']) ? 'true' : 'false',
                $data['weight'] ?? 100,
                $data['lease_ttl_seconds'] ?? 604800,
                $data['interface_name'] ?? null,
                $data['notes'] ?? '',
            ]
        );
        if ($row === null) {
            throw new \RuntimeException('Could not create IP pool.');
        }
        return $row;
    }

    public function update(int $id, array $data): void
    {
        if (isset($data['cidr'])) {
            $parsed = Ipv6::parseCidr((string) $data['cidr']);
            $this->assertNoOverlap($parsed['cidr'], $id);
            $data['cidr'] = $parsed['cidr'];
        }
        $allowed = ['name', 'cidr', 'enabled', 'weight', 'lease_ttl_seconds', 'interface_name', 'notes'];
        $sets = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = $field . ($field === 'cidr' ? ' = ?::cidr' : ' = ?');
                $params[] = is_bool($data[$field]) ? ($data[$field] ? 'true' : 'false') : $data[$field];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->execute('UPDATE ip_pools SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM ip_pools WHERE id = ?', [$id]);
    }

    public function markStatus(int $id, string $status, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE ip_pools SET status = ?, last_error = ?, last_checked_at = now() WHERE id = ?',
            [$status, $error, $id]
        );
    }

    public function leaseCount(int $id): int
    {
        return (int) $this->db->scalar(
            'SELECT count(*) FROM ip_leases WHERE pool_id = ? AND expires_at > now() AND released_at IS NULL',
            [$id]
        );
    }

    public function deleteExpiredLeases(int $limit = 1000): int
    {
        return $this->db->execute(
            'DELETE FROM ip_leases WHERE id IN (SELECT id FROM ip_leases WHERE expires_at < now() LIMIT ?)',
            [$limit]
        );
    }

    public function reserveLease(int $poolId, string $address, string $expiresAt, ?string $requestId = null): ?int
    {
        $row = $this->db->fetch(
            'INSERT INTO ip_leases (pool_id, address, expires_at, request_id)
             VALUES (?, ?::inet, ?::timestamptz, ?::uuid)
             ON CONFLICT (address) DO NOTHING
             RETURNING id',
            [$poolId, $address, $expiresAt, $requestId]
        );
        return $row === null ? null : (int) $row['id'];
    }

    public function assertNoOverlap(string $cidr, ?int $exceptId = null): void
    {
        foreach ($this->all() as $pool) {
            if ($exceptId !== null && (int) $pool['id'] === $exceptId) {
                continue;
            }
            if (Ipv6::overlaps($cidr, (string) $pool['cidr'])) {
                throw new \InvalidArgumentException('This range overlaps an existing pool: ' . $pool['name'] . ' (' . $pool['cidr'] . ').');
            }
        }
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return $this->db->fetch(
            'SELECT count(*) AS pools,
                    count(*) FILTER (WHERE enabled) AS enabled_pools,
                    count(*) FILTER (WHERE enabled AND status = \'healthy\') AS healthy_pools,
                    coalesce((SELECT count(*) FROM ip_leases WHERE expires_at > now() AND released_at IS NULL), 0) AS active_leases
             FROM ip_pools'
        ) ?? ['pools' => 0, 'enabled_pools' => 0, 'healthy_pools' => 0, 'active_leases' => 0];
    }
}
