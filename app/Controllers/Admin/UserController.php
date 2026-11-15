<?php

declare(strict_types=1);

namespace Relay\Controllers\Admin;

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Controller;
use Relay\Http\HttpException;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Http\ValidationException;
use Relay\Repositories\ApiKeyRepository;
use Relay\Repositories\PlanRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\UserRepository;
use Relay\Support\Crypto;
use Relay\Support\Session;

final class UserController extends Controller
{
    public function index(Request $request): never
    {
        Auth::requireAdmin();
        $page = $this->page($request, 40);
        $search = trim((string) $request->query('q', ''));
        $repo = new UserRepository(App::db());
        $users = $repo->paginate($search, $page['limit'], $page['offset']);
        $total = $repo->count($search);
        [$current, $pages] = $this->paginationMeta($total, $page);
        $this->render('admin/users', [
            'activeNav' => 'users',
            'users' => $users,
            'search' => $search,
            'page' => $current,
            'pages' => $pages,
            'plans' => (new PlanRepository(App::db()))->all(),
        ], 'layouts/admin');
    }

    public function create(Request $request): never
    {
        $admin = Auth::requireAdmin();
        $this->validateCsrf($request);
        $username = trim((string) $request->post('username', ''));
        $email = trim((string) $request->post('email', ''));
        $password = (string) $request->post('password', '');
        $planId = (int) $request->post('plan_id', 0);
        $role = (string) $request->post('role', 'user');
        $status = (string) $request->post('status', 'active');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            throw new ValidationException('Invalid username.');
        }
        if (strlen($password) < 8) {
            throw new ValidationException('Password must be at least 8 characters.');
        }
        if (!in_array($role, ['user', 'admin'], true) || !in_array($status, ['active', 'pending', 'suspended'], true)) {
            throw new ValidationException('Invalid role or status.');
        }
        $users = new UserRepository(App::db());
        if ($users->findByLogin($username) !== null) {
            throw new ValidationException('Username already exists.');
        }
        if ($email !== '' && $users->findByLogin($email) !== null) {
            throw new ValidationException('Email already exists.');
        }
        $plan = (new PlanRepository(App::db()))->find($planId) ?? (new PlanRepository(App::db()))->defaultPlan();
        if ($plan === null) {
            throw new ValidationException('No plan is configured.');
        }
        $user = $users->create([
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password_hash' => Crypto::passwordHash($password),
            'role' => $role,
            'status' => $status,
            'plan_id' => $plan['id'],
            'balance' => (float) $request->post('balance', 0),
        ]);
        $this->audit('user.create', 'user', (string) $user['id'], ['username' => $username, 'role' => $role]);
        flash('success', 'User created.');
        Response::redirect('/admin/users/' . $user['id']);
    }

    public function show(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $repo = new UserRepository(App::db());
        $user = $repo->find($id);
        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }
        $logs = new LogRepository(App::db());
        $this->render('admin/user', [
            'activeNav' => 'users',
            'account' => $user,
            'plans' => (new PlanRepository(App::db()))->all(),
            'keys' => (new ApiKeyRepository(App::db()))->forUser($id),
            'logs' => $logs->usageForUser($id, 30),
            'credits' => App::db()->fetchAll('SELECT * FROM credit_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$id]),
            'newKey' => Session::pull('admin_new_key'),
        ], 'layouts/admin');
    }

    public function update(Request $request, array $params): never
    {
        $admin = Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $repo = new UserRepository(App::db());
        if ($repo->find($id) === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }
        $fields = [];
        if ($request->post('email') !== null) {
            $email = trim((string) $request->post('email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException('Invalid email.');
            }
            $fields['email'] = $email !== '' ? $email : null;
        }
        if ($request->post('role') !== null) {
            $role = (string) $request->post('role');
            if (!in_array($role, ['user', 'admin'], true)) {
                throw new ValidationException('Invalid role.');
            }
            $fields['role'] = $role;
        }
        if ($request->post('status') !== null) {
            $status = (string) $request->post('status');
            if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
                throw new ValidationException('Invalid status.');
            }
            $fields['status'] = $status;
        }
        if ($request->post('plan_id') !== null) {
            $planId = (int) $request->post('plan_id');
            if ((new PlanRepository(App::db()))->find($planId) === null) {
                throw new ValidationException('Invalid plan.');
            }
            $fields['plan_id'] = $planId;
        }
        if ($request->post('password') !== null && (string) $request->post('password') !== '') {
            $password = (string) $request->post('password');
            if (strlen($password) < 8) {
                throw new ValidationException('Password must be at least 8 characters.');
            }
            $fields['password_hash'] = Crypto::passwordHash($password);
        }
        $repo->update($id, $fields);
        $this->audit('user.update', 'user', (string) $id, ['fields' => array_keys($fields)]);
        flash('success', 'User updated.');
        Response::redirect('/admin/users/' . $id);
    }

    public function balance(Request $request, array $params): never
    {
        $admin = Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $delta = (float) $request->post('delta', 0);
        if (abs($delta) < 0.000001) {
            throw new ValidationException('Enter a non-zero amount.');
        }
        (new UserRepository(App::db()))->adjustBalance($id, $delta, 'admin', (string) $request->post('note', ''), (int) $admin['id']);
        $this->audit('user.balance', 'user', (string) $id, ['delta' => $delta]);
        flash('success', 'Balance adjusted.');
        Response::redirect('/admin/users/' . $id);
    }

    public function createKey(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        if ((new UserRepository(App::db()))->find($id) === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }
        $created = (new ApiKeyRepository(App::db()))->create($id, 'admin-created', null);
        Session::put('admin_new_key', $created['key']);
        $this->audit('api_key.create_admin', 'api_key', (string) $created['row']['id'], ['user_id' => $id]);
        flash('success', 'Key created. Copy it now; it is shown only once.');
        Response::redirect('/admin/users/' . $id);
    }

    public function revokeKey(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $userId = (int) ($params['userId'] ?? 0);
        (new ApiKeyRepository(App::db()))->revokeAsAdmin($id);
        $this->audit('api_key.revoke_admin', 'api_key', (string) $id);
        flash('success', 'Key revoked.');
        Response::redirect('/admin/users/' . $userId);
    }
}
