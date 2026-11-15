<?php

declare(strict_types=1);

namespace Relay\Controllers\Web;

use Relay\App;
use Relay\Controllers\Controller;
use Relay\Http\Request;
use Relay\Repositories\ModelRepository;

final class DocsController extends Controller
{
    public function index(Request $request): never
    {
        $models = [];
        try {
            $models = (new ModelRepository(App::db()))->publicList();
        } catch (\Throwable) {
        }
        $this->render('docs', [
            'activeNav' => 'docs',
            'models' => $models,
            'baseUrl' => rtrim((string) App::config('app.url', ''), '/'),
        ]);
    }
}
