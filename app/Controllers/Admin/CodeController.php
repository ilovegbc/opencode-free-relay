<?php

declare(strict_types=1);

namespace Relay\Controllers\Admin;

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Http\ValidationException;
use Relay\Repositories\CodeRepository;
use Relay\Repositories\PlanRepository;
use Relay\Support\Session;

final class CodeController extends Controller
{
    public function index(Request $request): never
    {
        Auth::requireAdmin();
        $codes = new CodeRepository(App::db());
        $this->render('admin/codes', [
            'activeNav' => 'codes',
            'invites' => $codes->invites(),
            'plans' => (new PlanRepository(App::db()))->all(),
            'newCode' => Session::pull('admin_new_code'),
        ], 'layouts/admin');
    }

    public function createInvite(Request $request): never
    {
        $admin = Auth::requireAdmin();
        $this->validateCsrf($request);
        $planId = (int) $request->post('plan_id', 0) ?: null;
        if ($planId !== null && (new PlanRepository(App::db()))->find($planId) === null) {
            throw new ValidationException('Invalid plan.');
        }
        $created = (new CodeRepository(App::db()))->createInvite([
            'label' => trim((string) $request->post('label', '')),
            'plan_id' => $planId,
            'expires_at' => $this->dateOrNull($request->post('expires_at')),
            'active' => true,
        ], (int) $admin['id']);
        Session::put('admin_new_code', $created['code']);
        $this->audit('invite.create', 'invite', (string) $created['row']['id'], ['plan_id' => $planId]);
        flash('success', 'Invite code created. Copy it now.');
        Response::redirect('/admin/codes');
    }

    public function disableInvite(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        (new CodeRepository(App::db()))->disableInvite((int) ($params['id'] ?? 0));
        $this->audit('invite.disable', 'invite', (string) ($params['id'] ?? ''));
        flash('success', 'Invite disabled.');
        Response::redirect('/admin/codes');
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ValidationException('Invalid date.');
        }
        return gmdate('Y-m-d H:i:s+00:00', $timestamp);
    }
}
