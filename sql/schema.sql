-- Customer Loyalty Rewards System Database Schema
CREATE DATABASE IF NOT EXISTS loyalty_rewards_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE loyalty_rewards_db;

-- 1. Factory Products Catalog
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    points_value INT NOT NULL DEFAULT 50,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Manufacturing Batches
CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(60) NOT NULL UNIQUE,
    product_id INT NOT NULL,
    manufactured_date DATE NOT NULL,
    expiry_date DATE NULL,
    quantity_generated INT NOT NULL DEFAULT 0,
    status ENUM('active', 'recalled', 'expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 3. Customers (Identified by mobile phone number)
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(150) NULL,
    points_balance INT NOT NULL DEFAULT 0,
    total_points_earned INT NOT NULL DEFAULT 0,
    is_flagged TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 4. Unique Scratch/Inside-Box Codes
CREATE TABLE IF NOT EXISTS box_codes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    code_plain VARCHAR(32) NOT NULL UNIQUE,
    code_signature VARCHAR(16) NOT NULL,
    status ENUM('unclaimed', 'claimed', 'voided') NOT NULL DEFAULT 'unclaimed',
    claimed_by_customer_id INT NULL,
    claimed_at DATETIME NULL,
    claim_ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code_plain (code_plain),
    INDEX idx_status (status),
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    FOREIGN KEY (claimed_by_customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 5. Reward Catalog (Items customers can redeem points for)
CREATE TABLE IF NOT EXISTS reward_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    points_required INT NOT NULL,
    stock_available INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 6. Reward Redemptions
CREATE TABLE IF NOT EXISTS redemptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    reward_id INT NOT NULL,
    points_spent INT NOT NULL,
    voucher_code VARCHAR(32) NOT NULL UNIQUE,
    status ENUM('pending', 'fulfilled', 'cancelled') NOT NULL DEFAULT 'pending',
    redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (reward_id) REFERENCES reward_catalog(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7. Security & Anti-Fraud Audit Log
CREATE TABLE IF NOT EXISTS claim_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    phone_number VARCHAR(20) NULL,
    code_submitted VARCHAR(50) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    failure_reason VARCHAR(120) NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- 8. Factory Admin Users
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'owner',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
