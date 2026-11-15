<?php

declare(strict_types=1);

namespace Relay\Services;

use RuntimeException;

final class Ipv6UnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'ipv6_unavailable')
    {
        parent::__construct($message);
    }
}
