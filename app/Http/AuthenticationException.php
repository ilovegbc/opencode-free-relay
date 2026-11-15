<?php

declare(strict_types=1);

namespace Relay\Http;

final class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Invalid or missing API key.')
    {
        parent::__construct(401, $message, 'invalid_api_key', ['WWW-Authenticate' => 'Bearer']);
    }
}
