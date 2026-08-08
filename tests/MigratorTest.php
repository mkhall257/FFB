<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Database;
use FFB\Migrator;
use FFB\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../migrations';

    /** @var array{host:string,port:string|int,database:string,username:string,password:string} */
    private array $db;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->db = TestDatabase::create();
        $this->pdo = Database::connect($this->db);
    }

    protected function tearDown(): void
    {
        TestDatabase::drop($this->db);
    }

    public function testMigrateCreatesEveryExpectedTable(): void
    {
        (new Migrator($this->pdo))->migrate(self::MIGRATIONS_DIR);

        $expected = [
            'schema_migrations',
            'leagues',
            'seasons',
            'league_settings',
            'users',
            'teams',
            'players',
            'player_sync_log',
        ];

        foreach ($expected as $table) {
            $this->assertContains(
                $table,
                $this->tableNames(),
                "expected table '{$table}' to exist after migration"
            );
        }
    }

    public function testEveryDomainTableCarriesLeagueScope(): void
    {
        (new Migrator($this->pdo))->migrate(self::MIGRATIONS_DIR);

        // ADR-0001: League/Season-scoped domain tables carry league_id, and
        // season-scoped ones also carry season_id.
        $this->assertContains('league_id', $this->columnNames('teams'));
        $this->assertContains('season_id', $this->columnNames('teams'));
        $this->assertContains('league_id', $this->columnNames('users'));
        $this->assertContains('league_id', $this->columnNames('league_settings'));
        $this->assertContains('season_id', $this->columnNames('league_settings'));
    }

    public function testPlayersTableIsSleeperSpinedWithNflverseLink(): void
    {
        (new Migrator($this->pdo))->migrate(self::MIGRATIONS_DIR);

        $columns = $this->columnNames('players');

        // ADR-0004: keyed on Sleeper id, with an nflverse cross-reference.
        $this->assertContains('sleeper_id', $columns);
        $this->assertContains('nflverse_id', $columns);
    }

    public function testUsersTableCollectsNoPii(): void
    {
        (new Migrator($this->pdo))->migrate(self::MIGRATIONS_DIR);

        $columns = $this->columnNames('users');

        // No email or other PII — display name only.
        $this->assertNotContains('email', $columns);
        $this->assertContains('display_name', $columns);
    }

    public function testSeedCreatesSingleLeagueAndCurrentSeason(): void
    {
        (new Migrator($this->pdo))->migrate(self::MIGRATIONS_DIR);

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM leagues')->fetchColumn(),
            'expected exactly one seeded League'
        );

        $current = $this->pdo
            ->query('SELECT year FROM seasons WHERE is_current = 1')
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([2026], array_map('intval', $current));
    }

    public function testSeedInstallsDefaultRosterAndScoringSettings(): void
    {
        (new Migrator($this->pdo))->migrate(self::MIGRATIONS_DIR);

        $settings = $this->pdo
            ->query('SELECT setting_key, setting_value FROM league_settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame('0.5', $settings['scoring.reception'] ?? null, 'expected Half-PPR');
        $this->assertSame('1', $settings['roster.qb'] ?? null);
        $this->assertSame('2', $settings['roster.rb'] ?? null);
        $this->assertSame('2', $settings['roster.wr'] ?? null);
        $this->assertSame('1', $settings['roster.flex'] ?? null);
        $this->assertSame('5', $settings['roster.bench'] ?? null);
    }

    public function testMigrationsAreIdempotent(): void
    {
        $migrator = new Migrator($this->pdo);

        $first = $migrator->migrate(self::MIGRATIONS_DIR);
        $second = $migrator->migrate(self::MIGRATIONS_DIR);

        $this->assertNotEmpty($first, 'first run should apply migrations');
        $this->assertSame([], $second, 're-running should apply nothing');

        // And the seed did not double-insert.
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM leagues')->fetchColumn()
        );
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        /** @var list<string> $names */
        $names = $this->pdo
            ->query('SHOW TABLES')
            ->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }

    /** @return list<string> */
    private function columnNames(string $table): array
    {
        $stmt = $this->pdo->query(sprintf('SHOW COLUMNS FROM `%s`', $table));

        /** @var list<string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
