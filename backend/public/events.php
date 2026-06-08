<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\EventController;
use App\Models\Event;
use App\Models\Location;
use App\Models\User;
use App\Support\Database;
use App\Support\Mailer;
use App\Support\Request;

$pdo = Database::connection();
$controller = new EventController(new Event($pdo), new User($pdo), new Location($pdo), new Mailer());
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $controller->create(Request::jsonBody());
}

Request::requireMethod('GET');
$locationId = filter_var($_GET['location_id'] ?? null, FILTER_VALIDATE_INT);
$controller->index($locationId === false ? null : $locationId);
