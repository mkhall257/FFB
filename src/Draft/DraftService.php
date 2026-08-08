<?php

declare(strict_types=1);

namespace FFB\Draft;

use FFB\DraftPickRepository;
use FFB\DraftRepository;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\PlayerRepository;
use PDO;
use PDOException;

/**
 * The core Draft mechanic: commit a pick for the Team on the clock and advance
 * the Draft. Enforces the invariants (Draft is Live, the Team is on the clock,
 * the Player is draftable and still available) so every caller — a Manager
 * pick, an Auto-pick, or a Commissioner pick-on-behalf — goes through one path.
 *
 * Authorization of *who* may act for a Team is the caller's concern; this
 * service is handed the Team that is picking and trusts it.
 */
final class DraftService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DraftRepository $drafts,
        private readonly DraftPickRepository $picks,
        private readonly PlayerRepository $players,
        private readonly AutoPickStrategy $autoPick,
        private readonly LeagueSettingsRepository $settings,
        private readonly LeagueRepository $leagues,
    ) {
    }

    /**
     * If the pick on the clock has timed out and the Commissioner has left
     * expiry Auto-pick enabled, make the Auto-pick for the on-the-clock Team.
     * With the toggle off, an expired timer simply leaves the Team on the clock.
     *
     * @param array<string,mixed> $draft the current drafts row
     * @return bool whether an Auto-pick was made
     */
    public function processExpiryIfDue(array $draft): bool
    {
        if (($draft['state'] ?? null) !== 'live' || $draft['current_pick_no'] === null) {
            return false;
        }
        if ((int) $draft['autopick_on_expiry'] !== 1) {
            return false;
        }
        $deadline = $draft['current_deadline'];
        if ($deadline === null || strtotime((string) $deadline) > time()) {
            return false;
        }

        $current = $this->picks->findByOverall((int) $draft['id'], (int) $draft['current_pick_no']);
        if ($current === null) {
            return false;
        }

        return $this->autoPickFor($draft, (int) $current['team_id']);
    }

    /**
     * Keep Auto-picking while the Team on the clock is in Auto-draft mode, so a
     * Team whose Manager has left never holds up the Draft. Bounded by the pick
     * count; re-reads the live Draft each iteration.
     */
    public function runAutoDrafts(): void
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        for ($guard = 0; $guard < 10000; $guard++) {
            $draft = $this->drafts->find($leagueId, $seasonId);
            if ($draft === null || $draft['state'] !== 'live' || $draft['current_pick_no'] === null) {
                return;
            }

            $current = $this->picks->findByOverall((int) $draft['id'], (int) $draft['current_pick_no']);
            if ($current === null || !$this->drafts->isAutoDraft((int) $draft['id'], (int) $current['team_id'])) {
                return;
            }

            if (!$this->autoPickFor($draft, (int) $current['team_id'])) {
                return;
            }
        }
    }

    /**
     * Make an Auto-pick for a Team (its Queue, then the position-aware Sleeper
     * fallback with the legal-lineup guarantee). Used by expiry and, later, by
     * Commissioner-driven Auto-draft.
     *
     * @param array<string,mixed> $draft
     * @return bool whether a pick was made
     */
    public function autoPickFor(array $draft, int $teamId): bool
    {
        $settings = $this->settings->all(
            $this->leagues->currentLeagueId(),
            $this->leagues->currentSeasonId(),
        );
        $playerId = $this->autoPick->choose((int) $draft['id'], $teamId, $settings);
        if ($playerId === null) {
            return false;
        }

        $this->pick($draft, $teamId, $playerId, 'auto');

        return true;
    }

    /**
     * Commit $playerId as $teamId's selection for the pick currently on the
     * clock, then advance the Draft (or complete it after the last pick).
     *
     * @param array<string,mixed> $draft the current drafts row
     * @throws DraftPickException when the pick is not allowed
     */
    public function pick(array $draft, int $teamId, string $playerId, string $source): void
    {
        if (($draft['state'] ?? null) !== 'live') {
            throw new DraftPickException(409, 'The draft is not live.');
        }

        $draftId = (int) $draft['id'];
        $currentNo = (int) $draft['current_pick_no'];
        $pick = $this->picks->findByOverall($draftId, $currentNo);

        if ($pick === null) {
            throw new DraftPickException(409, 'No pick is on the clock.');
        }
        if ((int) $pick['team_id'] !== $teamId) {
            throw new DraftPickException(403, 'It is not your turn to pick.');
        }

        $playerId = trim($playerId);
        if ($playerId === '') {
            throw new DraftPickException(400, 'Choose a player to draft.');
        }
        if (!$this->players->isDraftable($playerId)) {
            throw new DraftPickException(400, 'That player cannot be drafted.');
        }
        if ($this->picks->isPlayerTaken($draftId, $playerId)) {
            throw new DraftPickException(409, 'That player has already been drafted.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->picks->assignPlayer((int) $pick['id'], $playerId, $source);
            $this->advance($draft);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            // Lost the race for this Player between the check and the write.
            if ($e->getCode() === '23000') {
                throw new DraftPickException(409, 'That player has already been drafted.');
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $draft
     */
    private function advance(array $draft): void
    {
        $draftId = (int) $draft['id'];
        $next = (int) $draft['current_pick_no'] + 1;

        if ($next > $this->picks->totalPicks($draftId)) {
            $this->drafts->complete($draftId);

            return;
        }

        $this->drafts->advanceTo($draftId, $next, (int) $draft['pick_seconds']);
    }
}
