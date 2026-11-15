<?php

declare(strict_types=1);

use Relay\App;

require dirname(__DIR__) . '/app/bootstrap.php';

$manual = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--version=')) {
        $manual = substr($arg, 10);
    }
}

function fetch_ipv6_json(string $url): ?array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V6,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Encoding: identity',
            'User-Agent: OpenCode-Free-Relay/1.0 (+self-hosted)',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

$version = $manual;
if ($version === null) {
    $sources = [
        'https://registry.npmjs.org/@opencode-ai%2Fcli/latest',
        'https://registry.npmjs.org/opencode-ai/latest',
        'https://api.github.com/repos/anomalyco/opencode/releases/latest',
        'https://api.github.com/repos/sst/opencode/releases/latest',
    ];
    foreach ($sources as $url) {
        $data = fetch_ipv6_json($url);
        if ($data === null) {
            continue;
        }
        $candidate = $data['version'] ?? $data['tag_name'] ?? null;
        if (is_string($candidate) && preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?)$/', $candidate, $m)) {
            $version = $m[1];
            echo "Resolved from {$url}: {$version}\n";
            break;
        }
    }
}

if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version)) {
    fwrite(STDERR, "Could not resolve a version. Use: php bin/sync-client-version.php --version=2.3.0\n");
    exit(1);
}

App::settings()->set('upstream.client_version', $version);
echo "Updated upstream.client_version to {$version}.\n";
echo "Effective User-Agent: opencode/" . App::settings()->string('upstream.client_channel', 'latest') . "/{$version}/" . App::settings()->string('upstream.client_name', 'cli') . "\n";
