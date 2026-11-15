<?php

declare(strict_types=1);

namespace Relay\Services;

final class Reservation
{
    public function __construct(
        public readonly string $requestId,
        public readonly int $userId,
        public readonly int $apiKeyId,
        public readonly string $day,
        public readonly int $dailyRequestLimit,
        public readonly int $dailyTokenLimit,
    ) {
    }
}
