<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'PHPMailer\\PHPMailer\\';
    $baseDir = __DIR__ . '/phpmailer/phpmailer/src/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
