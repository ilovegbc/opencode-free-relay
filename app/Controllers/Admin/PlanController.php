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
use Relay\Repositories\PlanRepository;

final class PlanController extends Controller
{
    public function index(Request $request): never
    {
        Auth::requireAdmin();
        $repo = new PlanRepository(App::db());
        $plans = $repo->all();
        foreach ($plans as &$plan) {
            $plan['user_count'] = $repo->userCount((int) $plan['id']);
        }
        unset($plan);
        $this->render('admin/plans', [
            'activeNav' => 'plans',
            'plans' => $plans,
        ], 'layouts/admin');
    }

    public function create(Request $request): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $data = $this->payload($request);
        if ((new PlanRepository(App::db()))->findByCode($data['code']) !== null) {
            throw new ValidationException('Plan code already exists.');
        }
        $plan = (new PlanRepository(App::db()))->create($data);
        $this->audit('plan.create', 'plan', (string) $plan['id'], ['code' => $plan['code']]);
        flash('success', 'Plan created.');
        Response::redirect('/admin/plans');
    }

    public function update(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $repo = new PlanRepository(App::db());
        if ($repo->find($id) === null) {
            throw new HttpException(404, 'Plan not found.', 'plan_not_found');
        }
        $repo->update($id, $this->payload($request));
        $this->audit('plan.update', 'plan', (string) $id);
        flash('success', 'Plan updated.');
        Response::redirect('/admin/plans');
    }

    public function destroy(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (int) ($params['id'] ?? 0);
        $repo = new PlanRepository(App::db());
        $plan = $repo->find($id);
        if ($plan === null) {
            throw new HttpException(404, 'Plan not found.', 'plan_not_found');
        }
        if (!empty($plan['is_default'])) {
            flash('error', '默认套餐不能删除，请先把其他套餐设为默认。');
            Response::redirect('/admin/plans');
        }
        if ($repo->userCount($id) > 0) {
            flash('error', '该套餐仍有用户在使用，无法删除。');
            Response::redirect('/admin/plans');
        }
        $repo->delete($id);
        $this->audit('plan.delete', 'plan', (string) $id, ['code' => $plan['code']]);
        flash('success', '套餐已删除。');
        Response::redirect('/admin/plans');
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $code = trim((string) $request->post('code', ''));
        $name = trim((string) $request->post('name', ''));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,32}$/', $code)) {
            throw new ValidationException('Plan code must be 2–33 lowercase letters, digits, underscores or hyphens.');
        }
        if ($name === '') {
            throw new ValidationException('Plan name is required.');
        }
        $allowed = trim((string) $request->post('allowed_models', ''));
        return [
            'code' => $code,
            'name' => $name,
            'description' => (string) $request->post('description', ''),
            'daily_requests' => (int) $request->post('daily_requests', 200),
            'daily_tokens' => (int) $request->post('daily_tokens', 5000000),
            'rpm' => max(0, (int) $request->post('rpm', 30)),
            'concurrency' => max(1, (int) $request->post('concurrency', 3)),
            'allowed_models' => $allowed === '' ? null : array_values(array_filter(array_map('trim', explode(',', $allowed)))),
            'is_default' => (string) $request->post('is_default', '0') === '1',
            'active' => (string) $request->post('active', '1') === '1',
            'sort_order' => (int) $request->post('sort_order', 0),
        ];
    }
}
