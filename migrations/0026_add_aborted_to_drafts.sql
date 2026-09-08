-- Let the Commissioner permanently stop a Draft in progress (see ADR-0003,
-- ADR-0007). An aborted Draft leaves the picks already made as a record but is
-- taken out of the live lifecycle, so no more picks can happen. It is a
-- terminal state alongside 'complete'; to run the Draft again the Commissioner
-- resets it back to 'setup', which wipes the board.

ALTER TABLE drafts
    MODIFY COLUMN state ENUM('setup', 'ready', 'live', 'paused', 'complete', 'aborted')
        NOT NULL DEFAULT 'setup';
