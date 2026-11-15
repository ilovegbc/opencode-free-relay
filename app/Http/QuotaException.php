<?php

declare(strict_types=1);

namespace Relay\Http;

final class QuotaException extends HttpException
{
    public function __construct(string $message, string $code = 'quota_exceeded', int $retryAfter = 0)
    {
        $headers = $retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : [];
        parent::__construct(429, $message, $code, $headers);
    }
}
