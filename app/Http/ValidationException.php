<?php

declare(strict_types=1);

namespace Relay\Http;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $errors */
    public function __construct(string $message = 'The given data was invalid.', public readonly array $errors = [])
    {
        parent::__construct(422, $message, 'validation_error');
    }
}
