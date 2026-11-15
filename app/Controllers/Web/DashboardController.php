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
use Relay\Repositories\ApiKeyRepository;
use Relay\Repositories\CodeRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\ModelRepository;
use Relay\Repositories\PlanRepository;
use Relay\Repositories\UserRepository;
use Relay\Services\QuotaService;
use Relay\Support\Crypto;
use Relay\Support\Session;

final class DashboardController extends Controller
{
    public function index(Request $request): never
    {
        $user = Auth::requireUser();
        $db = App::db();
        $plan = (new PlanRepository($db))->find((int) ($user['plan_id'] ?? 0));
        $quota = new QuotaService($db, App::settings(), new ApiKeyRepository($db));
        $usage = $quota->today((int) $user['id']);
        $keys = (new ApiKeyRepository($db))->forUser((int) $user['id']);
        $logs = (new LogRepository($db))->usageForUser((int) $user['id'], 8);
        $models = (new ModelRepository($db))->publicList(pg_text_array($user['allowed_models'] ?? null));
        $this->render('dashboard/index', [
            'activeNav' => 'dashboard',
            'plan' => $plan,
            'usage' => $usage,
            'keys' => $keys,
            'logs' => $logs,
            'models' => $models,
        ]);
    }

    public function keys(Request $request): never
    {
        $user = Auth::requireUser();
        $keys = (new ApiKeyRepository(App::db()))->forUser((int) $user['id']);
        $newKey = Session::pull('new_api_key');
        $this->render('dashboard/keys', [
            'activeNav' => 'keys',
            'keys' => $keys,
            'newKey' => is_string($newKey) ? $newKey : null,
        ]);
    }

    public function createKey(Request $request): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $name = trim((string) $request->post('name', 'default'));
        if ($name === '' || mb_strlen($name) > 64) {
            throw new ValidationException('Key name must be 1–64 characters.');
        }
        $max = (int) setting('security.max_keys_per_user', 10);
        $repo = new ApiKeyRepository(App::db());
        if ($repo->activeCountForUser((int) $user['id']) >= max(1, $max)) {
            flash('error', 'You have reached the maximum number of active keys.');
            Response::redirect('/dashboard/keys');
        }
        $days = (int) $request->post('expires_days', 0);
        $expires = $days > 0 ? gmdate('Y-m-d H:i:s+00:00', time() + $days * 86400) : null;
        $created = $repo->create((int) $user['id'], $name, $expires);
        Session::put('new_api_key', $created['key']);
        $this->audit('api_key.create', 'api_key', (string) $created['row']['id'], ['name' => $name]);
        flash('success', 'API key created. Copy it now — it will not be shown again in full.');
        Response::redirect('/dashboard/keys');
    }

    public function revokeKey(Request $request, array $params): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        if ((new ApiKeyRepository(App::db()))->revoke($id, (int) $user['id'])) {
            $this->audit('api_key.revoke', 'api_key', (string) $id);
            flash('success', 'API key revoked.');
        } else {
            flash('error', 'Key not found.');
        }
        Response::redirect('/dashboard/keys');
    }

    public function usage(Request $request): never
    {
        $user = Auth::requireUser();
        $page = $this->page($request, 30);
        $filters = ['user_id' => (int) $user['id']];
        $logs = (new LogRepository(App::db()))->paginate($filters, $page['limit'], $page['offset']);
        $total = (new LogRepository(App::db()))->count($filters);
        [$current, $pages] = $this->paginationMeta($total, $page);
        $series = (new LogRepository(App::db()))->dailySeries(14, (int) $user['id']);
        $this->render('dashboard/usage', [
            'activeNav' => 'usage',
            'logs' => $logs,
            'series' => $series,
            'page' => $current,
            'pages' => $pages,
        ]);
    }

    public function redeemForm(Request $request): never
    {
        Auth::requireUser();
        $this->render('dashboard/redeem', ['activeNav' => 'redeem']);
    }

    public function redeem(Request $request): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $code = trim((string) $request->post('code', ''));
        if ($code === '') {
            throw new ValidationException('Enter a redemption code.');
        }
        $result = (new CodeRepository(App::db()))->redeem($code, (int) $user['id']);
        if ($result === null) {
            flash('error', 'That code is invalid, expired, exhausted, or already used by you.');
        } else {
            $this->audit('credit.redeem', 'user', (string) $user['id'], ['credits' => $result['credits']]);
            flash('success', 'Redeemed ' . format_credits($result['credits']) . ' credits. New balance: ' . format_credits($result['balance']) . '.');
        }
        Response::redirect('/dashboard/redeem');
    }

    public function settings(Request $request): never
    {
        $user = Auth::requireUser();
        $this->render('dashboard/settings', ['activeNav' => 'settings']);
    }

    public function updatePassword(Request $request): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $current = (string) $request->post('current_password', '');
        $password = (string) $request->post('password', '');
        $confirmation = (string) $request->post('password_confirmation', '');
        if (!Crypto::passwordVerify($current, (string) $user['password_hash'])) {
            throw new ValidationException('Current password is incorrect.', ['current_password' => 'Incorrect']);
        }
        if (strlen($password) < 8) {
            throw new ValidationException('New password must be at least 8 characters.', ['password' => 'Too short']);
        }
        if ($password !== $confirmation) {
            throw new ValidationException('New passwords do not match.', ['password_confirmation' => 'Mismatch']);
        }
        (new UserRepository(App::db()))->update((int) $user['id'], ['password_hash' => Crypto::passwordHash($password)]);
        Auth::refresh();
        $this->audit('user.password_change', 'user', (string) $user['id']);
        flash('success', 'Password updated.');
        Response::redirect('/dashboard/settings');
    }
}
