<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\ProfileStats;
use App\Models\User;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Response;

$pdo = Database::connection();
$user = Auth::user(new User($pdo));

if ($user === null) {
    Response::json(['message' => 'Authentication required.'], 401);
}

Response::json(['stats' => (new ProfileStats($pdo))->forUser((int) $user['id'])]);
