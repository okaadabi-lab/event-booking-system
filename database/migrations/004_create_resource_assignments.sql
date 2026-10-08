-- Released assignments are kept for history. active_flag is 1 while the assignment
-- is live and NULL once released; because UNIQUE ignores NULLs, the unique key
-- allows only one live assignment per booking/resource pair (a partial unique index).
CREATE TABLE booking_resources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    resource_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    active_flag TINYINT AS (IF(released_at IS NULL, 1, NULL)) STORED,
    CONSTRAINT fk_booking_resources_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_resources_resource FOREIGN KEY (resource_id) REFERENCES resources (id),
    CONSTRAINT fk_booking_resources_user FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT uq_booking_resources_active UNIQUE (booking_id, resource_id, active_flag),
    INDEX idx_booking_resources_resource (resource_id, active_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
