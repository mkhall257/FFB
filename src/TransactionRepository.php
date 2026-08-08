<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the Transaction ledger — the `transactions` header and its
 * `transaction_items` lines (see ADR-0010). The ledger is the durable audit
 * record over the live `rosters` membership; every post-Draft Roster change is
 * written here so it can be shown in the activity feed and reversed.
 */
final class TransactionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert a header row and return its id.
     */
    public function createHeader(
        int $leagueId,
        int $seasonId,
        string $type,
        string $status = 'applied',
        ?string $proposalOutcome = null,
        ?int $proposedByTeam = null,
        ?int $acceptedByTeam = null,
        ?string $expiresAt = null,
        ?int $createdByUser = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions'
            . ' (league_id, season_id, type, status, proposal_outcome,'
            . '  proposed_by_team, accepted_by_team, expires_at, created_by_user)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leagueId, $seasonId, $type, $status, $proposalOutcome,
            $proposedByTeam, $acceptedByTeam, $expiresAt, $createdByUser,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert one line item (one Player that moved).
     */
    public function addItem(
        int $transactionId,
        string $playerId,
        ?int $fromTeamId,
        ?int $toTeamId,
        ?string $priorAcquired,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO transaction_items (transaction_id, player_id, from_team_id, to_team_id, prior_acquired)'
            . ' VALUES (?, ?, ?, ?, ?)'
        )->execute([$transactionId, $playerId, $fromTeamId, $toTeamId, $priorAcquired]);
    }

    /**
     * A header row by id, or null.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The line items of a Transaction.
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $transactionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id'
        );
        $stmt->execute([$transactionId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    /**
     * The league-wide activity feed for a Season: Transactions that actually
     * changed a Roster (every Add/Drop and Commissioner edit, and only accepted
     * Trades — pending/rejected/cancelled/expired proposals stay off the public
     * feed), newest first, each with its enriched line items (Player and Team
     * names) so the view can render plain-English sentences.
     *
     * @return list<array<string,mixed>>
     */
    public function feed(int $seasonId, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transactions'
            . ' WHERE season_id = ?'
            . "   AND (type <> 'trade' OR proposal_outcome = 'accepted')"
            . ' ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . $limit
        );
        $stmt->execute([$seasonId]);
        /** @var list<array<string,mixed>> $headers */
        $headers = $stmt->fetchAll();
        if ($headers === []) {
            return [];
        }

        $ids = array_map(static fn ($h): int => (int) $h['id'], $headers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $this->pdo->prepare(
            'SELECT ti.transaction_id, ti.player_id, ti.from_team_id, ti.to_team_id,'
            . ' p.full_name AS player_name,'
            . ' tf.name AS from_team_name, tt.name AS to_team_name'
            . ' FROM transaction_items ti'
            . ' JOIN players p ON p.sleeper_id = ti.player_id'
            . ' LEFT JOIN teams tf ON tf.id = ti.from_team_id'
            . ' LEFT JOIN teams tt ON tt.id = ti.to_team_id'
            . " WHERE ti.transaction_id IN ({$placeholders})"
            . ' ORDER BY ti.id'
        );
        $itemStmt->execute($ids);

        $itemsByTxn = [];
        foreach ($itemStmt->fetchAll() as $row) {
            $itemsByTxn[(int) $row['transaction_id']][] = $row;
        }

        foreach ($headers as &$h) {
            $h['items'] = $itemsByTxn[(int) $h['id']] ?? [];
        }
        unset($h);

        return $headers;
    }

    public function setStatus(int $id, string $status, ?int $reversedByUser = null): void
    {
        $this->pdo->prepare(
            'UPDATE transactions SET status = ?, reversed_by_user = ?,'
            . " reversed_at = CASE WHEN ? = 'reversed' THEN CURRENT_TIMESTAMP ELSE reversed_at END"
            . ' WHERE id = ?'
        )->execute([$status, $reversedByUser, $status, $id]);
    }

    public function setProposalOutcome(int $id, string $outcome, ?int $acceptedByTeam = null): void
    {
        $this->pdo->prepare(
            'UPDATE transactions SET proposal_outcome = ?, accepted_by_team = COALESCE(?, accepted_by_team)'
            . ' WHERE id = ?'
        )->execute([$outcome, $acceptedByTeam, $id]);
    }
}
