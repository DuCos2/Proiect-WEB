<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\User;
use App\Support\Database;
use App\Support\Response;

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id === null) {
    Response::json(['message' => 'Event id is required.'], 422);
}

$pdo = Database::connection();
$controller = new EventController(new Event($pdo), new User($pdo));
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'DELETE') {
    $controller->delete($id);
} else {
    $controller->show($id);
}