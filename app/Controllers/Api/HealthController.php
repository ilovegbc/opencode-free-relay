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
        $ok = false;
        try {
            $ok = App::db()->healthy()
                && (new ModelRepository(App::db()))->countFree() > 0
                && (int) (((new IpPoolRepository(App::db()))->summary()['enabled_pools'] ?? 0)) > 0;
        } catch (\Throwable) {
            $ok = false;
        }
        Response::json(['status' => $ok ? 'ok' : 'degraded'], $ok ? 200 : 503);
    }
}
