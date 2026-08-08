<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Records each player-sync run so the Commissioner can confirm the import is
 * running and see its results.
 */
final class PlayerSyncLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(): int
    {
        $this->pdo->exec(
            "INSERT INTO player_sync_log (started_at, outcome) VALUES (NOW(), 'running')"
        );

        return (int) $this->pdo->lastInsertId();
    }

    public function finishSuccess(int $id, int $upserted, int $unmatched): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE player_sync_log"
            . " SET finished_at = NOW(), players_upserted = ?, unmatched_count = ?, outcome = 'success'"
            . " WHERE id = ?"
        );
        $stmt->execute([$upserted, $unmatched, $id]);
    }

    public function finishError(int $id, string $message): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE player_sync_log SET finished_at = NOW(), outcome = 'error', message = ? WHERE id = ?"
        );
        $stmt->execute([$message, $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $row = $this->pdo
            ->query('SELECT * FROM player_sync_log ORDER BY id DESC LIMIT 1')
            ->fetch();

        return $row === false ? null : $row;
    }
}
