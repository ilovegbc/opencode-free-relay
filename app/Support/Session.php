<?php

declare(strict_types=1);

namespace Relay\Support;

use Relay\App;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        self::$started = true;

        $name = App::config('session.name', 'ocrelay_session');
        session_name((string) $name);

        $secure = App::config('session.secure', 'auto');
        $secure = $secure === 'auto'
            ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            : filter_var($secure, FILTER_VALIDATE_BOOL);

        session_set_cookie_params([
            'lifetime' => (int) App::config('session.lifetime', 86400),
            'path' => '/',
            'domain' => '',
            'secure' => (bool) $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        self::start();
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        return is_string($token) && $token !== '' && hash_equals((string) ($_SESSION['_csrf'] ?? ''), $token);
    }

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public static function consumeFlashes(): array
    {
        self::start();
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flashes) ? $flashes : [];
    }

    /** @param array<string,mixed> $input */
    public static function flashOld(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_confirmation']);
        self::put('_old', $input);
    }

    public static function consumeOld(): void
    {
        self::forget('_old');
    }
}
