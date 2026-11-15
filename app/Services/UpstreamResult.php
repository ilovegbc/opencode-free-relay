<?php

declare(strict_types=1);

namespace Relay\Services;

final class UpstreamResult
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $latencyMs,
        public readonly int $firstByteMs,
        public readonly int $bytes,
        public readonly string $sourceIp,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly int $curlErrno = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->errorCode === null;
    }

    public function retryable(): bool
    {
        if ($this->errorCode !== null && in_array($this->errorCode, ['ipv6_bind_failed', 'ipv6_connect_failed', 'upstream_timeout', 'tls_error'], true)) {
            return true;
        }
        if ($this->errorCode === 'ipv6_dns_failed') {
            // A missing AAAA record is target-wide; another local IPv6
            // address will not fix it, and IPv4 fallback is forbidden.
            return false;
        }
        return in_array($this->status, [408, 425, 429, 500, 502, 503, 504], true);
    }
}
