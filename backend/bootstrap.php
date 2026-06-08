<?php

require_once __DIR__ . '/autoload.php';

use App\Support\Response;

set_exception_handler(function (Throwable $exception): void {
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true);

    Response::json([
        'message' => $isLocal ? $exception->getMessage() : 'Server error.',
    ], 500);
});
