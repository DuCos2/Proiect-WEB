<?php

namespace App\Support;

final class Url
{
    public static function appUrl(string $path): string
    {
        $configured = getenv('LOG_APP_URL');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/') . '/' . ltrim($path, '/');
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/backend/public/index.php'));
        $base = preg_replace('#/backend/public$#', '', $scriptDir) ?: '';

        return $scheme . '://' . $host . rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
