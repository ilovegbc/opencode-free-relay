<?php

declare(strict_types=1);

use Relay\App;
use Relay\Repositories\PlanRepository;
use Relay\Repositories\UserRepository;
use Relay\Support\Crypto;

require dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$force = false;
$positional = [];
foreach ($args as $arg) {
    if ($arg === '--force') {
        $force = true;
    } else {
        $positional[] = $arg;
    }
}

$username = $positional[0] ?? null;
$email = $positional[1] ?? null;
$password = $positional[2] ?? null;

if ($username === null) {
    echo "Username: ";
    $username = trim((string) fgets(STDIN));
}
if ($email === null) {
    echo "Email (optional): ";
    $email = trim((string) fgets(STDIN));
}
if ($password === null) {
    echo "Password (min 8 chars): ";
    $password = trim((string) fgets(STDIN));
}

if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', (string) $username)) {
    fwrite(STDERR, "Invalid username. Use 3-32 letters, numbers, dots, underscores or hyphens.\n");
    exit(1);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email.\n");
    exit(1);
}
if (strlen((string) $password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$users = new UserRepository(App::db());
$existing = $users->findByLogin((string) $username);
if ($existing !== null) {
    if (!$force) {
        fwrite(STDERR, "User already exists. Use --force to reset password and grant admin role.\n");
        exit(1);
    }
    $users->update((int) $existing['id'], [
        'password_hash' => Crypto::passwordHash((string) $password),
        'role' => 'admin',
        'status' => 'active',
        'email' => $email !== '' ? $email : $existing['email'],
    ]);
    echo "Updated existing user #{$existing['id']} ({$username}) as admin.\n";
    exit(0);
}

$plan = (new PlanRepository(App::db()))->defaultPlan();
if ($plan === null) {
    fwrite(STDERR, "No plan found. Run php bin/migrate.php first.\n");
    exit(1);
}
$user = $users->create([
    'username' => $username,
    'email' => $email !== '' ? $email : null,
    'password_hash' => Crypto::passwordHash((string) $password),
    'role' => 'admin',
    'status' => 'active',
    'plan_id' => $plan['id'],
]);
echo "Created admin #{$user['id']} ({$username}).\n";
