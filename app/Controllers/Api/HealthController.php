<?php

declare(strict_types=1);

namespace Relay\Controllers\Api;

use Relay\App;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\ModelRepository;

final class HealthController
{
    public function index(Request $request): never
    {
        $dbOk = App::db()->healthy();
        $models = 0;
        $pools = ['enabled_pools' => 0];
        $lastSync = null;
        try {
            $models = (new ModelRepository(App::db()))->countFree();
            $pools = (new IpPoolRepository(App::db()))->summary();
            $lastSync = (new ModelRepository(App::db()))->lastSync();
        } catch (\Throwable) {
        }
        $ok = $dbOk && $models > 0 && (int) ($pools['enabled_pools'] ?? 0) > 0;
        Response::json([
            'status' => $ok ? 'ok' : 'degraded',
            'database' => $dbOk,
            'free_models' => $models,
            'enabled_ipv6_pools' => (int) ($pools['enabled_pools'] ?? 0),
            'last_model_sync' => $lastSync['finished_at'] ?? null,
            'ipv6_only' => true,
            'ipv4_fallback' => false,
            'time' => gmdate('c'),
        ], $ok ? 200 : 503);
    }
}
