-- Team lifecycle: a Team can be deactivated (retired for a Season) without
-- deleting its history. Inactive Teams are excluded from Draft order, schedule
-- generation, and Playoff seeding, but their past records still render.
-- Default 1 keeps every existing Team active.

ALTER TABLE teams
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER user_id;
