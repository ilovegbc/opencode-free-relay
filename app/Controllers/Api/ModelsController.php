<?php

declare(strict_types=1);

namespace Relay\Controllers\Api;

use Relay\App;
use Relay\Auth\ApiAuthenticator;
use Relay\Controllers\Controller;
use Relay\Http\AuthenticationException;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Repositories\ApiKeyRepository;
use Relay\Repositories\ModelRepository;

final class ModelsController extends Controller
{
    public function index(Request $request): never
    {
        $allowed = null;
        if ($request->bearerToken() !== null) {
            $auth = (new ApiAuthenticator(new ApiKeyRepository(App::db())))->authenticate($request);
            $allowed = pg_text_array($auth['user']['allowed_models'] ?? null);
        } elseif (!App::settings()->bool('site.public_model_list', true)) {
            throw new AuthenticationException();
        }

        $models = (new ModelRepository(App::db()))->publicList($allowed);
        $data = array_map([$this, 'format'], $models);
        Response::json(['object' => 'list', 'data' => $data]);
    }

    public function show(Request $request, array $params): never
    {
        $allowed = null;
        if ($request->bearerToken() !== null) {
            $auth = (new ApiAuthenticator(new ApiKeyRepository(App::db())))->authenticate($request);
            $allowed = pg_text_array($auth['user']['allowed_models'] ?? null);
        } elseif (!App::settings()->bool('site.public_model_list', true)) {
            throw new AuthenticationException();
        }
        $model = (new ModelRepository(App::db()))->resolve((string) ($params['id'] ?? ''));
        if ($model === null || !$model['is_free'] || !$model['enabled'] || !$model['available']) {
            throw new \Relay\Http\HttpException(404, 'Model not found.', 'model_not_found');
        }
        if ($allowed !== null && !in_array((string) $model['id'], $allowed, true)) {
            throw new \Relay\Http\HttpException(403, 'This model is not enabled for your plan.', 'model_forbidden');
        }
        Response::json($this->format($model));
    }

    /** @param array<string,mixed> $model */
    private function format(array $model): array
    {
        $created = isset($model['first_seen_at']) ? (int) strtotime((string) $model['first_seen_at']) : time();
        return [
            'id' => (string) $model['id'],
            'object' => 'model',
            'created' => $created,
            'owned_by' => (string) ($model['provider'] ?? 'opencode'),
            'name' => (string) $model['name'],
            'description' => (string) ($model['description'] ?? ''),
            'context_length' => $model['context_limit'] !== null ? (int) $model['context_limit'] : null,
            'max_output_tokens' => $model['output_limit'] !== null ? (int) $model['output_limit'] : null,
            'pricing' => [
                'prompt' => '0',
                'completion' => '0',
                'request' => (string) ($model['credit_per_request'] ?? '0'),
            ],
            'capabilities' => json_decode((string) ($model['capabilities'] ?? '{}'), true) ?: new \stdClass(),
        ];
    }
}
