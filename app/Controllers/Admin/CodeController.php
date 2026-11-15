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
            'redemptions' => $codes->redemptions(),
            'plans' => (new PlanRepository(App::db()))->all(),
            'newCode' => Session::pull('admin_new_code'),
        ], 'layouts/admin');
    }

    public function createInvite(Request $request): never
    {
        $admin = Auth::requireAdmin();
        $this->validateCsrf($request);
        $max = max(1, (int) $request->post('max_uses', 1));
        $planId = (int) $request->post('plan_id', 0) ?: null;
        if ($planId !== null && (new PlanRepository(App::db()))->find($planId) === null) {
            throw new ValidationException('Invalid plan.');
        }
        $created = (new CodeRepository(App::db()))->createInvite([
            'label' => trim((string) $request->post('label', '')),
            'plan_id' => $planId,
            'max_uses' => $max,
            'initial_credits' => max(0, (float) $request->post('initial_credits', 0)),
            'expires_at' => $this->dateOrNull($request->post('expires_at')),
            'active' => true,
        ], (int) $admin['id']);
        Session::put('admin_new_code', $created['code']);
        $this->audit('invite.create', 'invite', (string) $created['row']['id'], ['max_uses' => $max]);
        flash('success', 'Invite code created. Copy it now.');
        Response::redirect('/admin/codes');
    }

    public function createRedemption(Request $request): never
    {
        $admin = Auth::requireAdmin();
        $this->validateCsrf($request);
        $credits = (float) $request->post('credits', 0);
        if ($credits <= 0) {
            throw new ValidationException('Credits must be greater than zero.');
        }
        $created = (new CodeRepository(App::db()))->createRedemption([
            'credits' => $credits,
            'max_uses' => max(1, (int) $request->post('max_uses', 1)),
            'expires_at' => $this->dateOrNull($request->post('expires_at')),
            'note' => trim((string) $request->post('note', '')),
            'active' => true,
        ], (int) $admin['id']);
        Session::put('admin_new_code', $created['code']);
        $this->audit('redemption.create', 'redemption', (string) $created['row']['id'], ['credits' => $credits]);
        flash('success', 'Redemption code created. Copy it now.');
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

    public function disableRedemption(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        (new CodeRepository(App::db()))->disableRedemption((int) ($params['id'] ?? 0));
        $this->audit('redemption.disable', 'redemption', (string) ($params['id'] ?? ''));
        flash('success', 'Redemption code disabled.');
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
