<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run via CLI.\n");
}

if ($argc < 2) {
    die("Usage: php promote-admin.php <email>\n");
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$email = trim($argv[1]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: Invalid email format.\n");
}

$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    die("Error: User with email '{$email}' not found.\n");
}

$stmt = $pdo->prepare('UPDATE users SET role = "admin" WHERE id = :id');
$stmt->execute(['id' => $user['id']]);

echo "Success: User '{$email}' has been promoted to 'admin'.\n";