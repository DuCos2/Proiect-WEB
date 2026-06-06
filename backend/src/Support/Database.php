<?php

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('LOG_DB_HOST') ?: '127.0.0.1';
        $database = getenv('LOG_DB_NAME') ?: 'log_iasi';
        $user = getenv('LOG_DB_USER') ?: 'root';
        $password = getenv('LOG_DB_PASS') ?: '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ];

        try {
            self::$connection = new PDO($dsn, $user, $password, $options);
        } catch (PDOException) {
            throw new RuntimeException('Database connection failed.');
        }

        return self::$connection;
    }
}
