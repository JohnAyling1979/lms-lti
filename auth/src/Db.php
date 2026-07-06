<?php

namespace App;

use PDO;

/**
 * Shared connection to the tool's own database (tool-db). One PDO per request,
 * reused by every store that talks to it (Database, ToolDb) so a request that
 * touches both — e.g. /services/needsgrading — opens a single connection.
 */
class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('TOOL_DB_HOST') ?: 'tool-db';
            $name = getenv('TOOL_DB_NAME') ?: 'tool';
            $user = getenv('TOOL_DB_USER') ?: 'tool';
            $pass = getenv('TOOL_DB_PASS') ?: 'tool';

            self::$pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }

        return self::$pdo;
    }
}
