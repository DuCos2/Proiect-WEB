<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\LocationController;
use App\Models\Location;
use App\Support\Database;
use App\Support\Request;

$pdo = Database::connection();
$controller = new LocationController(new Location($pdo));

Request::requireMethod('GET');
$controller->index();