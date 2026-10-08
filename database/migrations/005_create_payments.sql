CREATE TABLE payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    method VARCHAR(20) NOT NULL,
    reference VARCHAR(100) NOT NULL DEFAULT '',
    note VARCHAR(255) NOT NULL DEFAULT '',
    paid_at DATETIME NOT NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
    CONSTRAINT fk_payments_user FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT chk_payments_kind CHECK (kind IN ('payment', 'refund')),
    CONSTRAINT chk_payments_amount CHECK (amount > 0),
    CONSTRAINT chk_payments_method CHECK (method IN ('bank_transfer', 'cash', 'qris', 'card')),
    INDEX idx_payments_booking (booking_id),
    INDEX idx_payments_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
