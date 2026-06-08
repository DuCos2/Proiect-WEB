<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\LocationController;
use App\Models\Location;
use App\Models\User;
use App\Support\Database;
use App\Support\Request;

$pdo = Database::connection();
$controller = new LocationController(new Location($pdo), new User($pdo));

Request::requireMethod('GET');
$controller->advanced();
