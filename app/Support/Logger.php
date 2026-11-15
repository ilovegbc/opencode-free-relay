<?php

declare(strict_types=1);

namespace Relay\Support;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $dir = defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : sys_get_temp_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $context = self::redact($context);
        $line = json_encode([
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            $line = json_encode(['ts' => gmdate('c'), 'level' => $level, 'message' => $message]);
        }
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (str_contains($lower, 'password') || str_contains($lower, 'secret') || str_contains($lower, 'authorization') || str_contains($lower, 'api_key') || str_contains($lower, 'token')) {
                if (is_string($value) && $value !== '') {
                    $data[$key] = substr($value, 0, 6) . '…[redacted]';
                } else {
                    $data[$key] = '[redacted]';
                }
                continue;
            }
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
}
