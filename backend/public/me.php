<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Controllers\UserController;
$controller = new UserController();
$controller->me();
