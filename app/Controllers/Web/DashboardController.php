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

    public function deleteKey(Request $request, array $params): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        if ((new ApiKeyRepository(App::db()))->delete($id, (int) $user['id'])) {
            $this->audit('api_key.delete', 'api_key', (string) $id);
            flash('success', 'API Key 已删除，立即失效。');
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

    public function invites(Request $request): never
    {
        $user = Auth::requireUser();
        $codes = new CodeRepository(App::db());
        $this->render('dashboard/invites', [
            'activeNav' => 'invites',
            'invites' => $codes->invitesForUser((int) $user['id']),
            'newCode' => Session::pull('new_invite_code'),
        ]);
    }

    public function createInvite(Request $request): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $created = (new CodeRepository(App::db()))->createInvite([
            'label' => trim((string) $request->post('label', '')),
            'plan_id' => null,
            'expires_at' => null,
            'active' => true,
        ], (int) $user['id']);
        Session::put('new_invite_code', $created['code']);
        $this->audit('invite.create', 'invite', (string) $created['row']['id']);
        flash('success', '邀请码已生成。复制保存，只显示一次。');
        Response::redirect('/dashboard/invites');
    }

    public function disableInvite(Request $request, array $params): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        if ((new CodeRepository(App::db()))->disableInviteForUser($id, (int) $user['id'])) {
            $this->audit('invite.disable', 'invite', (string) $id);
            flash('success', '邀请码已停用。');
        } else {
            flash('error', '邀请码不存在。');
        }
        Response::redirect('/dashboard/invites');
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

    public function deleteAccount(Request $request): never
    {
        $user = Auth::requireUser();
        $this->validateCsrf($request);
        $password = (string) $request->post('password', '');
        if (!Crypto::passwordVerify($password, (string) $user['password_hash'])) {
            flash('error', '当前密码不正确，账户未删除。');
            Response::redirect('/dashboard/settings');
        }
        if ((string) $request->post('confirm', '') !== 'DELETE') {
            flash('error', '请在确认框输入 DELETE 后再提交。');
            Response::redirect('/dashboard/settings');
        }
        $repo = new UserRepository(App::db());
        if (($user['role'] ?? 'user') === 'admin' && $repo->countAdmins() <= 1) {
            flash('error', '这是最后一个管理员账户，无法注销。');
            Response::redirect('/dashboard/settings');
        }
        $this->audit('user.self_delete', 'user', (string) $user['id'], ['username' => $user['username']]);
        $repo->delete((int) $user['id']);
        Auth::logout();
        Response::redirect('/login');
    }
}
