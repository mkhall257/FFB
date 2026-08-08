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
