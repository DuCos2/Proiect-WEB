<?php

namespace App\Support;

use App\Models\User;

final class Auth
{
    public static function user(?User $users = null): ?array
    {
        $token = Request::bearerToken();

        if ($token !== null && $users instanceof User) {
            return $users->findByAuthTokenHash(self::tokenHash($token));
        }

        return null;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
