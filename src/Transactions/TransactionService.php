<?php

declare(strict_types=1);

namespace FFB\Transactions;

use Closure;
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
    /** @var Closure(): int */
    private Closure $now;

    /**
     * @param (callable(): int)|null $now current unix time provider (for tests)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly RosterRepository $rosters,
        private readonly PlayerRepository $players,
        private readonly LeagueSettingsRepository $settings,
        private readonly TransactionRepository $ledger,
        private readonly LineupService $lineups,
        private readonly LineupRepository $lineupRepo,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
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

    /** How long a Trade proposal lives before it can no longer be accepted. */
    public const TRADE_TTL_HOURS = 48;

    /**
     * A Manager offers a Trade to another Team: some of their Players for some of
     * the target's. Nothing moves — a pending proposal is recorded that only the
     * target may accept or reject (and only the proposer may cancel), expiring
     * after TRADE_TTL_HOURS.
     *
     * @param list<string> $offeredPlayerIds  Players the proposer gives (must be on the proposer's Roster)
     * @param list<string> $requestedPlayerIds Players the proposer wants (must be on the target's Roster)
     * @return int the new Transaction id
     * @throws TransactionException on any invalid proposal
     */
    public function proposeTrade(
        int $leagueId,
        int $seasonId,
        int $proposerTeamId,
        int $targetTeamId,
        ?int $userId,
        array $offeredPlayerIds,
        array $requestedPlayerIds,
    ): int {
        if ($proposerTeamId === $targetTeamId) {
            throw new TransactionException(422, 'You cannot trade with yourself.');
        }
        $offeredPlayerIds = $this->cleanIds($offeredPlayerIds);
        $requestedPlayerIds = $this->cleanIds($requestedPlayerIds);
        if ($offeredPlayerIds === [] || $requestedPlayerIds === []) {
            throw new TransactionException(422, 'A trade needs at least one player from each team.');
        }

        foreach ($offeredPlayerIds as $pid) {
            if ($this->rosters->teamForPlayer($seasonId, $pid) !== $proposerTeamId) {
                throw new TransactionException(422, 'You can only offer players on your own team.');
            }
        }
        foreach ($requestedPlayerIds as $pid) {
            if ($this->rosters->teamForPlayer($seasonId, $pid) !== $targetTeamId) {
                throw new TransactionException(422, 'You can only request players on the other team.');
            }
        }

        $expiresAt = date('Y-m-d H:i:s', $this->clock() + self::TRADE_TTL_HOURS * 3600);

        $this->pdo->beginTransaction();
        try {
            $txnId = $this->ledger->createHeader(
                $leagueId, $seasonId, 'trade', 'pending', 'proposed',
                $proposerTeamId, $targetTeamId, $expiresAt, $userId,
            );
            foreach ($offeredPlayerIds as $pid) {
                $this->ledger->addItem($txnId, $pid, $proposerTeamId, $targetTeamId, null);
            }
            foreach ($requestedPlayerIds as $pid) {
                $this->ledger->addItem($txnId, $pid, $targetTeamId, $proposerTeamId, null);
            }
            $this->pdo->commit();

            return $txnId;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The target Team accepts a pending Trade: both Rosters swap atomically. The
     * proposal is re-validated first — every Player must still sit on the Team the
     * proposal expects (ownership can drift between propose and accept).
     *
     * @throws TransactionException when the acting Team is not the target, the
     *   proposal is not pending, or it is no longer valid
     */
    public function acceptTrade(int $leagueId, int $seasonId, int $txnId, int $actingTeamId, ?int $userId): void
    {
        $txn = $this->requireProposedTrade($txnId);
        if ((int) $txn['accepted_by_team'] !== $actingTeamId) {
            throw new TransactionException(403, 'Only the team the trade was offered to can accept it.');
        }

        $items = $this->ledger->items($txnId);
        foreach ($items as $it) {
            if ($this->rosters->teamForPlayer($seasonId, (string) $it['player_id']) !== (int) $it['from_team_id']) {
                throw new TransactionException(409, 'This trade is no longer valid — a player involved has changed teams.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                $playerId = (string) $it['player_id'];
                $fromTeam = (int) $it['from_team_id'];
                $toTeam = (int) $it['to_team_id'];
                $prior = $this->rosters->acquiredOf($seasonId, $playerId);
                $this->ledger->setItemPriorAcquired((int) $it['id'], $prior);
                $this->rosters->movePlayer($seasonId, $playerId, $toTeam, 'trade');
                $this->releaseFromLineup($leagueId, $seasonId, $fromTeam, $playerId);
            }
            $this->ledger->setStatus($txnId, 'applied');
            $this->ledger->setProposalOutcome($txnId, 'accepted', $actingTeamId);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The target Team declines a pending Trade. Nothing moves.
     */
    public function rejectTrade(int $seasonId, int $txnId, int $actingTeamId): void
    {
        $txn = $this->requireProposedTrade($txnId);
        if ((int) $txn['accepted_by_team'] !== $actingTeamId) {
            throw new TransactionException(403, 'Only the team the trade was offered to can reject it.');
        }
        $this->ledger->setProposalOutcome($txnId, 'rejected');
    }

    /**
     * The proposing Team withdraws its pending Trade. Nothing moves.
     */
    public function cancelTrade(int $seasonId, int $txnId, int $actingTeamId): void
    {
        $txn = $this->requireProposedTrade($txnId);
        if ((int) $txn['proposed_by_team'] !== $actingTeamId) {
            throw new TransactionException(403, 'Only the team that proposed the trade can cancel it.');
        }
        $this->ledger->setProposalOutcome($txnId, 'cancelled');
    }

    /**
     * @return array<string,mixed>
     * @throws TransactionException when the Transaction is missing, not a Trade,
     *   or no longer an open proposal
     */
    private function requireProposedTrade(int $txnId): array
    {
        $txn = $this->ledger->find($txnId);
        if ($txn === null || $txn['type'] !== 'trade') {
            throw new TransactionException(404, 'Trade not found.');
        }
        if ($txn['proposal_outcome'] !== 'proposed') {
            throw new TransactionException(409, 'This trade is no longer open.');
        }

        return $txn;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function clock(): int
    {
        return ($this->now)();
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
