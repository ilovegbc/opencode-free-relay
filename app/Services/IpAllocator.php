<?php

declare(strict_types=1);

namespace Relay\Services;

use Relay\Repositories\IpPoolRepository;
use Relay\Support\Database;
use Relay\Support\Ipv6;
use Relay\Support\Settings;

/**
 * Allocates a never-before-used (within the lease window) address from a
 * custom IPv6 pool. The prefix length is read from the CIDR and is never
 * assumed to be /56, /48 or anything else.
 */
final class IpAllocator
{
    public function __construct(
        private Database $db,
        private IpPoolRepository $pools,
        private Settings $settings,
    ) {
    }

    /**
     * @param array<int,string> $excludeAddresses
     * @param array<int,int> $excludePoolIds
     */
    public function allocate(string $requestId, array $excludeAddresses = [], array $excludePoolIds = []): IpLease
    {
        $pools = $this->pools->enabled();
        if ($pools === []) {
            throw new Ipv6UnavailableException('No enabled IPv6 pool is configured. Add one in Admin → IPv6 pools.', 'no_ipv6_pool');
        }

        $allowLoopback = $this->settings->bool('ipv6.allow_loopback', false);
        $pools = array_values(array_filter($pools, function (array $pool) use ($allowLoopback): bool {
            $parsed = Ipv6::parseCidr((string) $pool['cidr']);
            if (!$allowLoopback && $parsed['prefix'] === 128 && $parsed['network'] === inet_pton('::1')) {
                return false;
            }
            return true;
        }));
        if ($excludePoolIds !== []) {
            $pools = array_values(array_filter($pools, static fn (array $pool): bool => !in_array((int) $pool['id'], $excludePoolIds, true)));
        }
        $preferred = array_values(array_filter($pools, static fn (array $pool): bool => ($pool['status'] ?? 'unknown') !== 'degraded'));
        if ($preferred !== []) {
            $pools = $preferred;
        }
        if ($pools === []) {
            throw new Ipv6UnavailableException('Every enabled IPv6 pool was excluded for this attempt.', 'no_ipv6_pool');
        }

        $ordered = $this->weightedOrder($pools);
        $ttlDefault = max(0, $this->settings->int('ipv6.default_lease_ttl', 604800));
        $exclude = array_fill_keys(array_map('strtolower', $excludeAddresses), true);

        foreach ($ordered as $pool) {
            $parsed = Ipv6::parseCidr((string) $pool['cidr']);
            $capacity = Ipv6::capacity($parsed['prefix']);

            // Expired leases are collected lazily before declaring
            // exhaustion. The capacity is derived from the CIDR's actual
            // prefix length, never from an assumed /48, /56 or /64.
            $this->pools->deleteExpiredLeases(5000);
            $leases = $this->pools->leaseCount((int) $pool['id']);
            if (function_exists('bccomp') ? bccomp((string) $leases, $capacity) >= 0 : $leases >= (float) $capacity) {
                $this->pools->markStatus((int) $pool['id'], 'degraded', 'Address space exhausted in this pool.');
                continue;
            }

            $ttl = (int) ($pool['lease_ttl_seconds'] ?? $ttlDefault);
            $expires = $ttl <= 0
                ? gmdate('Y-m-d H:i:s+00:00', time() + 3153600000) // ~100 years
                : gmdate('Y-m-d H:i:s+00:00', time() + max(60, $ttl));

            for ($attempt = 0; $attempt < 32; $attempt++) {
                try {
                    $address = Ipv6::randomAddress($parsed['network'], $parsed['prefix'], array_keys($exclude));
                } catch (\RuntimeException) {
                    break;
                }
                $leaseId = $this->pools->reserveLease((int) $pool['id'], $address, $expires, $requestId);
                if ($leaseId !== null) {
                    return new IpLease($leaseId, (int) $pool['id'], (string) $pool['name'], (string) $pool['cidr'], $address);
                }
                // Collision with an existing (possibly recent) lease. Try a
                // different address rather than reusing it.
                $exclude[$address] = true;
            }
        }

        throw new Ipv6UnavailableException(
            'No fresh IPv6 address is available in the configured pools. No IPv4 fallback is performed.',
            'ipv6_pool_exhausted'
        );
    }

    public function markBindFailure(IpLease $lease, string $message): void
    {
        $this->db->execute('UPDATE ip_leases SET failure_count = failure_count + 1 WHERE id = ?', [$lease->leaseId]);
        $this->pools->markStatus($lease->poolId, 'degraded', mb_substr($message, 0, 500));
    }

    public function markSuccess(IpLease $lease): void
    {
        $this->pools->markStatus($lease->poolId, 'healthy', null);
    }

    /** @param list<array<string,mixed>> $pools @return list<array<string,mixed>> */
    private function weightedOrder(array $pools): array
    {
        $remaining = $pools;
        $ordered = [];
        while ($remaining !== []) {
            $total = 0;
            foreach ($remaining as $pool) {
                $total += max(1, (int) $pool['weight']);
            }
            $pick = random_int(1, max(1, $total));
            $cursor = 0;
            foreach ($remaining as $index => $pool) {
                $cursor += max(1, (int) $pool['weight']);
                if ($pick <= $cursor) {
                    $ordered[] = $pool;
                    array_splice($remaining, $index, 1);
                    break;
                }
            }
        }
        return $ordered;
    }
}
