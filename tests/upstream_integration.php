<?php

declare(strict_types=1);

use Relay\App;
use Relay\Services\OpenCodeClient;
use Relay\Services\RelayIdentity;
use Relay\Support\Settings;

putenv('APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
require dirname(__DIR__) . '/app/bootstrap.php';

$settings = new Settings(App::db(), [], false, [
    'upstream.allow_http' => true,
    'upstream.verify_tls' => false,
    'upstream.allowed_hosts' => '::1',
    'upstream.key' => 'public',
    'upstream.client_name' => 'cli',
    'upstream.client_channel' => 'latest',
    'upstream.client_version' => '1.19.6',
    'upstream.user_agent' => 'opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4',
    'upstream.timeout' => 15,
    'upstream.connect_timeout' => 5,
    'upstream.stream_include_usage' => true,
    'upstream.prompt_cache_key' => false,
    'upstream.shape_requests' => true,
    'upstream.mirror_client_headers' => true,
    'ipv6.bind_method' => 'interface',
]);

$model = [
    'id' => 'big-pickle',
    'upstream_kind' => 'chat',
    'upstream_base_url' => 'http://[::1]:18080/inference/openai/v1',
];
$identity = new RelayIdentity(
    'ses_' . str_repeat('a', 64),
    'ses_' . str_repeat('a', 64),
    str_repeat('a', 64),
    'prj_test',
    null,
    'cli',
    'opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4'
);
$client = new OpenCodeClient($settings);
$body = [
    'model' => 'big-pickle',
    'messages' => [['role' => 'user', 'content' => 'hello']],
    'stream' => true,
];

$started = null;
$chunks = '';
$result = $client->send($body, $model, '::1', $identity, true,
    static function (int $status, array $headers) use (&$started): void { $started = $status; },
    static function (string $chunk) use (&$chunks): bool { $chunks .= $chunk; return true; }
);

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) { $failures[] = $message; }
};

$check($result->status === 200, 'stream status should be 200, got ' . $result->status . ' / ' . ($result->errorMessage ?? ''));
$check($started === 200, 'onStart should receive 200');
$check(str_contains($chunks, 'Hello') && str_contains($chunks, ' from mock'), 'stream body should contain mock content');
$check(str_contains($chunks, '"usage"'), 'stream body should contain usage');
$check(!str_contains($chunks, 'error'), 'stream body should not contain an error');

$body['stream'] = false;
$result2 = $client->send($body, $model, '::1', $identity, false);
$check($result2->status === 200, 'non-stream status should be 200, got ' . $result2->status . ' / ' . ($result2->errorMessage ?? ''));
$decoded = json_decode($result2->body, true);
$check(($decoded['choices'][0]['message']['content'] ?? '') === 'Hello from mock', 'non-stream body');
$check(($decoded['usage']['total_tokens'] ?? 0) === 18, 'non-stream usage from the reassembled SSE');

$logFile = __DIR__ . '/mock-upstream.log';
$lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [];
$records = array_values(array_filter(array_map(static fn (string $line): ?array => json_decode($line, true) ?: null, $lines)));
$streamRecord = null;
$nonStreamRecord = null;
foreach ($records as $record) {
    if (($record['body']['stream'] ?? false) === true) {
        $streamRecord = $record;
    } else {
        $nonStreamRecord = $record;
    }
}
$headers = is_array($streamRecord) ? array_change_key_case($streamRecord['headers'] ?? [], CASE_LOWER) : [];
$requestBody = is_array($streamRecord) ? ($streamRecord['body'] ?? []) : [];
$check(($headers['authorization'] ?? '') === 'Bearer public', 'upstream Authorization should be Bearer public');
$check(($headers['x-opencode-client'] ?? '') === 'cli', 'x-opencode-client should be cli');
$check(($headers['user-agent'] ?? '') === 'opencode/1.19.6 ai-sdk/provider-utils/4.0.23 runtime/bun/1.4.4', 'User-Agent should match official client');
$check(($headers['x-opencode-session'] ?? '') === $identity->affinity, 'x-opencode-session should be forwarded');
$check(($headers['x-opencode-session-id'] ?? '') === $identity->sessionId, 'x-opencode-session-id should be forwarded');
$check(!isset($headers['x-session-affinity']), 'non-official headers should not be sent');
$check(!isset($requestBody['prompt_cache_key']), 'prompt_cache_key must not be injected');
$check(($requestBody['stream_options']['include_usage'] ?? false) === true, 'include_usage should be added for chat streams');
$systemText = (string) ($requestBody['messages'][0]['content'] ?? '');
$check(str_starts_with($systemText, 'You are opencode'), 'official agent prompt must be the system prefix');
$check(!str_contains($systemText, 'hello'), 'client user text must not leak into the system prompt');
$check(($requestBody['messages'][1]['content'] ?? '') === 'hello', 'user messages are preserved');
$check(($requestBody['stream'] ?? false) === true, 'upstream stream is always enabled');
$toolNames = array_map(static fn (array $tool): string => (string) ($tool['function']['name'] ?? ''), (array) ($requestBody['tools'] ?? []));
$check(in_array('read', $toolNames, true), 'official read tool must be injected');

if ($failures !== []) {
    foreach ($failures as $failure) {
        echo "FAIL: {$failure}\n";
    }
    exit(1);
}
echo "Upstream integration test passed.\n";
