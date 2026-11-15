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
use Relay\Repositories\ModelRepository;
use Relay\Services\ModelSyncService;

final class ModelController extends Controller
{
    public function index(Request $request): never
    {
        Auth::requireAdmin();
        $repo = new ModelRepository(App::db());
        $this->render('admin/models', [
            'activeNav' => 'models',
            'models' => $repo->all(),
            'aliases' => $repo->aliases(),
            'lastSync' => $repo->lastSync(),
            'syncRuns' => $repo->syncRuns(10),
        ], 'layouts/admin');
    }

    public function sync(Request $request): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $service = new ModelSyncService(App::db(), App::settings(), new ModelRepository(App::db()));
        $result = $service->sync();
        if ($result['status'] === 'success') {
            flash('success', 'Model catalog refreshed: ' . $result['free'] . ' free models (' . $result['changed'] . ' changed).');
        } else {
            flash('error', 'Model sync failed: ' . ($result['message'] ?? $result['status']));
        }
        $this->audit('models.sync', 'model', 'catalog', $result);
        Response::redirect('/admin/models');
    }

    public function update(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $id = (string) ($params['id'] ?? '');
        $repo = new ModelRepository(App::db());
        if ($repo->find($id) === null) {
            throw new HttpException(404, 'Model not found.', 'model_not_found');
        }
        $data = [
            'enabled' => (string) $request->post('enabled', '0') === '1',
            'credit_per_request' => max(0, (float) $request->post('credit_per_request', 0)),
            'credit_per_1k_input' => max(0, (float) $request->post('credit_per_1k_input', 0)),
            'credit_per_1k_output' => max(0, (float) $request->post('credit_per_1k_output', 0)),
            'credit_multiplier' => max(0.0001, (float) $request->post('credit_multiplier', 1)),
            'sort_order' => (int) $request->post('sort_order', 0),
        ];
        $repo->updateConfig($id, $data);
        $this->audit('model.update', 'model', $id, $data);
        flash('success', 'Model settings saved.');
        Response::redirect('/admin/models');
    }

    public function addAlias(Request $request): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $alias = trim((string) $request->post('alias', ''));
        $modelId = trim((string) $request->post('model_id', ''));
        if (!preg_match('/^[A-Za-z0-9_.:\/-]{1,100}$/', $alias)) {
            throw new ValidationException('Invalid alias.');
        }
        $repo = new ModelRepository(App::db());
        if ($repo->find($modelId) === null) {
            throw new ValidationException('Model does not exist.');
        }
        $repo->addAlias($alias, $modelId);
        $this->audit('model.alias_add', 'model', $modelId, ['alias' => $alias]);
        flash('success', 'Alias saved.');
        Response::redirect('/admin/models');
    }

    public function removeAlias(Request $request, array $params): never
    {
        Auth::requireAdmin();
        $this->validateCsrf($request);
        $alias = (string) ($params['alias'] ?? '');
        (new ModelRepository(App::db()))->removeAlias($alias);
        $this->audit('model.alias_remove', 'model', $alias);
        flash('success', 'Alias removed.');
        Response::redirect('/admin/models');
    }
}
