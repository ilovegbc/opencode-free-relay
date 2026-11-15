<?php

declare(strict_types=1);

namespace Relay\Controllers\Web;

use Relay\App;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\ModelRepository;

final class HomeController extends Controller
{
    public function index(Request $request): never
    {
        $models = [];
        $pool = ['enabled_pools' => 0, 'active_leases' => 0];
        $lastSync = null;
        try {
            $models = (new ModelRepository(App::db()))->publicList();
            $pool = (new IpPoolRepository(App::db()))->summary();
            $lastSync = (new ModelRepository(App::db()))->lastSync();
        } catch (\Throwable) {
            // Before migration: still render the landing page.
        }

        $this->render('home', [
            'activeNav' => 'home',
            'models' => $models,
            'pool' => $pool,
            'lastSync' => $lastSync,
            'registrationMode' => (string) setting('site.registration_mode', 'open'),
        ]);
    }
}
