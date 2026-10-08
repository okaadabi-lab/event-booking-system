CREATE TABLE activity_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(20) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    summary VARCHAR(255) NOT NULL,
    before_state LONGTEXT NULL,
    after_state LONGTEXT NULL,
    actor_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_activity_before_json CHECK (before_state IS NULL OR JSON_VALID(before_state)),
    CONSTRAINT chk_activity_after_json CHECK (after_state IS NULL OR JSON_VALID(after_state)),
    INDEX idx_activity_entity (entity_type, entity_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
