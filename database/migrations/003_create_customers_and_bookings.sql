CREATE TABLE customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_customers_phone UNIQUE (phone),
    INDEX idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-year counter behind codes like BK-2026-0042.
CREATE TABLE booking_sequences (
    year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    status VARCHAR(12) NOT NULL,
    source VARCHAR(10) NOT NULL,
    event_date DATE NOT NULL,
    location VARCHAR(255) NOT NULL,
    guest_count INT UNSIGNED NOT NULL,
    setup_start DATETIME NULL,
    teardown_end DATETIME NULL,
    subtotal BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
    discount_reason VARCHAR(255) NULL,
    total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    deposit_percent TINYINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    cancel_reason VARCHAR(500) NULL,
    quoted_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_bookings_code UNIQUE (code),
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_bookings_status CHECK (status IN ('inquiry', 'quoted', 'confirmed', 'completed', 'cancelled')),
    CONSTRAINT chk_bookings_source CHECK (source IN ('inquiry', 'staff')),
    CONSTRAINT chk_bookings_guests CHECK (guest_count > 0),
    CONSTRAINT chk_bookings_window CHECK (teardown_end > setup_start),
    CONSTRAINT chk_bookings_totals CHECK (discount_amount <= subtotal AND total = subtotal - discount_amount),
    CONSTRAINT chk_bookings_deposit CHECK (deposit_percent <= 100),
    INDEX idx_bookings_status_date (status, event_date),
    INDEX idx_bookings_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Name and unit price are copied from the catalog when the quote is saved,
-- so later catalog price changes never alter an existing quote.
CREATE TABLE booking_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    item_type VARCHAR(10) NOT NULL,
    package_id INT UNSIGNED NULL,
    addon_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    catalog_price BIGINT UNSIGNED NOT NULL,
    unit_price BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    line_total BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_items_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_items_package FOREIGN KEY (package_id) REFERENCES packages (id),
    CONSTRAINT fk_booking_items_addon FOREIGN KEY (addon_id) REFERENCES addons (id),
    CONSTRAINT chk_booking_items_type CHECK (
        (item_type = 'package' AND package_id IS NOT NULL AND addon_id IS NULL)
        OR (item_type = 'addon' AND addon_id IS NOT NULL AND package_id IS NULL)
    ),
    CONSTRAINT chk_booking_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_booking_items_line CHECK (line_total = unit_price * quantity),
    INDEX idx_booking_items_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
