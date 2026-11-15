<?php

declare(strict_types=1);

namespace Relay\Services;

use RuntimeException;

final class UpstreamException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $upstreamStatus = 0,
        public readonly string $errorCode = 'upstream_error',
        public readonly bool $retryable = false,
        public readonly string $body = '',
        public readonly array $attempts = [],
    ) {
        parent::__construct($message);
    }
}
