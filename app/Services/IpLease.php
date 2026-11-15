<?php

declare(strict_types=1);

namespace Relay\Services;

final class IpLease
{
    public function __construct(
        public readonly int $leaseId,
        public readonly int $poolId,
        public readonly string $poolName,
        public readonly string $cidr,
        public readonly string $address,
    ) {
    }
}
