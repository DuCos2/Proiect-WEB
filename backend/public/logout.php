<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\UserController;
use App\Support\Request;

Request::requireMethod('POST');

$controller = new UserController();
$controller->logout();
