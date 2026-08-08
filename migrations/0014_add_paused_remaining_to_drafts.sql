-- Seconds left on the pick clock when the Commissioner paused the Draft, so
-- resuming restores the remaining time rather than a full fresh timer.

ALTER TABLE drafts
    ADD COLUMN paused_remaining SMALLINT UNSIGNED NULL AFTER current_deadline;
