<?php
require_once __DIR__ . '/autoload.php';

use App\Support\Response;
use App\Support\Request;
use App\Support\Csrf;

set_exception_handler(function (Throwable $exception): void {
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true);
    Response::json([
        'message' => $isLocal ? $exception->getMessage() : 'Server error.',
    ], 500);
});

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, 'csrf.php') === false) {
        if (!Csrf::isValid(Request::csrfToken())) {
            Response::json(['message' => 'CSRF token is invalid or expired.'], 403);
        }
    }
}