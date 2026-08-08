<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Applies numbered `.sql` migration files to a database, in filename order,
 * exactly once each. Applied migrations are recorded in a `schema_migrations`
 * ledger table so that re-running is a safe no-op (idempotent).
 *
 * A migration's "version" is its filename without the `.sql` extension, e.g.
 * `0001_create_leagues_and_seasons`.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Apply every not-yet-applied migration in $dir, in filename order.
     *
     * @return list<string> the versions applied by this call (empty if none)
     */
    public function migrate(string $dir): array
    {
        $this->ensureLedger();
        $applied = $this->appliedVersions();

        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);

        $newlyApplied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Could not read migration: {$file}");
            }

            $this->pdo->exec($sql);

            $stmt = $this->pdo->prepare(
                'INSERT INTO schema_migrations (version) VALUES (?)'
            );
            $stmt->execute([$version]);

            $newlyApplied[] = $version;
        }

        return $newlyApplied;
    }

    /**
     * Versions already recorded in the ledger.
     *
     * @return list<string>
     */
    public function appliedVersions(): array
    {
        $this->ensureLedger();

        /** @var list<string> $versions */
        $versions = $this->pdo
            ->query('SELECT version FROM schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_COLUMN);

        return $versions;
    }

    private function ensureLedger(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . ' version VARCHAR(255) NOT NULL PRIMARY KEY,'
            . ' applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
