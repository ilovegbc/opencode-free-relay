<?php

declare(strict_types=1);

use Relay\App;
use Relay\Http\Response;
use Relay\Services\CronService;

require dirname(__DIR__) . '/app/bootstrap.php';

$secret = (string) (getenv('CRON_SECRET') ?: App::settings()->string('cron.secret', ''));
$provided = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
if ($secret === '' || $provided === '' || !hash_equals($secret, $provided)) {
    Response::json(['error' => ['message' => 'Forbidden', 'type' => 'authentication_error', 'code' => 'invalid_cron_token']], 403);
}

$task = (string) ($_GET['task'] ?? 'all');
if (!in_array($task, ['all', 'models', 'cleanup'], true)) {
    Response::json(['error' => ['message' => 'Invalid task', 'type' => 'invalid_request_error', 'code' => 'invalid_task']], 422);
}

$result = (new CronService(App::db(), App::settings()))->run($task);
Response::json($result, ($result['status'] ?? 'error') === 'error' ? 500 : 200);
