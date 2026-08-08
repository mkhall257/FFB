-- The post-Draft Transaction ledger header (see CONTEXT.md, ADR-0010). One row
-- per Transaction: an Add/Drop, a Trade, or a Commissioner manual roster-edit.
-- `status` is the ledger state (applied vs reversed); an Add/Drop and a
-- commish_edit are born 'applied', a Trade only becomes 'applied' when accepted.
-- `proposal_outcome` is the Trade-only lifecycle (NULL for the other types).
-- The `transaction_items` lines carry the actual Player moves; this header is
-- the derived audit record over the live `rosters` membership.

CREATE TABLE transactions (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id        INT UNSIGNED NOT NULL,
    season_id        INT UNSIGNED NOT NULL,
    type             ENUM('add_drop', 'trade', 'commish_edit') NOT NULL,
    status           ENUM('applied', 'reversed') NOT NULL DEFAULT 'applied',
    proposal_outcome ENUM('proposed', 'accepted', 'rejected', 'cancelled', 'expired') NULL,
    proposed_by_team INT UNSIGNED NULL,
    accepted_by_team INT UNSIGNED NULL,
    expires_at       DATETIME NULL,
    created_by_user  INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_by_user INT UNSIGNED NULL,
    reversed_at      DATETIME NULL,
    KEY idx_txn_feed (season_id, created_at),
    KEY idx_txn_proposal (season_id, type, proposal_outcome),
    CONSTRAINT fk_txn_league FOREIGN KEY (league_id) REFERENCES leagues (id),
    CONSTRAINT fk_txn_season FOREIGN KEY (season_id) REFERENCES seasons (id),
    CONSTRAINT fk_txn_proposed_by FOREIGN KEY (proposed_by_team) REFERENCES teams (id),
    CONSTRAINT fk_txn_accepted_by FOREIGN KEY (accepted_by_team) REFERENCES teams (id),
    CONSTRAINT fk_txn_created_by FOREIGN KEY (created_by_user) REFERENCES users (id),
    CONSTRAINT fk_txn_reversed_by FOREIGN KEY (reversed_by_user) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
