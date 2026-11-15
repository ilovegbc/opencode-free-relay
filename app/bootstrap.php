<?php

declare(strict_types=1);

use Relay\App;
use Relay\Support\Config;
use Relay\Support\Env;
use Relay\Support\Hook;
use Relay\Support\Logger;

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Relay\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(BASE_PATH . '/.env');

$config = new Config(require BASE_PATH . '/config/config.php');
App::boot($config, BASE_PATH);

date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

$debug = $config->bool('app.debug', false);
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '0');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    Logger::error('Unhandled exception', [
        'type' => $e::class,
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $debug ? $e->getTraceAsString() : null,
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = [
        'error' => [
            'message' => $debug ? $e->getMessage() : 'Internal server error',
            'type' => 'server_error',
            'code' => 'internal_error',
        ],
    ];
    if (defined('RELAY_API_REQUEST') && RELAY_API_REQUEST) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Server error</title><body style="font-family:system-ui;background:#111;color:#eee;padding:3rem">';
    echo '<h1>Server error</h1><p>' . htmlspecialchars($debug ? $e->getMessage() : 'Please check the application log.', ENT_QUOTES, 'UTF-8') . '</p></body>';
});

// Local plugins are optional, autoloaded only when present. This is the
// supported extension point for forks and private customisations.
if (is_dir(BASE_PATH . '/app/plugins')) {
    foreach (glob(BASE_PATH . '/app/plugins/*.php') ?: [] as $plugin) {
        require_once $plugin;
    }
}

Hook::fire('app.booted');
