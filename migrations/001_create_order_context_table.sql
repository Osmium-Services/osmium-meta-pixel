-- What the visitor's own browser told us when they started checkout. A payment
-- webhook has no cookies, so the Purchase sent to Meta later reads it from here.
CREATE TABLE IF NOT EXISTS {PREFIX}meta_pixel_order_context (
    order_id INT UNSIGNED NOT NULL PRIMARY KEY,
    consent TINYINT(1) NOT NULL DEFAULT 0,
    fbp VARCHAR(255) DEFAULT NULL,
    fbc VARCHAR(255) DEFAULT NULL,
    event_source_url VARCHAR(500) DEFAULT NULL,
    capi_sent_at DATETIME DEFAULT NULL,
    capi_result VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
