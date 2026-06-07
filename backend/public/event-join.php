<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\User;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;

Request::requireMethod('POST');

$payload = Request::jsonBody();
$id = filter_var($payload['event_id'] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id === null) {
    Response::json(['message' => 'Event id is required.'], 422);
}

$pdo = Database::connection();
(new EventController(new Event($pdo), new User($pdo)))->join($id);
