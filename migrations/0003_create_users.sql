-- User accounts: Commissioner (and backup Commissioner) and Managers.
-- Commissioner-provisioned only. No email or other PII — display name only.

CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    league_id     INT UNSIGNED NOT NULL,
    username      VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('commissioner', 'manager') NOT NULL,
    display_name  VARCHAR(100) NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_league_username (league_id, username),
    CONSTRAINT fk_users_league FOREIGN KEY (league_id) REFERENCES leagues (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
