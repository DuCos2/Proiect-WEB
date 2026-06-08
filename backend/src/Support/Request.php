<?php

namespace App\Support;

final class Request
{
    public static function jsonBody(): array
    {
        $rawBody = file_get_contents('php://input');

        if ($rawBody === false || trim($rawBody) === '') {
            return $_POST;
        }

        $body = json_decode($rawBody, true);

        return is_array($body) ? $body : [];
    }

    public static function csrfToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'x-csrf-token') {
                return (string) $value;
            }
        }

        return $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    public static function bearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                return self::parseBearerToken((string) $value);
            }

            if (strtolower((string) $name) === 'x-auth-token') {
                return trim((string) $value) ?: null;
            }
        }

        return self::parseBearerToken($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            ?? self::parseBearerToken($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')
            ?? (trim((string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '')) ?: null);
    }

    private static function parseBearerToken(string $header): ?string
    {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    public static function requireMethod(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
            Response::json(['message' => 'Method not allowed.'], 405);
        }
    }
}
