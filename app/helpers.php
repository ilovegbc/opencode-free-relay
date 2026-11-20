<?php

declare(strict_types=1);

use Relay\App;
use Relay\Http\HttpException;
use Relay\Support\Session;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return App::url($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return App::asset($path);
    }
}

if (!function_exists('cfg')) {
    function cfg(string $key, mixed $default = null): mixed
    {
        return App::config($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return App::settings()->get($key, $default);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Session::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::get('_old.' . $key, $default);
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?array
    {
        return \Relay\Auth\Auth::user();
    }
}

if (!function_exists('now')) {
    function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

if (!function_exists('uuid_v4')) {
    function uuid_v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

if (!function_exists('b64url')) {
    function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

if (!function_exists('format_number')) {
    function format_number(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        return number_format((float) $value, $decimals);
    }
}

if (!function_exists('format_dt')) {
    function format_dt(?string $value, string $format = 'Y-m-d H:i:s'): string
    {
        if (!$value) {
            return '—';
        }
        try {
            return (new DateTimeImmutable($value))->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $value): string
    {
        if (!$value) {
            return 'never';
        }
        try {
            $then = new DateTimeImmutable($value);
        } catch (Throwable) {
            return 'unknown';
        }
        $seconds = max(0, time() - $then->getTimestamp());
        if ($seconds < 60) {
            return $seconds . 's ago';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ago';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ago';
        }
        return intdiv($seconds, 86400) . 'd ago';
    }
}

if (!function_exists('abort')) {
    function abort(int $status, string $message = '', string $code = 'http_error', array $headers = []): never
    {
        throw new HttpException($status, $message !== '' ? $message : 'Request failed', $code, $headers);
    }
}

if (!function_exists('mask_key')) {
    function mask_key(?string $value): string
    {
        if (!$value) {
            return '—';
        }
        $len = strlen($value);
        if ($len <= 12) {
            return $value;
        }
        return substr($value, 0, 8) . '…' . substr($value, -4);
    }
}

if (!function_exists('json_encode_pretty')) {
    function json_encode_pretty(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('safe_array')) {
    function safe_array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('pg_text_array')) {
    /**
     * Convert a PostgreSQL text[] value (pdo_pgsql returns it as a string) to
     * a PHP array. NULL stays NULL, which means "all models" in this project.
     *
     * @return list<string>|null
     */
    function pg_text_array(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $value = (string) $value;
        if ($value === '{}') {
            return [];
        }
        $value = trim($value, '{}');
        if ($value === '') {
            return [];
        }
        $result = [];
        $current = '';
        $quoted = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quoted) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $value[++$i];
                } elseif ($char === '"') {
                    $quoted = false;
                } else {
                    $current .= $char;
                }
            } elseif ($char === '"') {
                $quoted = true;
            } elseif ($char === ',') {
                $result[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $result[] = $current;
        return array_values(array_filter($result, static fn (string $v): bool => $v !== ''));
    }
}

if (!function_exists('percent')) {
    function percent(int|float $used, int|float $limit): float
    {
        if ($limit <= 0) {
            return 0.0;
        }
        return min(100.0, max(0.0, ($used / $limit) * 100));
    }
}
