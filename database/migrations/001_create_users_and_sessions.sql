CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT chk_users_role CHECK (role IN ('owner', 'sales', 'finance'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session ids are stored as SHA-256 hashes so a database dump does not hand out live sessions.
CREATE TABLE sessions (
    id_hash CHAR(64) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    payload MEDIUMTEXT NOT NULL,
    last_activity_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sessions_last_activity (last_activity_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per counted attempt (failed login, public inquiry). key_hash = sha256 of the
-- throttle key, e.g. "email|ip", so raw IPs and emails are not kept in this table.
CREATE TABLE rate_limit_hits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bucket VARCHAR(30) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    hit_at DATETIME NOT NULL,
    INDEX idx_rate_limit_lookup (bucket, key_hash, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
