-- CM Comercial enterprise relational schema.
-- Target: MySQL 8.0+ / InnoDB.

CREATE DATABASE IF NOT EXISTS cm_comercial
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE cm_comercial;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(180) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_active_name (active, name),
    CONSTRAINT chk_products_price CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NULL,
    status ENUM('pending','confirmed','preparing','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
    payment_status ENUM('pending','authorized','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    total_amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    shipping_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_idempotency (idempotency_key),
    KEY idx_orders_customer_status (customer_id, status),
    KEY idx_orders_payment_status (payment_status, created_at),
    CONSTRAINT chk_orders_total CHECK (total_amount >= 0),
    CONSTRAINT chk_orders_shipping CHECK (shipping_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    sku VARCHAR(64) NOT NULL,
    product_name VARCHAR(180) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    line_total DECIMAL(12,2) AS (unit_price * quantity) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_order_product (order_id, product_id),
    KEY idx_order_items_product (product_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT chk_order_items_quantity CHECK (quantity > 0),
    CONSTRAINT chk_order_items_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    provider_payment_id VARCHAR(100) NULL,
    external_reference VARCHAR(255) NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    status ENUM('pending','authorized','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'BRL',
    pix_qr_code TEXT NULL,
    pix_qr_code_base64 LONGTEXT NULL,
    pix_expires_at DATETIME(6) NULL,
    gateway_payload JSON NULL,
    webhook_event_id VARCHAR(255) NULL,
    last_webhook_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_idempotency (provider, idempotency_key),
    UNIQUE KEY uq_payment_provider_id (provider, provider_payment_id),
    UNIQUE KEY uq_payment_webhook_event (provider, webhook_event_id),
    KEY idx_payment_order_status (order_id, status),
    KEY idx_payment_external_reference (external_reference),
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- Optional generic audit trail for financial lifecycle changes.
CREATE TABLE IF NOT EXISTS payment_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_transaction_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    actor VARCHAR(100) NOT NULL DEFAULT 'system',
    payload JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_payment_audit_payment (payment_transaction_id, created_at),
    CONSTRAINT fk_payment_audit_payment FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Stock reservation operation example:
-- START TRANSACTION;
-- SELECT id, stock_quantity, price, sku, name FROM products WHERE id IN (...) ORDER BY id FOR UPDATE;
-- Validate stock quantities, then UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?;
-- INSERT order + order_items + payment_transactions;
-- COMMIT;
