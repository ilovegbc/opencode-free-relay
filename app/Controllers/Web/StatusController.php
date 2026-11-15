<?php

declare(strict_types=1);

namespace Relay\Controllers\Web;

use Relay\App;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\ModelRepository;

final class StatusController extends Controller
{
    public function index(Request $request): never
    {
        $dbOk = App::db()->healthy();
        $models = [];
        $pool = [];
        $lastSync = null;
        $stats = [];
        try {
            $models = (new ModelRepository(App::db()))->publicList();
            $pool = (new IpPoolRepository(App::db()))->summary();
            $lastSync = (new ModelRepository(App::db()))->lastSync();
            $stats = (new LogRepository(App::db()))->dashboard();
        } catch (\Throwable) {
        }
        $this->render('status', [
            'activeNav' => 'status',
            'dbOk' => $dbOk,
            'models' => $models,
            'pool' => $pool,
            'lastSync' => $lastSync,
            'stats' => $stats,
        ]);
    }
}
