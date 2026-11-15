<?php

declare(strict_types=1);

use Relay\App;
use Relay\Repositories\IpPoolRepository;
use Relay\Repositories\ModelRepository;
use Relay\Services\IpProbe;
use Relay\Support\Ipv6;

require dirname(__DIR__) . '/app/bootstrap.php';

$probe = in_array('--probe', $argv, true);
$json = in_array('--json', $argv, true);
$results = [];
$critical = false;

$add = static function (string $name, bool $ok, string $detail, bool $criticalCheck = false) use (&$results, &$critical): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'critical' => $criticalCheck];
    if ($criticalCheck && !$ok) {
        $critical = true;
    }
};

$add('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION, true);
foreach (['pdo_pgsql' => true, 'curl' => true, 'openssl' => true, 'mbstring' => true, 'json' => true, 'sockets' => false, 'bcmath' => false] as $extension => $required) {
    $add('extension ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing', $required);
}
$appKey = (string) App::config('app.key', '');
$add('APP_KEY', strlen($appKey) >= 32 && !str_contains($appKey, 'change-me'), strlen($appKey) >= 32 ? 'configured' : 'missing or placeholder', true);

$dbOk = false;
try {
    $dbOk = App::db()->healthy();
} catch (Throwable) {
}
$add('PostgreSQL connection', $dbOk, $dbOk ? 'connected' : 'failed', true);

$pools = [];
$models = 0;
$lastSync = null;
if ($dbOk) {
    try {
        $settings = App::db()->scalar("SELECT to_regclass('public.settings')");
        $add('schema migrated', $settings !== null, $settings !== null ? 'settings table exists' : 'run php bin/migrate.php', true);
        $pools = (new IpPoolRepository(App::db()))->all();
        $models = (new ModelRepository(App::db()))->countFree();
        $lastSync = (new ModelRepository(App::db()))->lastSync();
    } catch (Throwable $e) {
        $add('schema readable', false, $e->getMessage(), true);
    }
}
$enabled = array_values(array_filter($pools, static fn (array $p): bool => (bool) $p['enabled']));
$add('enabled IPv6 pools', $enabled !== [], count($enabled) . ' of ' . count($pools) . ' enabled', true);
$add('free models', $models > 0, $models . ' available', true);
$add('model sync recent', $lastSync !== null && strtotime((string) $lastSync['finished_at']) > time() - 7200, $lastSync['finished_at'] ?? 'never', false);

$host = parse_url((string) App::settings()->get('upstream.models_url', 'https://opencode.ai/inference/v1/models'), PHP_URL_HOST) ?: 'opencode.ai';
$aaaa = @dns_get_record($host, DNS_AAAA);
$add('target has AAAA: ' . $host, is_array($aaaa) && $aaaa !== [], is_array($aaaa) && $aaaa !== [] ? ((string) ($aaaa[0]['ipv6'] ?? 'yes')) : 'DNS failed or no AAAA', true);

$sysctl = @file_get_contents('/proc/sys/net/ipv6/ip_nonlocal_bind');
$add('net.ipv6.ip_nonlocal_bind', $sysctl !== false && trim($sysctl) === '1', $sysctl === false ? 'not readable (expected on Windows/containers)' : trim($sysctl), false);

if ($probe && $enabled !== []) {
    $prober = new IpProbe(new IpPoolRepository(App::db()));
    $url = (string) App::settings()->get('ipv6.probe_url', 'https://opencode.ai/inference/v1/models');
    foreach ($enabled as $pool) {
        $result = $prober->probe($url, $pool);
        $add('probe ' . $pool['name'] . ' (' . $pool['cidr'] . ')', (bool) $result['ok'], $result['message'] . ' via ' . $result['address'] . ' -> ' . $result['target'] . ' [' . $result['latency_ms'] . 'ms]', false);
    }
}

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    foreach ($results as $result) {
        printf("[%s] %-42s %s\n", $result['ok'] ? 'PASS' : 'FAIL', $result['name'], $result['detail']);
    }
    echo "\n";
    echo $critical ? "Critical checks failed.\n" : "Critical checks passed.\n";
    if ($enabled === []) {
        echo "Add an IPv6 pool in Admin → IPv6 pools before sending API traffic.\n";
    }
    if ($models === 0) {
        echo "Run php bin/cron.php models to populate the free model catalog.\n";
    }
}
exit($critical ? 1 : 0);
