<?php

declare(strict_types=1);

namespace Relay\Auth;

use Relay\App;
use Relay\Http\HttpException;
use Relay\Repositories\LogRepository;
use Relay\Repositories\UserRepository;
use Relay\Repositories\PlanRepository;
use Relay\Support\Crypto;
use Relay\Support\Session;

final class Auth
{
    private static ?array $cached = null;

    public static function user(): ?array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $id = Session::get('user_id');
        if (!is_numeric($id)) {
            return null;
        }
        $user = (new UserRepository(App::db()))->find((int) $id);
        if ($user === null || $user['status'] !== 'active') {
            Session::forget('user_id');
            return null;
        }
        self::$cached = $user;
        return $user;
    }

    public static function attempt(string $login, string $password, string $ip): ?array
    {
        $users = new UserRepository(App::db());
        $logs = new LogRepository(App::db());
        $user = $users->findByLogin($login);
        $ok = $user !== null && Crypto::passwordVerify($password, (string) $user['password_hash']);
        $logs->recordLoginAttempt($login, filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null, $ok && $user['status'] === 'active');
        if (!$ok || $user === null) {
            return null;
        }
        if ($user['status'] !== 'active') {
            throw new HttpException(403, 'This account is not active yet. If you just registered, verify your email first; otherwise contact the administrator.', 'account_inactive');
        }
        self::login($user, $ip);
        return self::user();
    }

    public static function login(array $user, string $ip = ''): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        self::$cached = null;
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            (new UserRepository(App::db()))->recordLogin((int) $user['id'], $ip);
        }
    }

    public static function logout(): void
    {
        self::$cached = null;
        Session::destroy();
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                Session::put('intended', $_SERVER['REQUEST_URI'] ?? '/dashboard');
                \Relay\Http\Response::redirect('/login');
            }
            throw new HttpException(401, 'Please sign in first.', 'unauthenticated');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (($user['role'] ?? 'user') !== 'admin') {
            throw new HttpException(403, 'Administrator access is required.', 'forbidden');
        }
        return $user;
    }

    public static function refresh(): void
    {
        self::$cached = null;
    }

    public static function defaultPlan(): ?array
    {
        return (new PlanRepository(App::db()))->defaultPlan();
    }
}
