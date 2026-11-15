<?php

declare(strict_types=1);

use Relay\App;
use Relay\Auth\Auth;
use Relay\Controllers\Admin\CodeController;
use Relay\Controllers\Admin\DashboardController as AdminDashboardController;
use Relay\Controllers\Admin\LogController as AdminLogController;
use Relay\Controllers\Admin\ModelController as AdminModelController;
use Relay\Controllers\Admin\PlanController as AdminPlanController;
use Relay\Controllers\Admin\PoolController as AdminPoolController;
use Relay\Controllers\Admin\UserController as AdminUserController;
use Relay\Controllers\Api\ChatController;
use Relay\Controllers\Api\HealthController;
use Relay\Controllers\Api\ModelsController;
use Relay\Controllers\Web\AuthController;
use Relay\Controllers\Web\DashboardController;
use Relay\Controllers\Web\DocsController;
use Relay\Controllers\Web\HomeController;
use Relay\Controllers\Web\StatusController;
use Relay\Http\HttpException;
use Relay\Http\Request;
use Relay\Http\Response;
use Relay\Http\Router;
use Relay\Http\ValidationException;
use Relay\Services\Ipv6UnavailableException;
use Relay\Services\UpstreamException;
use Relay\Support\Logger;
use Relay\Support\View;

require dirname(__DIR__) . '/app/bootstrap.php';

$request = new Request();
$path = $request->path();
$isApi = str_starts_with($path, '/v1') || str_starts_with($path, '/api') || $path === '/health';
define('RELAY_API_REQUEST', $isApi);

if ($isApi) {
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, x-api-key, x-session-id, x-session-affinity, x-opencode-session-id, x-opencode-session, x-opencode-project, x-opencode-client, anthropic-version, anthropic-beta, openai-beta, x-stainless-lang, x-stainless-package-version, x-stainless-os, x-stainless-arch, x-stainless-runtime, x-stainless-runtime-version');
    header('Access-Control-Expose-Headers: x-request-id');
    $originSetting = trim((string) setting('security.cors_origin', '*'));
    $origin = $request->header('Origin');
    if ($originSetting === '*') {
        header('Access-Control-Allow-Origin: *');
    } elseif ($origin !== null) {
        $allowed = array_map('trim', explode(',', $originSetting));
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
    if ($request->method() === 'OPTIONS') {
        Response::noContent();
    }
}

$router = new Router();

// ---------------------------------------------------------------------------
// OpenAI-compatible relay API
// ---------------------------------------------------------------------------
$router->get('/health', [new HealthController(), 'index']);
$router->get('/v1/models', [new ModelsController(), 'index']);
$router->get('/v1/models/{id}', [new ModelsController(), 'show']);
$router->get('/v1/me', [new ChatController(), 'me']);
$router->post('/v1/chat/completions', [new ChatController(), 'chat']);
$router->post('/v1/messages', [new ChatController(), 'messages']);
$router->post('/v1/responses', [new ChatController(), 'responses']);

// ---------------------------------------------------------------------------
// Public web
// ---------------------------------------------------------------------------
$router->get('/', [new HomeController(), 'index']);
$router->get('/docs', [new DocsController(), 'index']);
$router->get('/status', [new StatusController(), 'index']);
$router->get('/login', [new AuthController(), 'loginForm']);
$router->post('/login', [new AuthController(), 'login']);
$router->get('/register', [new AuthController(), 'registerForm']);
$router->post('/register', [new AuthController(), 'register']);
$router->post('/logout', [new AuthController(), 'logout']);
$router->get('/logout', [new AuthController(), 'logout']);

// ---------------------------------------------------------------------------
// User dashboard
// ---------------------------------------------------------------------------
$router->get('/dashboard', [new DashboardController(), 'index']);
$router->get('/dashboard/keys', [new DashboardController(), 'keys']);
$router->post('/dashboard/keys', [new DashboardController(), 'createKey']);
$router->post('/dashboard/keys/{id}/revoke', [new DashboardController(), 'revokeKey']);
$router->get('/dashboard/usage', [new DashboardController(), 'usage']);
$router->get('/dashboard/redeem', [new DashboardController(), 'redeemForm']);
$router->post('/dashboard/redeem', [new DashboardController(), 'redeem']);
$router->get('/dashboard/settings', [new DashboardController(), 'settings']);
$router->post('/dashboard/settings/password', [new DashboardController(), 'updatePassword']);

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------
$router->get('/admin', [new AdminDashboardController(), 'index']);
$router->get('/admin/settings', [new AdminDashboardController(), 'settings']);
$router->post('/admin/settings', [new AdminDashboardController(), 'settings']);
$router->get('/admin/announcements', [new AdminDashboardController(), 'announcements']);
$router->post('/admin/announcements', [new AdminDashboardController(), 'announcements']);
$router->post('/admin/announcements/{id}/delete', [new AdminDashboardController(), 'deleteAnnouncement']);
$router->get('/admin/diagnostics', [new AdminDashboardController(), 'diagnostics']);
$router->post('/admin/diagnostics/pools/{id}/probe', [new AdminDashboardController(), 'probePool']);

$router->get('/admin/users', [new AdminUserController(), 'index']);
$router->post('/admin/users', [new AdminUserController(), 'create']);
$router->get('/admin/users/{id}', [new AdminUserController(), 'show']);
$router->post('/admin/users/{id}', [new AdminUserController(), 'update']);
$router->post('/admin/users/{id}/balance', [new AdminUserController(), 'balance']);
$router->post('/admin/users/{id}/keys', [new AdminUserController(), 'createKey']);
$router->post('/admin/users/{userId}/keys/{id}/revoke', [new AdminUserController(), 'revokeKey']);

$router->get('/admin/pools', [new AdminPoolController(), 'index']);
$router->post('/admin/pools', [new AdminPoolController(), 'create']);
$router->post('/admin/pools/{id}', [new AdminPoolController(), 'update']);
$router->post('/admin/pools/{id}/delete', [new AdminPoolController(), 'delete']);
$router->post('/admin/pools/{id}/test', [new AdminPoolController(), 'test']);

$router->get('/admin/models', [new AdminModelController(), 'index']);
$router->post('/admin/models/sync', [new AdminModelController(), 'sync']);
$router->post('/admin/models/aliases', [new AdminModelController(), 'addAlias']);
$router->post('/admin/models/aliases/{alias}/delete', [new AdminModelController(), 'removeAlias']);
$router->post('/admin/models/{id}', [new AdminModelController(), 'update']);

$router->get('/admin/plans', [new AdminPlanController(), 'index']);
$router->post('/admin/plans', [new AdminPlanController(), 'create']);
$router->post('/admin/plans/{id}', [new AdminPlanController(), 'update']);

$router->get('/admin/codes', [new CodeController(), 'index']);
$router->post('/admin/codes/invites', [new CodeController(), 'createInvite']);
$router->post('/admin/codes/redemptions', [new CodeController(), 'createRedemption']);
$router->post('/admin/codes/invites/{id}/disable', [new CodeController(), 'disableInvite']);
$router->post('/admin/codes/redemptions/{id}/disable', [new CodeController(), 'disableRedemption']);

$router->get('/admin/logs', [new AdminLogController(), 'requests']);
$router->get('/admin/audit', [new AdminLogController(), 'audit']);

// ---------------------------------------------------------------------------
// Dispatch + error rendering
// ---------------------------------------------------------------------------
try {
    $router->dispatch($request);
} catch (ValidationException $e) {
    if ($isApi) {
        Response::json([
            'error' => [
                'message' => $e->getMessage(),
                'type' => 'invalid_request_error',
                'code' => $e->errorCode,
                'errors' => $e->errors,
            ],
        ], $e->status);
    }
    Response::html(View::render('errors/error', [
        'title' => 'Invalid request',
        'status' => $e->status,
        'message' => $e->getMessage(),
        'details' => $debug ? $e->getTraceAsString() : null,
    ], 'layouts/app'), $e->status);
} catch (HttpException $e) {
    if ($isApi) {
        Response::apiError($e->getMessage(), $e->status === 401 ? 'authentication_error' : 'invalid_request_error', $e->errorCode, $e->status, $e->headers);
    }
    if ($e->status === 401) {
        Response::redirect('/login');
    }
    Response::html(View::render('errors/error', [
        'title' => $e->status === 403 ? 'Forbidden' : 'Request failed',
        'status' => $e->status,
        'message' => $e->getMessage(),
        'details' => null,
    ], 'layouts/app'), $e->status);
} catch (UpstreamException $e) {
    $status = in_array($e->upstreamStatus, [400, 404, 422, 429], true) ? $e->upstreamStatus : 502;
    if ($e->errorCode === 'free_usage_limit') {
        $status = 429;
    }
    if (str_starts_with($e->errorCode, 'ipv6_')) {
        $status = 503;
    }
    if ($isApi) {
        Response::apiError(
            $e->getMessage(),
            'upstream_error',
            $e->errorCode,
            $status,
            $status === 429 ? ['Retry-After' => '60'] : []
        );
    }
    Response::html(View::render('errors/error', [
        'title' => 'Upstream error',
        'status' => $status,
        'message' => $e->getMessage(),
        'details' => null,
    ], 'layouts/app'), $status);
} catch (Ipv6UnavailableException $e) {
    if ($isApi) {
        Response::apiError($e->getMessage(), 'server_error', $e->reason, 503);
    }
    Response::html(View::render('errors/error', [
        'title' => 'IPv6 unavailable',
        'status' => 503,
        'message' => $e->getMessage(),
        'details' => null,
    ], 'layouts/app'), 503);
} catch (Throwable $e) {
    Logger::error('Unhandled web exception', [
        'type' => $e::class,
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $debug ? $e->getTraceAsString() : null,
    ]);
    if ($isApi) {
        Response::apiError($debug ? $e->getMessage() : 'Internal server error.', 'server_error', 'internal_error', 500);
    }
    Response::html(View::render('errors/error', [
        'title' => 'Server error',
        'status' => 500,
        'message' => $debug ? $e->getMessage() : 'Please check the application log.',
        'details' => $debug ? $e->getTraceAsString() : null,
    ], 'layouts/app'), 500);
}
