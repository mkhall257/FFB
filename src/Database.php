<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Creates PDO connections to MySQL from a `db` configuration array
 * (host, port, database, username, password).
 *
 * Every connection is configured to throw on error, fetch associative
 * rows by default, and use real (non-emulated) prepared statements.
 */
final class Database
{
    private const OPTIONS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /**
     * Connect to a specific database.
     *
     * @param array{host:string,port:string|int,database:string,username:string,password:string} $db
     */
    public static function connect(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['database'],
        );

        return new PDO($dsn, $db['username'], $db['password'], self::OPTIONS);
    }

    /**
     * Connect to the MySQL server without selecting a database — used to
     * create or drop databases (e.g. throwaway test databases).
     *
     * @param array{host:string,port:string|int,username:string,password:string} $db
     */
    public static function connectServer(array $db): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
        );

        return new PDO($dsn, $db['username'], $db['password'], self::OPTIONS);
    }
}
