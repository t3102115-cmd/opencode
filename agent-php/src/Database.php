<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = Config::dataDir() . '/agent.sqlite';
        $isNew = !is_file($path);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        if ($isNew) {
            $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/schema.sql'));
        }

        self::$pdo = $pdo;

        return $pdo;
    }
}
