<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Csrf;
use App\Support\Response;

Response::json(['token' => Csrf::token()]);
