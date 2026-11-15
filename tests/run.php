<?php

declare(strict_types=1);

// Test-only APP_KEY and PHP timezone. No database is required for these tests.
putenv('APP_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');

require dirname(__DIR__) . '/app/bootstrap.php';

require __DIR__ . '/TestCase.php';

$tests = [
    'Relay\\Tests\\Ipv6Test',
    'Relay\\Tests\\ModelFilterTest',
    'Relay\\Tests\\IdentityTest',
    'Relay\\Tests\\UsageParserTest',
    'Relay\\Tests\\RouterTest',
    'Relay\\Tests\\CryptoTest',
];

$totalAssertions = 0;
$totalFailures = [];

foreach ($tests as $class) {
    $file = __DIR__ . '/' . substr(strrchr($class, '\\') ?: '', 1) . '.php';
    if (!is_file($file)) {
        fwrite(STDERR, "Missing test file: {$file}\n");
        exit(1);
    }
    require $file;
    /** @var \Relay\Tests\TestCase $test */
    $test = new $class();
    echo "▶ " . substr(strrchr($class, '\\') ?: $class, 1) . "\n";
    try {
        $test->run();
    } catch (Throwable $e) {
        echo "  ✗ exception: " . $e->getMessage() . "\n";
        $totalFailures[] = $class . ': ' . $e->getMessage();
        continue;
    }
    $result = $test->result();
    $totalAssertions += $result['assertions'];
    foreach ($result['failures'] as $failure) {
        echo "  ✗ " . $failure . "\n";
        $totalFailures[] = $class . ': ' . $failure;
    }
    if ($result['failures'] === []) {
        echo "  ✓ " . $result['assertions'] . " assertions\n";
    }
}

echo "\n" . str_repeat('-', 56) . "\n";
echo $totalAssertions . " assertions, " . count($totalFailures) . " failure(s)\n";
if ($totalFailures !== []) {
    foreach ($totalFailures as $failure) {
        echo " - " . $failure . "\n";
    }
    exit(1);
}
echo "All tests passed.\n";
