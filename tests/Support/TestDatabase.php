<?php

declare(strict_types=1);

namespace FFB\Tests\Support;

use FFB\Database;

/**
 * Creates and drops throwaway MySQL databases for tests, using the local
 * server credentials from config/config.php but a unique, disposable database
 * name per test. Nothing here touches the real application database.
 */
final class TestDatabase
{
    /**
     * @return array{host:string,port:string|int,database:string,username:string,password:string}
     */
    public static function create(): array
    {
        $db = self::serverCreds();
        $db['database'] = 'ffb_test_' . bin2hex(random_bytes(6));

        $server = Database::connectServer($db);
        $server->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $db['database']
        ));

        return $db;
    }

    /**
     * @param array{host:string,port:string|int,database:string,username:string,password:string} $db
     */
    public static function drop(array $db): void
    {
        $server = Database::connectServer($db);
        $server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $db['database']));
    }

    /**
     * @return array{host:string,port:string|int,database:string,username:string,password:string}
     */
    private static function serverCreds(): array
    {
        /** @var array{db: array{host:string,port:string|int,database:string,username:string,password:string}} $config */
        $config = require dirname(__DIR__, 2) . '/config/config.php';

        return $config['db'];
    }
}
