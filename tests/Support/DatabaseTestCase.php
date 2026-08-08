<?php

declare(strict_types=1);

namespace FFB\Tests\Support;

use FFB\Database;
use FFB\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a fully-migrated database. Each test runs
 * against its own throwaway MySQL database, created and dropped around the
 * test, with all migrations (including the seed) applied.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var array{host:string,port:string|int,database:string,username:string,password:string} */
    protected array $db;

    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create();
        $this->pdo = Database::connect($this->db);
        (new Migrator($this->pdo))->migrate(dirname(__DIR__, 2) . '/migrations');
    }

    protected function tearDown(): void
    {
        TestDatabase::drop($this->db);
    }
}
