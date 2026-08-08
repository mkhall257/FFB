<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class TransactionSchemaTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
    }

    public function testAnAddDropHeaderWithItemsPersists(): void
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Sharks');
        $players = new PlayerRepository($this->pdo);
        $players->upsert('IN1', null, 'Added Guy', 'RB', 'KC', 'Active', 1);
        $players->upsert('OUT1', null, 'Dropped Guy', 'RB', 'KC', 'Active', 2);

        $txnId = $this->insertTransaction('add_drop');

        // added: from pool -> team; dropped: team -> pool (carrying its prior acquired).
        $this->insertItem($txnId, 'IN1', null, $teamId, null);
        $this->insertItem($txnId, 'OUT1', $teamId, null, 'draft');

        $count = $this->pdo->query(
            'SELECT COUNT(*) FROM transaction_items WHERE transaction_id = ' . $txnId
        )->fetchColumn();
        $this->assertSame(2, (int) $count);
    }

    public function testDeletingATransactionCascadesToItsItems(): void
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Bears');
        (new PlayerRepository($this->pdo))->upsert('P1', null, 'Player One', 'WR', 'KC', 'Active', 1);

        $txnId = $this->insertTransaction('add_drop');
        $this->insertItem($txnId, 'P1', null, $teamId, null);

        $this->pdo->exec('DELETE FROM transactions WHERE id = ' . $txnId);

        $count = $this->pdo->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn();
        $this->assertSame(0, (int) $count);
    }

    public function testItemRequiresARealTransaction(): void
    {
        (new PlayerRepository($this->pdo))->upsert('P9', null, 'Orphan', 'TE', 'KC', 'Active', 1);

        $this->expectException(\PDOException::class);
        $this->insertItem(999999, 'P9', null, null, null);
    }

    private function insertTransaction(string $type): int
    {
        $this->pdo->prepare(
            'INSERT INTO transactions (league_id, season_id, type) VALUES (?, ?, ?)'
        )->execute([$this->leagueId, $this->seasonId, $type]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertItem(int $txnId, string $playerId, ?int $from, ?int $to, ?string $priorAcquired): void
    {
        $this->pdo->prepare(
            'INSERT INTO transaction_items (transaction_id, player_id, from_team_id, to_team_id, prior_acquired)'
            . ' VALUES (?, ?, ?, ?, ?)'
        )->execute([$txnId, $playerId, $from, $to, $priorAcquired]);
    }
}
