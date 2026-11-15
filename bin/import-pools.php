<?php

declare(strict_types=1);

use Relay\App;
use Relay\Repositories\IpPoolRepository;
use Relay\Support\Ipv6;

require dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$file = null;
foreach ($args as $arg) {
    if (!str_starts_with($arg, '--')) {
        $file = $arg;
        break;
    }
}

$content = $file !== null ? @file_get_contents($file) : stream_get_contents(STDIN);
if ($content === false || $content === null) {
    fwrite(STDERR, "Cannot read pool list. Usage: php bin/import-pools.php pools.txt\n");
    exit(1);
}
if ($file === null && trim($content) === '') {
    fwrite(STDERR, "Provide a file or pipe lines on stdin. Format: cidr[,name[,weight[,ttl_seconds[,interface[,notes]]]]]\n");
    exit(1);
}

$repo = new IpPoolRepository(App::db());
$created = 0;
$failed = 0;
foreach (preg_split('/\R/', trim($content)) ?: [] as $lineNumber => $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $parts = array_map('trim', explode(',', $line));
    $cidr = $parts[0] ?? '';
    try {
        $parsed = Ipv6::parseCidr($cidr);
        $data = [
            'cidr' => $parsed['cidr'],
            'name' => $parts[1] ?? $parsed['cidr'],
            'weight' => max(1, (int) ($parts[2] ?? 100)),
            'lease_ttl_seconds' => max(0, (int) ($parts[3] ?? 604800)),
            'interface_name' => ($parts[4] ?? '') !== '' ? $parts[4] : null,
            'notes' => $parts[5] ?? '',
            'enabled' => true,
        ];
        if ($dry) {
            echo "[dry-run] {$data['cidr']}  {$data['name']}\n";
            continue;
        }
        $repo->create($data);
        $created++;
        echo "[added] {$data['cidr']}  {$data['name']}\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "[error] line " . ($lineNumber + 1) . " ({$cidr}): " . $e->getMessage() . "\n");
    }
}

echo "\nCreated: {$created}, failed: {$failed}" . ($dry ? ' (dry run, nothing written)' : '') . "\n";
exit($failed > 0 ? 1 : 0);
