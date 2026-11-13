-- ========================================================
-- BEANTRACK: Consolidated Master Database Schema & Seed Data
-- Centralized Web-Based Transaction, Inventory, & Reporting System
-- Standard Philippine Time Zone: Asia/Manila (UTC+8)
-- ========================================================

CREATE DATABASE IF NOT EXISTS `beantrack`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `beantrack`;

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+08:00';
SET NAMES utf8mb4;

-- --------------------------------------------------------
-- 1. Table structure for `roles`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `role_name`) VALUES
(1, 'Administrator'),
(2, 'Supervisor'),
(3, 'Cashier'),
(4, 'Inventory Staff'),
(5, 'Management');

-- --------------------------------------------------------
-- 2. Table structure for `branches`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `address` VARCHAR(255) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `branches` (`id`, `code`, `name`, `address`, `active`) VALUES
(1, 'MAIN', 'Davao Main Branch', 'J. Dela Cruz St., Brgy. 29-C Poblacion, Davao City', 1),
(2, 'MATINA', 'Matina Branch', 'Matina Crossing, Davao City', 1);

-- --------------------------------------------------------
-- 3. Table structure for `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role_id` INT(11) NOT NULL,
  `branch_id` INT(11) NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_branch` (`branch_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role_id`, `branch_id`, `is_active`) VALUES
(1, 'System Administrator', 'admin@beantrack.local', 'password', 1, 1, 1),
(2, 'Supervisor', 'supervisor@beantrack.local', 'password', 2, 1, 1),
(3, 'Cashier One', 'cashier@beantrack.local', 'password', 3, 1, 1),
(4, 'Inventory Staff One', 'inventory@beantrack.local', 'password', 4, 1, 1),
(5, 'Management', 'manager@beantrack.local', 'password', 5, 1, 1);

-- --------------------------------------------------------
-- 4. Table structure for `brands`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `brands`;
CREATE TABLE `brands` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `brands` (`id`, `name`) VALUES
(1, 'HOUSE BLEND'),
(2, 'ARLA'),
(3, 'MONIN'),
(4, 'EASY BRAND');

-- --------------------------------------------------------
-- 5. Table structure for `categories`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`id`, `name`) VALUES
(1, 'Beverage'),
(2, 'Food'),
(3, 'Raw Ingredient'),
(4, 'Packaging');

-- --------------------------------------------------------
-- 6. Table structure for `customers`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `default_price_type` ENUM('SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER') NOT NULL DEFAULT 'SRP',
  `contact_no` VARCHAR(50) NULL,
  `address` VARCHAR(255) NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `customers` (`id`, `name`, `default_price_type`, `contact_no`, `address`, `active`) VALUES
(1, 'WALK-IN', 'SRP', 'N/A', 'Davao City', 1),
(2, 'Cafe Davao Wholesale', 'WHOLESALE', '0917-123-4567', 'Bajada, Davao City', 1),
(3, 'Metro Reseller Partner', 'RESELLER', '0922-987-6543', 'Toril, Davao City', 1);

-- --------------------------------------------------------
-- 7. Table structure for `products`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `brand_id` INT(11) NULL,
  `brand` VARCHAR(80) NOT NULL DEFAULT 'HOUSE BLEND',
  `name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(80) NOT NULL,
  `qty_per_case` INT(11) NOT NULL DEFAULT 1,
  `unit` VARCHAR(30) NOT NULL DEFAULT 'pc',
  `srp` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bulk_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `dealer_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `reseller_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` INT(11) NOT NULL DEFAULT 0,
  `reorder_level` INT(11) NOT NULL DEFAULT 5,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_prod_brand` (`brand_id`),
  CONSTRAINT `fk_prod_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` (`id`, `brand`, `name`, `category`, `qty_per_case`, `unit`, `srp`, `wholesale_price`, `bulk_price`, `dealer_price`, `reseller_price`, `unit_price`, `quantity`, `reorder_level`, `active`) VALUES
(1, 'HOUSE BLEND', 'Iced Coffee', 'Beverage', 1, 'cup', 120.00, 105.00, 95.00, 90.00, 100.00, 120.00, 85, 15, 1),
(2, 'HOUSE BLEND', 'Hot Coffee', 'Beverage', 1, 'cup', 100.00, 90.00, 80.00, 75.00, 85.00, 100.00, 50, 10, 1),
(3, 'HOUSE BLEND', 'Milk Tea', 'Beverage', 1, 'cup', 110.00, 98.00, 88.00, 82.00, 92.00, 110.00, 40, 10, 1),
(4, 'HOUSE BLEND', 'Chocolate Cake', 'Food', 1, 'slice', 150.00, 135.00, 120.00, 115.00, 125.00, 150.00, 20, 5, 1),
(5, 'HOUSE BLEND', 'Ham Sandwich', 'Food', 1, 'pack', 95.00, 85.00, 75.00, 70.00, 80.00, 95.00, 15, 5, 1),
(6, 'HOUSE BLEND', 'Croissant', 'Food', 1, 'pc', 85.00, 75.00, 68.00, 62.00, 70.00, 85.00, 18, 5, 1);

-- --------------------------------------------------------
-- 8. Table structure for `ingredients`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `ingredients`;
CREATE TABLE `ingredients` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `unit` VARCHAR(30) NOT NULL,
  `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `reorder_level` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ingredient_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ingredients` (`id`, `name`, `unit`, `quantity`, `reorder_level`, `active`) VALUES
(1, 'Espresso Coffee Beans', 'g', 15000.000, 2500.000, 1),
(2, 'Fresh Milk', 'ml', 25000.000, 5000.000, 1),
(3, 'Vanilla Syrup', 'ml', 5000.000, 1000.000, 1),
(4, 'Brown Sugar Syrup', 'ml', 6000.000, 1000.000, 1);

-- --------------------------------------------------------
-- 9. Table structure for `product_recipe_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `product_recipe_items`;
CREATE TABLE `product_recipe_items` (
  `product_id` INT(11) NOT NULL,
  `ingredient_id` INT(11) NOT NULL,
  `quantity_required` DECIMAL(12,3) NOT NULL,
  PRIMARY KEY (`product_id`,`ingredient_id`),
  KEY `fk_pri_ingredient` (`ingredient_id`),
  CONSTRAINT `fk_pri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pri_ingredient` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_recipe_items` (`product_id`, `ingredient_id`, `quantity_required`) VALUES
(1, 1, 18.000),  -- Iced Coffee: 18g Espresso Beans
(1, 2, 120.000), -- Iced Coffee: 120ml Milk
(2, 1, 16.000),  -- Hot Coffee: 16g Espresso Beans
(3, 2, 150.000), -- Milk Tea: 150ml Milk
(3, 4, 30.000);  -- Milk Tea: 30ml Brown Sugar Syrup

-- --------------------------------------------------------
-- 10. Table structure for `branch_inventory`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `branch_inventory`;
CREATE TABLE `branch_inventory` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `branch_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_product` (`branch_id`, `product_id`),
  KEY `fk_bi_branch` (`branch_id`),
  KEY `fk_bi_product` (`product_id`),
  CONSTRAINT `fk_bi_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `branch_inventory` (`branch_id`, `product_id`, `quantity`) VALUES
(1, 1, 85.00),
(1, 2, 50.00),
(1, 3, 40.00),
(1, 4, 20.00),
(1, 5, 15.00),
(1, 6, 18.00);

-- --------------------------------------------------------
-- 11. Table structure for `transactions`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` INT(11) NOT NULL,
  `customer_id` INT(11) NULL DEFAULT 1,
  `customer_name` VARCHAR(120) NOT NULL DEFAULT 'WALK-IN',
  `branch_id` INT(11) NOT NULL DEFAULT 1,
  `branch_name` VARCHAR(50) NOT NULL DEFAULT 'DAVAO',
  `transaction_date` DATE NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('Cash','Online') NOT NULL DEFAULT 'Cash',
  `payment_status` ENUM('Paid','Pending') NOT NULL DEFAULT 'Paid',
  `bundle` VARCHAR(100) NULL,
  `remarks` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tr_cashier` (`cashier_id`),
  KEY `idx_tr_customer` (`customer_id`),
  KEY `idx_tr_branch` (`branch_id`),
  CONSTRAINT `fk_tr_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_tr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tr_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 12. Table structure for `transaction_items`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `transaction_items`;
CREATE TABLE `transaction_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` INT(11) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `branch_name` VARCHAR(50) NOT NULL DEFAULT 'DAVAO',
  `brand_name` VARCHAR(80) NOT NULL DEFAULT '',
  `product_name` VARCHAR(150) NOT NULL DEFAULT '',
  `price_type` ENUM('SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER') NOT NULL DEFAULT 'SRP',
  `ordered_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `unit_qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `quantity` INT(11) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `price_seq` DATE NULL,
  `bundle_seq` VARCHAR(50) NOT NULL DEFAULT 'NOT A BUNDLE',
  `remarks` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ti_trans` (`transaction_id`),
  KEY `fk_ti_product` (`product_id`),
  CONSTRAINT `fk_ti_trans` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ti_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 13. Table structure for `supplier_deliveries`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `supplier_deliveries`;
CREATE TABLE `supplier_deliveries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date_arrived` DATE NOT NULL,
  `supplier` VARCHAR(150) NOT NULL,
  `po_no` VARCHAR(80) NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `branch_name` VARCHAR(50) NOT NULL DEFAULT 'DAVAO',
  `product_id` INT(11) NOT NULL,
  `brand_name` VARCHAR(80) NOT NULL,
  `item_name` VARCHAR(150) NOT NULL,
  `cases` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `qty` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) NULL,
  `received_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sd_date` (`date_arrived`),
  KEY `idx_sd_branch` (`branch_id`),
  KEY `idx_sd_product` (`product_id`),
  CONSTRAINT `fk_sd_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_sd_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sd_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 14. Table structure for `inventory_spoilage`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `inventory_spoilage`;
CREATE TABLE `inventory_spoilage` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `branch_id` INT(11) NOT NULL,
  `branch_name` VARCHAR(50) NOT NULL DEFAULT 'DAVAO',
  `product_id` INT(11) NOT NULL,
  `brand_name` VARCHAR(80) NOT NULL,
  `item_name` VARCHAR(150) NOT NULL,
  `qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `remarks` VARCHAR(255) NOT NULL,
  `logged_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sp_date` (`date`),
  KEY `idx_sp_branch` (`branch_id`),
  KEY `idx_sp_product` (`product_id`),
  CONSTRAINT `fk_sp_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_sp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_sp_user` FOREIGN KEY (`logged_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 15. Table structure for `inventory_transfers`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `inventory_transfers`;
CREATE TABLE `inventory_transfers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `date` DATE NOT NULL,
  `from_branch_id` INT(11) NOT NULL,
  `from_branch_name` VARCHAR(50) NOT NULL,
  `to_branch_id` INT(11) NOT NULL,
  `to_branch_name` VARCHAR(50) NOT NULL,
  `product_id` INT(11) NOT NULL,
  `brand_name` VARCHAR(80) NOT NULL,
  `item_name` VARCHAR(150) NOT NULL,
  `qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `remarks` VARCHAR(255) NULL,
  `transferred_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_it_date` (`date`),
  KEY `idx_it_from` (`from_branch_id`),
  KEY `idx_it_to` (`to_branch_id`),
  KEY `idx_it_product` (`product_id`),
  CONSTRAINT `fk_it_from` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_it_to` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_it_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_it_user` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 16. Table structure for `audit_trail`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `audit_trail`;
CREATE TABLE `audit_trail` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `action` VARCHAR(120) NOT NULL,
  `table_name` VARCHAR(80) DEFAULT NULL,
  `record_id` INT(11) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `previous_hash` CHAR(64) DEFAULT NULL,
  `entry_hash` CHAR(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_at_user` (`user_id`),
  CONSTRAINT `fk_at_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 17. Table structure for `audit_chain_state`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `audit_chain_state`;
CREATE TABLE `audit_chain_state` (
  `id` TINYINT(3) UNSIGNED NOT NULL,
  `last_id` INT(11) NOT NULL,
  `chain_start_id` INT(11) NOT NULL,
  `last_hash` CHAR(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `audit_chain_state` (`id`, `last_id`, `chain_start_id`, `last_hash`) VALUES
(1, 0, 1, '0000000000000000000000000000000000000000000000000000000000000000');

SET FOREIGN_KEY_CHECKS = 1;

-- ========================================================
-- Master database schema created successfully.
-- ========================================================
