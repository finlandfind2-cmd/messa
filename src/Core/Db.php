<?php
declare(strict_types=1);
namespace Messa\Core;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = (int)(Config::get('DB_PORT', '3306') ?? '3306');
        $name = Config::get('DB_NAME', '');
        $user = Config::get('DB_USER', '');
        $pass = Config::get('DB_PASS', '');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3
        ]);
        return self::$pdo;
    }

    public static function ping(): bool
    {
        try { self::pdo()->query('SELECT 1'); return true; } catch (\Throwable) { return false; }
    }
}
