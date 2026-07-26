<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $mysql = null;

    public static function mysql(array $config): PDO
    {
        if (self::$mysql) return self::$mysql;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
        self::$mysql = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$mysql;
    }

    public static function resetMysql(): void
    {
        self::$mysql = null;
    }

}
