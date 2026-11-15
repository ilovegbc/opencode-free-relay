<?php

declare(strict_types=1);

namespace Relay\Support;

/**
 * Minimal .env loader. No Composer package, no process globals leaked into
 * templates. Values already present in the real environment win.
 */
final class Env
{
    private static bool $loaded = false;

    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $value = trim(substr($line, $pos + 1));

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $quote = $first;
                    $value = substr($value, 1, -1);
                    if ($quote === '"') {
                        $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                    }
                } else {
                    // Strip a trailing comment that is not part of the value.
                    $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
                }
            }

            self::$values[$key] = $value;
        }

        foreach (self::$values as $key => $value) {
            // Some shared hosts (BT Panel, cPanel) disable putenv() through
            // disable_functions. The values are still available through the
            // static map and $_ENV/$_SERVER, so never fatal on this.
            if (getenv($key) === false && function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
            $_ENV[$key] ??= $value;
            $_SERVER[$key] ??= $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }
}
