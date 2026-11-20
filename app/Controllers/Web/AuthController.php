<?php

declare(strict_types=1);

namespace Relay\Controllers\Web;

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Controller;
use Relay\Http\HttpException;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Http\ValidationException;
use Relay\Repositories\CodeRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\PlanRepository;
use Relay\Repositories\UserRepository;
use Relay\Services\Mailer;
use Relay\Support\Crypto;
use Relay\Support\Session;

final class AuthController extends Controller
{
    public function loginForm(Request $request): never
    {
        if (Auth::user() !== null) {
            Response::redirect('/dashboard');
        }
        $this->render('auth/login', ['activeNav' => 'login'], 'layouts/auth');
    }

    public function login(Request $request): never
    {
        $this->validateCsrf($request);
        $login = trim((string) $request->post('login', ''));
        $password = (string) $request->post('password', '');
        $ip = $request->ip();
        if ($login === '' || $password === '') {
            throw new ValidationException('Enter your username/email and password.', ['login' => 'Required']);
        }

        $maxAttempts = (int) setting('security.login_max_attempts', 10);
        $window = (int) setting('security.login_window_minutes', 15);
        $failed = (new LogRepository(App::db()))->recentFailedLogins($login, $ip, max(1, $window));
        if ($failed >= $maxAttempts) {
            throw new HttpException(429, 'Too many failed sign-in attempts. Try again later.', 'login_rate_limited');
        }

        $user = Auth::attempt($login, $password, $ip);
        if ($user === null) {
            Session::flashOld(['login' => $login]);
            flash('error', 'Invalid username/email or password.');
            Response::redirect('/login');
        }
        Session::forget('_old');
        $intended = Session::pull('intended');
        Response::redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/dashboard');
    }

    public function registerForm(Request $request): never
    {
        $mode = (string) setting('site.registration_mode', 'open');
        if ($mode === 'closed') {
            throw new HttpException(403, 'Registration is currently closed.', 'registration_closed');
        }
        if (Auth::user() !== null) {
            Response::redirect('/dashboard');
        }
        $this->render('auth/register', [
            'activeNav' => 'register',
            'mode' => $mode,
            'invite' => (string) $request->query('invite', ''),
        ], 'layouts/auth');
    }

    public function register(Request $request): never
    {
        $this->validateCsrf($request);
        $mode = (string) setting('site.registration_mode', 'open');
        if ($mode === 'closed') {
            throw new HttpException(403, 'Registration is currently closed.', 'registration_closed');
        }

        $username = trim((string) $request->post('username', ''));
        $email = trim((string) $request->post('email', ''));
        $password = (string) $request->post('password', '');
        $password2 = (string) $request->post('password_confirmation', '');
        $inviteCode = trim((string) $request->post('invite', ''));

        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            $errors['username'] = 'Use 3–32 letters, numbers, dots, underscores or hyphens.';
        }
        if ($email === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($password !== $password2) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }
        if ($mode === 'invite' && $inviteCode === '') {
            $errors['invite'] = 'An invite code is required.';
        }
        if ($errors !== []) {
            Session::flashOld(['username' => $username, 'email' => $email, 'invite' => $inviteCode]);
            foreach ($errors as $message) {
                flash('error', $message);
            }
            Response::redirect('/register' . ($inviteCode !== '' ? '?invite=' . rawurlencode($inviteCode) : ''));
        }

        $db = App::db();
        $users = new UserRepository($db);
        if ($users->findByLogin($username) !== null) {
            flash('error', 'That username is already taken.');
            Response::redirect('/register');
        }
        if ($users->findByLogin($email) !== null) {
            flash('error', 'That email is already registered.');
            Response::redirect('/register');
        }

        $plans = new PlanRepository($db);
        $codes = new CodeRepository($db);
        $plan = $plans->defaultPlan();
        $invite = null;
        if ($mode === 'invite' || $inviteCode !== '') {
            $invite = $codes->findInvite($inviteCode);
            if ($invite === null || !$invite['active'] || (int) $invite['used_count'] >= (int) $invite['max_uses'] || ($invite['expires_at'] !== null && strtotime((string) $invite['expires_at']) < time())) {
                flash('error', 'That invite code is invalid or exhausted.');
                Response::redirect('/register');
            }
            if ($invite['plan_id'] !== null) {
                $plan = $plans->find((int) $invite['plan_id']) ?? $plan;
            }
        }
        if ($plan === null) {
            throw new HttpException(503, 'No default plan is configured. Ask the administrator to run the seed migration.', 'no_plan');
        }

        $requireApproval = (bool) setting('site.require_approval', false);
        $mailer = new Mailer(App::settings());
        $needsVerification = $mailer->isConfigured();
        $user = null;
        try {
            $user = $db->transaction(function () use ($users, $invite, $inviteCode, $username, $email, $password, $plan, $requireApproval, $needsVerification): array {
                if ($invite !== null) {
                    $consumed = (new CodeRepository(App::db()))->consumeInvite($inviteCode);
                    if ($consumed === null) {
                        throw new HttpException(422, 'That invite code is no longer valid.', 'invalid_invite');
                    }
                }
                return $users->create([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => Crypto::passwordHash($password),
                    'role' => 'user',
                    'status' => ($needsVerification || $requireApproval) ? 'pending' : 'active',
                    'plan_id' => $plan['id'],
                    'metadata' => ['email_verified' => !$needsVerification],
                ]);
            });
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            Response::redirect('/register');
        }

        if ($needsVerification) {
            $token = bin2hex(random_bytes(32));
            $ttl = max(5, (int) setting('mail.verify_ttl_minutes', 60));
            $db->execute(
                'INSERT INTO email_verifications (user_id, token_hash, expires_at)
                 VALUES (?, ?, now() + (?::int * interval \'1 minute\'))',
                [(int) $user['id'], hash('sha256', $token), $ttl]
            );
            $link = rtrim((string) cfg('app.url', ''), '/') . '/verify-email?token=' . $token;
            $siteName = (string) setting('site.name', 'OpenCode Relay');
            $html = '<p>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '，你好：</p>'
                . '<p>请点击下面的链接验证邮箱并完成注册，链接 ' . $ttl . ' 分钟内有效：</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
                . '<p>如果这不是你本人的操作，请忽略这封邮件。</p>'
                . '<p>— ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>';
            if (!$mailer->send($email, '验证你的邮箱 · ' . $siteName, $html)) {
                $db->execute('DELETE FROM users WHERE id = ?', [(int) $user['id']]);
                flash('error', '验证邮件发送失败：' . ($mailer->lastError() ?: 'SMTP error') . ' 请稍后重试或联系管理员。');
                Response::redirect('/register');
            }
            flash('success', '验证邮件已发送到 ' . $email . '，请点击邮件中的链接完成注册。');
            Response::redirect('/login');
        }

        if ($requireApproval) {
            flash('success', 'Account created. An administrator must approve it before you can sign in.');
            Response::redirect('/login');
        }

        Auth::login($user, $request->ip());
        flash('success', 'Welcome. Create an API key to get started.');
        Response::redirect('/dashboard');
    }

    public function verifyEmail(Request $request): never
    {
        $token = trim((string) $request->query('token', ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            flash('error', '验证链接无效或已过期。');
            Response::redirect('/login');
        }
        $db = App::db();
        $row = $db->fetch(
            'SELECT * FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > now()',
            [hash('sha256', $token)]
        );
        if ($row === null) {
            flash('error', '验证链接无效或已过期。');
            Response::redirect('/login');
        }
        $db->transaction(function () use ($db, $row): void {
            $db->execute('UPDATE email_verifications SET used_at = now() WHERE id = ?', [$row['id']]);
            $db->execute(
                "UPDATE users SET status = 'active',
                        metadata = coalesce(metadata, '{}'::jsonb) || '{\"email_verified\": true}'::jsonb
                  WHERE id = ?",
                [$row['user_id']]
            );
        });
        $this->audit('user.email_verified', 'user', (string) $row['user_id']);
        flash('success', '邮箱验证成功，请登录。');
        Response::redirect('/login');
    }

    public function logout(Request $request): never
    {
        if ($request->method() === 'POST') {
            $this->validateCsrf($request);
        }
        Auth::logout();
        Response::redirect('/');
    }
}
