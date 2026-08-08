<?php

declare(strict_types=1);

namespace FFB\Transactions;

use FFB\LeagueSettingsRepository;
use FFB\Lineup\LineupService;
use FFB\LineupRepository;
use FFB\PlayerRepository;
use FFB\RosterRepository;
use FFB\TransactionRepository;
use PDO;
use PDOException;

/**
 * Owns the post-Draft Transaction lifecycle (ADR-0010): applying an Add/Drop,
 * and (later slices) proposing/accepting Trades, reversing Transactions, and
 * Commissioner manual roster-edits. Every state change runs inside a single DB
 * transaction and is all-or-nothing, and is recorded in the ledger so it can be
 * shown in the activity feed and reversed.
 */
final class TransactionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RosterRepository $rosters,
        private readonly PlayerRepository $players,
        private readonly LeagueSettingsRepository $settings,
        private readonly TransactionRepository $ledger,
        private readonly LineupService $lineups,
        private readonly LineupRepository $lineupRepo,
    ) {
    }

    /**
     * A Manager claims a free-agent Player (adding), optionally releasing one of
     * their own (dropping). A drop is mandatory only when the Roster is at its
     * size cap. Applied atomically: on any failure nothing changes.
     *
     * @return int the new Transaction id
     * @throws TransactionException on any rule violation
     */
    public function addDrop(
        int $leagueId,
        int $seasonId,
        int $teamId,
        ?int $userId,
        string $addPlayerId,
        ?string $dropPlayerId,
    ): int {
        if (!$this->players->exists($addPlayerId)) {
            throw new TransactionException(422, 'That player does not exist.');
        }
        if ($this->rosters->teamForPlayer($seasonId, $addPlayerId) !== null) {
            throw new TransactionException(422, 'That player is already on a roster.');
        }

        $priorAcquired = null;
        if ($dropPlayerId !== null && $dropPlayerId !== '') {
            if ($this->rosters->teamForPlayer($seasonId, $dropPlayerId) !== $teamId) {
                throw new TransactionException(422, 'You can only drop a player on your own team.');
            }
            $priorAcquired = $this->rosters->acquiredOf($seasonId, $dropPlayerId);
        } else {
            $dropPlayerId = null;
        }

        $cap = $this->rosterCap($leagueId, $seasonId);
        $size = $this->rosters->sizeForTeam($seasonId, $teamId);
        $newSize = $size + 1 - ($dropPlayerId !== null ? 1 : 0);
        if ($newSize > $cap) {
            throw new TransactionException(422, 'Your roster is full — drop a player to add this one.');
        }

        $this->pdo->beginTransaction();
        try {
            $txnId = $this->ledger->createHeader($leagueId, $seasonId, 'add_drop', 'applied', null, null, null, null, $userId);

            if ($dropPlayerId !== null) {
                $this->rosters->removePlayer($seasonId, $dropPlayerId);
                $this->releaseFromLineup($leagueId, $seasonId, $teamId, $dropPlayerId);
                $this->ledger->addItem($txnId, $dropPlayerId, $teamId, null, $priorAcquired);
            }

            // uq_roster_player is the first-come-first-served backstop.
            $this->rosters->addPlayer($leagueId, $seasonId, $teamId, $addPlayerId, 'add');
            $this->ledger->addItem($txnId, $addPlayerId, null, $teamId, null);

            $this->pdo->commit();

            return $txnId;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($this->isUniqueViolation($e)) {
                throw new TransactionException(409, 'Sorry — that player was just added by another team. Nothing changed.');
            }
            throw $e;
        }
    }

    /**
     * Maximum Roster size = starter slots + Bench, from the roster.* settings.
     */
    public function rosterCap(int $leagueId, int $seasonId): int
    {
        $s = $this->settings->all($leagueId, $seasonId);
        $slot = static fn (string $k): int => (int) ($s['roster.' . $k] ?? 0);

        return $slot('qb') + $slot('rb') + $slot('wr') + $slot('te')
            + $slot('flex') + $slot('k') + $slot('def') + $slot('bench');
    }

    /**
     * When a Player leaves a Team's Roster (dropped or traded away), clear the
     * slot they hold in the current week's Lineup — but only if that week is not
     * yet locked. A locked week's Lineup snapshot is left untouched, so the
     * departed Player keeps scoring for that week (ADR-0008 / ADR-0010).
     */
    private function releaseFromLineup(int $leagueId, int $seasonId, int $teamId, string $playerId): void
    {
        $week = $this->currentWeek($leagueId, $seasonId);
        if ($this->lineups->isLocked($leagueId, $seasonId, $week)) {
            return;
        }
        $this->lineupRepo->clearPlayer($seasonId, $week, $teamId, $playerId);
    }

    private function currentWeek(int $leagueId, int $seasonId): int
    {
        $all = $this->settings->all($leagueId, $seasonId);

        return max(1, (int) ($all['schedule.current_week'] ?? 1));
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        // MySQL duplicate-key is SQLSTATE 23000 with driver error 1062.
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
