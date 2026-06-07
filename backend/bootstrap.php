<?php

require_once __DIR__ . '/autoload.php';

use App\Support\Response;

set_exception_handler(function (): void {
    Response::json(['message' => 'Server error.'], 500);
});
