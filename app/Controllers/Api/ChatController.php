<?php

declare(strict_types=1);

namespace Relay\Controllers\Api;

use Relay\App;
use Relay\Auth\ApiAuthenticator;
use Relay\Controllers\Controller;
use Relay\Http\HttpException;
use Relay\Http\Request;
use Relay\Http\ValidationException;
use Relay\Repositories\ApiKeyRepository;
use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\LogRepository;
use Relay\Repositories\ModelRepository;
use Relay\Services\BillingService;
use Relay\Services\IdentityResolver;
use Relay\Services\IpAllocator;
use Relay\Services\OpenCodeClient;
use Relay\Services\QuotaService;
use Relay\Services\RelayService;

final class ChatController extends Controller
{
    public function chat(Request $request): never
    {
        $this->relay($request, 'chat', '/v1/chat/completions');
    }

    public function messages(Request $request): never
    {
        $this->relay($request, 'messages', '/v1/messages');
    }

    public function responses(Request $request): never
    {
        $this->relay($request, 'responses', '/v1/responses');
    }

    public function me(Request $request): never
    {
        $auth = (new ApiAuthenticator(new ApiKeyRepository(App::db())))->authenticate($request);
        $user = $auth['user'];
        $quota = new QuotaService(App::db(), App::settings(), new ApiKeyRepository(App::db()));
        \Relay\Http\Response::json([
            'object' => 'relay.account',
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'plan' => $user['plan_code'],
                'balance' => (float) $user['balance'],
            ],
            'key' => [
                'id' => (int) $auth['key']['id'],
                'name' => $auth['key']['name'],
                'prefix' => $auth['key']['prefix'],
            ],
            'limits' => [
                'daily_requests' => (int) $user['daily_requests'],
                'daily_tokens' => (int) $user['daily_tokens'],
                'rpm' => (int) $user['rpm'],
                'concurrency' => (int) $user['concurrency'],
            ],
            'usage_today' => $quota->today((int) $user['id']),
        ]);
    }

    private function relay(Request $request, string $kind, string $endpoint): never
    {
        $auth = (new ApiAuthenticator(new ApiKeyRepository(App::db())))->authenticate($request);
        $user = $auth['user'];
        $key = $auth['key'];
        $body = $request->json();

        $requestedModel = $body['model'] ?? null;
        if (!is_string($requestedModel) || trim($requestedModel) === '') {
            throw new ValidationException('The "model" field is required.', ['model' => 'Required']);
        }
        $model = (new ModelRepository(App::db()))->resolve($requestedModel);
        if ($model === null) {
            throw new HttpException(404, 'Unknown model: ' . $requestedModel, 'model_not_found');
        }
        if (!$model['is_free']) {
            throw new HttpException(403, 'This relay only serves OpenCode free models.', 'model_not_free');
        }
        if (!$model['enabled'] || !$model['available']) {
            throw new HttpException(403, 'This model is currently disabled or unavailable.', 'model_disabled');
        }
        if ((string) $model['upstream_kind'] !== $kind) {
            throw new HttpException(400, 'Model "' . $model['id'] . '" is exposed via ' . $model['upstream_kind'] . ', not ' . $kind . '.', 'wrong_endpoint');
        }
        $allowed = pg_text_array($user['allowed_models'] ?? null);
        if ($allowed !== null && !in_array((string) $model['id'], $allowed, true)) {
            throw new HttpException(403, 'This model is not enabled for your plan.', 'model_forbidden');
        }
        if (!isset($body['messages']) && !isset($body['input'])) {
            throw new ValidationException('The request must contain messages or input.', ['messages' => 'Required']);
        }

        $db = App::db();
        $settings = App::settings();
        $apiKeys = new ApiKeyRepository($db);
        $quota = new QuotaService($db, $settings, $apiKeys);
        $allocator = new IpAllocator($db, new IpPoolRepository($db), $settings);
        $client = new OpenCodeClient($settings);
        $billing = new BillingService($settings);
        $logs = new LogRepository($db);
        $relay = new RelayService(
            $settings,
            new IdentityResolver($settings),
            $allocator,
            $client,
            $quota,
            $billing,
            $logs,
        );
        $relay->handle($request, $user, $key, $model, $kind, $endpoint, $body);
    }
}
