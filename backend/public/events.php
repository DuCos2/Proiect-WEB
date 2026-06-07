<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\User;
use App\Support\Database;
use App\Support\Request;

$pdo = Database::connection();
$controller = new EventController(new Event($pdo), new User($pdo));
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $controller->create(Request::jsonBody());
}

Request::requireMethod('GET');
$controller->index();
