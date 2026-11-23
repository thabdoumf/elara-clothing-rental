-- Élara database compatibility updates
-- Target: MariaDB 10.4+ (the version recorded in location_vetement.sql)
-- Run this file once in phpMyAdmin after selecting the location_vetement database.
-- Existing records are preserved. CREATE TABLE IF NOT EXISTS leaves existing tables untouched.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS wilaya VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS dob DATE NULL,
    ADD COLUMN IF NOT EXISTS gender ENUM('Men','Women','Unisex') NULL;

ALTER TABLE rentals
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(80) NULL;

CREATE TABLE IF NOT EXISTS cart (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    size ENUM('S','M','L','XL') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_user_product_size (user_id, product_id, size),
    KEY idx_cart_product_size (product_id, size),
    CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    code CHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_password_resets_email_expiry (email, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
