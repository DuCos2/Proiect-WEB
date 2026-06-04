<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\UserController;
use App\Models\User;
use App\Support\Database;
use App\Support\Request;

Request::requireMethod('POST');

$controller = new UserController(new User(Database::connection()));
$controller->requestPasswordReset(Request::jsonBody());
