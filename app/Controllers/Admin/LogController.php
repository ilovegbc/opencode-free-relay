<?php

declare(strict_types=1);

namespace Relay\Controllers\Admin;

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Repositories\LogRepository;

final class LogController extends Controller
{
    public function requests(Request $request): never
    {
        Auth::requireAdmin();
        $page = $this->page($request, 50);
        $filters = [
            'user_id' => $request->query('user_id') ?: null,
            'model' => $request->query('model') ?: null,
            'status' => $request->query('status') ?: null,
            'ip' => $request->query('ip') ?: null,
        ];
        $filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
        $repo = new LogRepository(App::db());
        $logs = $repo->paginate($filters, $page['limit'], $page['offset']);
        $total = $repo->count($filters);
        [$current, $pages] = $this->paginationMeta($total, $page);
        $this->render('admin/logs', [
            'activeNav' => 'logs',
            'logs' => $logs,
            'filters' => $filters,
            'page' => $current,
            'pages' => $pages,
        ], 'layouts/admin');
    }

    public function auditLogs(Request $request): never
    {
        Auth::requireAdmin();
        $page = $this->page($request, 50);
        $repo = new LogRepository(App::db());
        $this->render('admin/audit', [
            'activeNav' => 'audit',
            'logs' => $repo->auditList($page['limit'], $page['offset']),
            'page' => $page['page'],
            'pages' => max(1, (int) ceil((int) App::db()->scalar('SELECT count(*) FROM audit_logs') / $page['limit'])),
        ], 'layouts/admin');
    }
}
