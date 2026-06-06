<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\UserController;
use App\Models\User;
use App\Support\Database;

$controller = new UserController(new User(Database::connection()));
$controller->me();
