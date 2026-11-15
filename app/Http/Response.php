<?php

declare(strict_types=1);

namespace Relay\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function apiError(string $message, string $type = 'invalid_request_error', string $code = 'error', int $status = 400, array $headers = []): never
    {
        self::json([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $code,
            ],
        ], $status, $headers);
    }

    /** @param array<string,string> $headers */
    public static function raw(string $body, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $body;
        exit;
    }

    /** @param array<string,string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $body;
        exit;
    }

    public static function redirect(string $path, int $status = 302): never
    {
        $target = preg_match('#^https?://#i', $path) ? $path : url($path);
        if (!headers_sent()) {
            header('Location: ' . $target, true, $status);
        }
        exit;
    }

    public static function noContent(int $status = 204): never
    {
        if (!headers_sent()) {
            http_response_code($status);
        }
        exit;
    }

    /** @param array<string,string> $headers */
    public static function beginStream(int $status, array $headers = []): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        header('X-Content-Type-Options: nosniff');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @ob_implicit_flush(true);
    }

    public static function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-cache, no-transform',
        ];
    }
}
