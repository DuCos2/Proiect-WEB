<?php

namespace App\Support;

final class Csrf
{
    private const TOKEN_TTL_SECONDS = 7200;

    public static function token(): string
    {
        $payload = time() . ':' . bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $payload, self::secret());

        return base64_encode($payload . ':' . $signature);
    }

    public static function isValid(?string $token): bool
    {
        if (!is_string($token) || trim($token) === '') {
            return false;
        }

        $decoded = base64_decode($token, true);

        if (!is_string($decoded)) {
            return false;
        }

        $parts = explode(':', $decoded, 3);

        if (count($parts) !== 3) {
            return false;
        }

        [$issuedAt, $nonce, $signature] = $parts;

        if (!ctype_digit($issuedAt) || !ctype_xdigit($nonce) || strlen($nonce) !== 32) {
            return false;
        }

        if (time() - (int) $issuedAt > self::TOKEN_TTL_SECONDS) {
            return false;
        }

        $payload = $issuedAt . ':' . $nonce;
        $expectedSignature = hash_hmac('sha256', $payload, self::secret());

        return hash_equals($expectedSignature, $signature);
    }

    private static function secret(): string
    {
        return getenv('LOG_APP_KEY') ?: 'local-greetings-development-csrf-key';
    }
}
