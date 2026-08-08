-- The line items of a Transaction: one row per Player that moved. A NULL
-- from_team_id means the Player came from the free-agent pool (an add); a NULL
-- to_team_id means the Player was dropped to the pool. A Trade writes one item
-- per Player moving between the two Teams. `prior_acquired` captures the
-- `rosters.acquired` value the Player held immediately before this move (NULL
-- for a pool pickup), so a Commissioner reversal can restore the exact prior
-- state. Items cascade-delete with their header.

CREATE TABLE transaction_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    player_id      VARCHAR(32) NOT NULL,
    from_team_id   INT UNSIGNED NULL,
    to_team_id     INT UNSIGNED NULL,
    prior_acquired ENUM('draft', 'add', 'trade') NULL,
    KEY idx_txn_item_txn (transaction_id),
    CONSTRAINT fk_txn_item_txn FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_txn_item_player FOREIGN KEY (player_id) REFERENCES players (sleeper_id),
    CONSTRAINT fk_txn_item_from FOREIGN KEY (from_team_id) REFERENCES teams (id),
    CONSTRAINT fk_txn_item_to FOREIGN KEY (to_team_id) REFERENCES teams (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
