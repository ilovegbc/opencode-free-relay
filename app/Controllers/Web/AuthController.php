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
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
        if ($email !== '' && $users->findByLogin($email) !== null) {
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
        $welcome = (float) setting('site.welcome_credits', 0) + (float) ($invite['initial_credits'] ?? 0);
        $user = null;
        try {
            $user = $db->transaction(function () use ($users, $invite, $inviteCode, $username, $email, $password, $plan, $welcome, $requireApproval): array {
                if ($invite !== null) {
                    $consumed = (new CodeRepository(App::db()))->consumeInvite($inviteCode);
                    if ($consumed === null) {
                        throw new HttpException(422, 'That invite code is no longer valid.', 'invalid_invite');
                    }
                }
                $created = $users->create([
                    'username' => $username,
                    'email' => $email !== '' ? $email : null,
                    'password_hash' => Crypto::passwordHash($password),
                    'role' => 'user',
                    'status' => $requireApproval ? 'pending' : 'active',
                    'plan_id' => $plan['id'],
                    'balance' => $welcome,
                ]);
                if ($welcome > 0) {
                    App::db()->execute(
                        'INSERT INTO credit_transactions (user_id, amount, balance_after, kind, note)
                         VALUES (?, ?, ?, \'signup\', ?)',
                        [$created['id'], $welcome, $welcome, 'Welcome credit']
                    );
                }
                return $created;
            });
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            Response::redirect('/register');
        }

        if ($requireApproval) {
            flash('success', 'Account created. An administrator must approve it before you can sign in.');
            Response::redirect('/login');
        }

        Auth::login($user, $request->ip());
        flash('success', 'Welcome. Create an API key to get started.');
        Response::redirect('/dashboard');
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
