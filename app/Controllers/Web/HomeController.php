<?php

declare(strict_types=1);

namespace Relay\Controllers\Web;

use Relay\App;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Repositories\ModelRepository;

final class HomeController extends Controller
{
    public function index(Request $request): never
    {
        $models = [];
        try {
            $models = (new ModelRepository(App::db()))->publicList();
        } catch (\Throwable) {
            // Before migration: still render the landing page.
        }

        $this->render('home', [
            'activeNav' => 'home',
            'models' => $models,
            'registrationMode' => (string) setting('site.registration_mode', 'open'),
        ]);
    }
}
