<?php

declare(strict_types=1);

use Relay\App;
use Relay\Services\CronService;

require dirname(__DIR__) . '/app/bootstrap.php';

$task = $argv[1] ?? 'all';
if (!in_array($task, ['all', 'models', 'cleanup'], true)) {
    fwrite(STDERR, "Usage: php bin/cron.php [all|models|cleanup]\n");
    exit(1);
}

$service = new CronService(App::db(), App::settings());
$result = $service->run($task);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($result['status'] ?? 'error') === 'error' ? 1 : 0);
