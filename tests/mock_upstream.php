<?php

declare(strict_types=1);

// Minimal OpenCode-compatible mock for integration tests.
// Run: php -S [::1]:18080 tests/mock_upstream.php

header('X-Mock: 1');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$logFile = getenv('MOCK_LOG') ?: __DIR__ . '/mock-upstream.log';
file_put_contents($logFile, json_encode([
    'time' => gmdate('c'),
    'path' => $path,
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    'body' => $body,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);

if (str_ends_with($path, '/chat/completions')) {
    $isStream = (bool) ($body['stream'] ?? false);
    if ($isStream) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        echo "data: {\"id\":\"mock\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hello\"}}]}\n\n";
        flush();
        usleep(10000);
        echo "data: {\"id\":\"mock\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\" from mock\"}}]}\n\n";
        flush();
        usleep(10000);
        echo "data: {\"id\":\"mock\",\"choices\":[],\"usage\":{\"prompt_tokens\":11,\"completion_tokens\":7,\"total_tokens\":18,\"prompt_tokens_details\":{\"cached_tokens\":2}}}\n\n";
        echo "data: [DONE]\n\n";
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'mock',
        'object' => 'chat.completion',
        'model' => $body['model'] ?? 'mock',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello from mock'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 4, 'total_tokens' => 13],
    ]);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo '{"error":{"message":"not found","type":"not_found"}}';
