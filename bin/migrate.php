<?php

declare(strict_types=1);

use Relay\App;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = App::db()->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');

$dir = BASE_PATH . '/database/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "No migration files found in database/migrations.\n");
    exit(1);
}

foreach ($files as $file) {
    $version = basename($file);
    $exists = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
    $exists->execute([$version]);
    if ($exists->fetchColumn()) {
        echo "[skip] {$version}\n";
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$version}\n");
        exit(1);
    }
    echo "[apply] {$version}\n";
    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $stmt->execute([$version]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[error] {$version}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nDatabase is up to date.\n";
echo "Next: php bin/create-admin.php <username> <email> [password]\n";
echo "Then add one or more IPv6 pools from Admin → IPv6 pools, and run: php bin/cron.php models\n";
