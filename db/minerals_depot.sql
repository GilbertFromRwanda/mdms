-- MDMS Database Backup
-- Generated: 2026-07-05 23:13:02
-- Database: minerals_depot

SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table: account_transactions
-- ----------------------------
DROP TABLE IF EXISTS `account_transactions`;
CREATE TABLE `account_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `txn_type` enum('credit','debit') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `account_transactions` (`id`, `account_id`, `txn_type`, `amount`, `balance_after`, `reference_type`, `reference_id`, `description`, `created_by`, `created_at`) VALUES ('1', '1', 'debit', '2700000.00', '15274131.00', 'purchase', '1', 'Supplier payment', '1', '2026-06-23 17:38:58');
INSERT INTO `account_transactions` (`id`, `account_id`, `txn_type`, `amount`, `balance_after`, `reference_type`, `reference_id`, `description`, `created_by`, `created_at`) VALUES ('2', '1', 'debit', '2529819.60', '12744311.40', 'purchase', '2', 'Supplier payment', '1', '2026-07-05 22:25:51');

-- ----------------------------
-- Table: audit_log
-- ----------------------------
DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('1', '1', 'CREATE', 'batches', '1', 'Added batch: BATCH-20260623-2493', '127.0.0.1', '2026-06-23 17:38:58');
INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('2', '1', 'CREATE', 'batches', '2', 'Added batch: BATCH-20260705-5284', '127.0.0.1', '2026-07-05 22:25:51');
INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('3', '1', 'CREATE', 'manual_journal', '1', 'Manual credit: Kwishyura alexis — 40000 FRW', '127.0.0.1', '2026-07-05 22:28:38');
INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('4', '1', 'CREATE', 'manual_journal', '2', 'Manual debit: muhire kugura ibirayi — 60000 FRW', '127.0.0.1', '2026-07-05 22:33:43');
INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('5', '1', 'CREATE', 'manual_journal', '3', 'Manual credit: Inguzanyo iva muri bnr — 100000 FRW', '127.0.0.1', '2026-07-05 22:36:57');
INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('6', '1', 'CREATE', 'manual_journal', '4', 'Manual debit: kwishyura esanse — 50000 FRW', '127.0.0.1', '2026-07-05 22:37:50');
INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES ('7', '1', 'CREATE', 'manual_journal', '5', 'Manual debit: Nguze unuriro — 15000 FRW', '127.0.0.1', '2026-07-05 22:56:54');

-- ----------------------------
-- Table: batches
-- ----------------------------
DROP TABLE IF EXISTS `batches`;
CREATE TABLE `batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(50) NOT NULL,
  `mineral_type_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `quality_grade` varchar(20) DEFAULT NULL,
  `origin_location` varchar(200) DEFAULT NULL,
  `received_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `certificate_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_id` (`batch_id`),
  KEY `mineral_type_id` (`mineral_type_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_received_date` (`received_date`),
  CONSTRAINT `batches_ibfk_1` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`),
  CONSTRAINT `batches_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `batches_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `batches` (`id`, `batch_id`, `mineral_type_id`, `supplier_id`, `quantity`, `quality_grade`, `origin_location`, `received_date`, `expiry_date`, `certificate_number`, `notes`, `created_by`, `created_at`) VALUES ('1', 'BATCH-20260623-2493', '2', '3', '10.000', 'Standard', '', '2026-06-23', NULL, '', '', '1', '2026-06-23 17:38:58');
INSERT INTO `batches` (`id`, `batch_id`, `mineral_type_id`, `supplier_id`, `quantity`, `quality_grade`, `origin_location`, `received_date`, `expiry_date`, `certificate_number`, `notes`, `created_by`, `created_at`) VALUES ('2', 'BATCH-20260705-5284', '2', '2', '10.000', 'Standard', '', '2026-07-05', NULL, '', '', '1', '2026-07-05 22:25:51');

-- ----------------------------
-- Table: buyer_loans
-- ----------------------------
DROP TABLE IF EXISTS `buyer_loans`;
CREATE TABLE `buyer_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `buyer_id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `type` enum('loan','repayment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','bank','momo') DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `is_advance` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `buyer_id` (`buyer_id`),
  KEY `sale_id` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: buyers
-- ----------------------------
DROP TABLE IF EXISTS `buyers`;
CREATE TABLE `buyers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `buyer_code` varchar(30) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `buyers` (`id`, `buyer_code`, `name`, `contact`, `phone`, `email`, `address`, `created_at`) VALUES ('1', 'BUY-20260523-854', 'mugurusu', '', '0783547548', '', '', '2026-05-23 20:13:13');
INSERT INTO `buyers` (`id`, `buyer_code`, `name`, `contact`, `phone`, `email`, `address`, `created_at`) VALUES ('2', 'BUY-20260524-171', 'Kalisa john muhozi', '', '0789047264', '', '', '2026-05-24 12:59:01');
INSERT INTO `buyers` (`id`, `buyer_code`, `name`, `contact`, `phone`, `email`, `address`, `created_at`) VALUES ('3', 'BUY-20260526-809', 'manzi', '', '0773563428', '', '', '2026-05-26 20:53:37');

-- ----------------------------
-- Table: company_accounts
-- ----------------------------
DROP TABLE IF EXISTS `company_accounts`;
CREATE TABLE `company_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_type` enum('cash','bank','momo') NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `company_accounts` (`id`, `account_type`, `account_name`, `balance`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'cash', 'cash rwf', '12744311.40', '', '1', '1', '2026-05-23 18:46:02', '2026-07-05 22:25:51');
INSERT INTO `company_accounts` (`id`, `account_type`, `account_name`, `balance`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'cash', 'cash usd', '2000.00', '', '1', '1', '2026-05-23 18:50:15', '2026-05-23 18:50:15');
INSERT INTO `company_accounts` (`id`, `account_type`, `account_name`, `balance`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'momo', 'momo', '2000000.00', '', '1', '1', '2026-05-23 18:50:44', '2026-05-23 19:24:28');

-- ----------------------------
-- Table: expenses
-- ----------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Other',
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank','momo','mixed') NOT NULL DEFAULT 'cash',
  `account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `expense_date` (`expense_date`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: inventory
-- ----------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mineral_type_id` int(11) DEFAULT NULL,
  `current_stock` decimal(12,3) DEFAULT 0.000,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `mineral_type_id` (`mineral_type_id`),
  CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `inventory` (`id`, `mineral_type_id`, `current_stock`, `last_updated`) VALUES ('1', '2', '20.000', '2026-07-05 22:25:51');

-- ----------------------------
-- Table: manual_journal
-- ----------------------------
DROP TABLE IF EXISTS `manual_journal`;
CREATE TABLE `manual_journal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `comment` varchar(255) NOT NULL,
  `entry_type` enum('credit','debit') NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_entry_date` (`entry_date`),
  KEY `idx_entry_type` (`entry_type`),
  CONSTRAINT `manual_journal_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `manual_journal` (`id`, `entry_date`, `amount`, `comment`, `entry_type`, `created_by`, `created_at`) VALUES ('1', '2026-07-05', '40000.00', 'Kwishyura alexis', 'credit', '1', '2026-07-05 22:28:38');
INSERT INTO `manual_journal` (`id`, `entry_date`, `amount`, `comment`, `entry_type`, `created_by`, `created_at`) VALUES ('2', '2026-07-05', '60000.00', 'muhire kugura ibirayi', 'debit', '1', '2026-07-05 22:33:43');
INSERT INTO `manual_journal` (`id`, `entry_date`, `amount`, `comment`, `entry_type`, `created_by`, `created_at`) VALUES ('3', '2026-07-05', '100000.00', 'Inguzanyo iva muri bnr', 'credit', '1', '2026-07-05 22:36:57');
INSERT INTO `manual_journal` (`id`, `entry_date`, `amount`, `comment`, `entry_type`, `created_by`, `created_at`) VALUES ('4', '2026-07-05', '50000.00', 'kwishyura esanse', 'debit', '1', '2026-07-05 22:37:50');
INSERT INTO `manual_journal` (`id`, `entry_date`, `amount`, `comment`, `entry_type`, `created_by`, `created_at`) VALUES ('5', '2026-07-05', '15000.00', 'Nguze unuriro', 'debit', '1', '2026-07-05 22:56:54');

-- ----------------------------
-- Table: mineral_price_settings
-- ----------------------------
DROP TABLE IF EXISTS `mineral_price_settings`;
CREATE TABLE `mineral_price_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mineral_type_id` int(11) NOT NULL,
  `quality_grade` varchar(20) NOT NULL,
  `purchase_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mineral_grade` (`mineral_type_id`,`quality_grade`),
  CONSTRAINT `mineral_price_settings_ibfk_1` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: mineral_types
-- ----------------------------
DROP TABLE IF EXISTS `mineral_types`;
CREATE TABLE `mineral_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `unit` varchar(20) DEFAULT 'kg',
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `mineral_types` (`id`, `name`, `unit`, `description`) VALUES ('2', 'Coltan', 'kg', NULL);
INSERT INTO `mineral_types` (`id`, `name`, `unit`, `description`) VALUES ('3', 'Cassiterite', 'kg', NULL);
INSERT INTO `mineral_types` (`id`, `name`, `unit`, `description`) VALUES ('4', 'Wolframite', 'kg', NULL);

-- ----------------------------
-- Table: purchase_details
-- ----------------------------
DROP TABLE IF EXISTS `purchase_details`;
CREATE TABLE `purchase_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `mineral_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_date` date NOT NULL,
  `currency_used` varchar(3) NOT NULL DEFAULT 'FRW',
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `sample` decimal(10,6) DEFAULT NULL,
  `rwf_rate` decimal(10,2) DEFAULT NULL,
  `fees_1` decimal(12,2) DEFAULT NULL,
  `fees_2` decimal(12,2) DEFAULT NULL,
  `tag` decimal(12,2) DEFAULT NULL,
  `rma` decimal(12,2) DEFAULT NULL,
  `rra` decimal(15,6) DEFAULT NULL,
  `lma` decimal(12,4) DEFAULT NULL,
  `tmt` decimal(12,4) DEFAULT NULL,
  `tantal` decimal(12,4) DEFAULT NULL,
  `unit_price` decimal(15,6) DEFAULT NULL,
  `take_home` decimal(15,2) DEFAULT NULL,
  `loan_action` enum('give','deduct','none') NOT NULL DEFAULT 'none',
  `loan_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15,2) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `mineral_id` (`mineral_id`),
  KEY `purchase_date` (`purchase_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchase_details` (`id`, `batch_id`, `mineral_id`, `supplier_id`, `purchase_date`, `currency_used`, `qty`, `sample`, `rwf_rate`, `fees_1`, `fees_2`, `tag`, `rma`, `rra`, `lma`, `tmt`, `tantal`, `unit_price`, `take_home`, `loan_action`, `loan_amount`, `amount_paid`, `comment`, `created_by`, `created_at`) VALUES ('1', '1', '2', '3', '2026-06-23', 'FRW', '10.000', '45.000000', '1466.00', NULL, NULL, '2000.00', '190.00', '9796.545000', NULL, NULL, '4.5000', '284878.455000', '2848784.55', 'none', '0.00', '2700000.00', '', '1', '2026-06-23 17:38:58');
INSERT INTO `purchase_details` (`id`, `batch_id`, `mineral_id`, `supplier_id`, `purchase_date`, `currency_used`, `qty`, `sample`, `rwf_rate`, `fees_1`, `fees_2`, `tag`, `rma`, `rra`, `lma`, `tmt`, `tantal`, `unit_price`, `take_home`, `loan_action`, `loan_amount`, `amount_paid`, `comment`, `created_by`, `created_at`) VALUES ('2', '2', '2', '2', '2026-07-05', 'FRW', '10.000', '40.000000', '1466.00', NULL, NULL, '2000.00', '190.00', '8708.040000', NULL, NULL, '4.5000', '252981.960000', '2529819.60', 'none', '0.00', '2529819.60', '', '1', '2026-07-05 22:25:51');

-- ----------------------------
-- Table: purchase_payments
-- ----------------------------
DROP TABLE IF EXISTS `purchase_payments`;
CREATE TABLE `purchase_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `payment_method` enum('cash','bank','momo') NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `purchase_payments` (`id`, `batch_id`, `supplier_id`, `payment_method`, `account_id`, `amount`, `created_by`, `created_at`) VALUES ('1', '1', '3', 'cash', '1', '2700000.00', '1', '2026-06-23 17:38:58');
INSERT INTO `purchase_payments` (`id`, `batch_id`, `supplier_id`, `payment_method`, `account_id`, `amount`, `created_by`, `created_at`) VALUES ('2', '2', '2', 'cash', '1', '2529819.60', '1', '2026-07-05 22:25:51');

-- ----------------------------
-- Table: sale_details
-- ----------------------------
DROP TABLE IF EXISTS `sale_details`;
CREATE TABLE `sale_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `mineral_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `currency_used` varchar(3) NOT NULL DEFAULT 'FRW',
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `selling_price` decimal(15,4) DEFAULT NULL,
  `cost_price` decimal(15,4) DEFAULT NULL,
  `total_revenue` decimal(15,2) DEFAULT NULL,
  `total_cost` decimal(15,2) DEFAULT NULL,
  `benefit` decimal(15,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `mineral_id` (`mineral_id`),
  KEY `buyer_id` (`buyer_id`),
  KEY `sale_date` (`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: sales
-- ----------------------------
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` varchar(50) NOT NULL,
  `mineral_type_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `sale_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sale_id` (`sale_id`),
  KEY `mineral_type_id` (`mineral_type_id`),
  KEY `buyer_id` (`buyer_id`),
  KEY `sale_date` (`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: subscription
-- ----------------------------
DROP TABLE IF EXISTS `subscription`;
CREATE TABLE `subscription` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_name` varchar(255) NOT NULL,
  `client_email` varchar(255) DEFAULT NULL,
  `client_phone` varchar(100) DEFAULT NULL,
  `plan_name` varchar(100) DEFAULT 'Monthly',
  `amount` decimal(12,2) DEFAULT 0.00,
  `start_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `grace_days` tinyint(3) unsigned DEFAULT 3,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `subscription` (`id`, `client_name`, `client_email`, `client_phone`, `plan_name`, `amount`, `start_date`, `expiry_date`, `grace_days`, `is_active`, `notes`, `created_at`, `updated_at`) VALUES ('1', 'Kigali mini company', 'askforgilbert@gmail.com', '+250789047173', 'Annual', '100000.00', '2026-06-23', '2027-06-23', '3', '1', '', '2026-06-23 17:27:50', '2026-06-23 17:27:56');

-- ----------------------------
-- Table: subscription_payments
-- ----------------------------
DROP TABLE IF EXISTS `subscription_payments`;
CREATE TABLE `subscription_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `payment_method` enum('cash','bank','momo') DEFAULT 'cash',
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `subscription_payments_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: supplier_loans
-- ----------------------------
DROP TABLE IF EXISTS `supplier_loans`;
CREATE TABLE `supplier_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `type` enum('loan','repayment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deferred` tinyint(1) NOT NULL DEFAULT 0,
  `payment_method` enum('cash','bank','momo') DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `batch_id` (`batch_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_sl_type` (`type`),
  CONSTRAINT `supplier_loans_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `supplier_loans_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  CONSTRAINT `supplier_loans_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `supplier_loans` (`id`, `supplier_id`, `batch_id`, `type`, `amount`, `notes`, `created_by`, `created_at`, `is_deferred`, `payment_method`, `account_id`) VALUES ('1', '3', '1', 'loan', '148784.55', '', '1', '2026-06-23 17:38:58', '1', NULL, NULL);

-- ----------------------------
-- Table: suppliers
-- ----------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_code` (`supplier_code`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('1', 'SUP-20260523-979', 'jilbert', '', '0789179400', '', '', '', '2026-05-23 18:33:53');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('2', 'SUP-20260523-501', 'kwizera', '', '0725047173', '', '', '', '2026-05-23 18:57:52');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('3', 'SUP-20260523-307', 'amina', '', '0789047170', '', '', '', '2026-05-23 19:07:17');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('4', 'SUP-20260523-946', 'alex', '', '079989776', '', '', '', '2026-05-23 19:09:56');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('5', 'SUP-20260523-376', 'amire', '', '07311301', '', '', '', '2026-05-23 19:12:36');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('6', 'SUP-20260523-532', 'mutabaruka', '', '07878572', '', '', '', '2026-05-23 19:16:21');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('8', 'SUP-20260524-600', 'mucyo gaston', 'Niyonsaba Gilbert', '+250789047190', '', '', NULL, '2026-05-24 12:54:41');
INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES ('9', 'SUP-20260526-507', 'gaju', '', '078364787', '', '', '', '2026-05-26 20:50:50');

-- ----------------------------
-- Table: supply_stock
-- ----------------------------
DROP TABLE IF EXISTS `supply_stock`;
CREATE TABLE `supply_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `mineral_id` int(11) NOT NULL,
  `qty` decimal(10,3) NOT NULL DEFAULT 0.000,
  `advance_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('in','out','sold') NOT NULL DEFAULT 'in',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `mineral_id` (`mineral_id`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `supply_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `supply_stock_ibfk_2` FOREIGN KEY (`mineral_id`) REFERENCES `mineral_types` (`id`),
  CONSTRAINT `supply_stock_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `supply_stock_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `supply_stock` (`id`, `supplier_id`, `mineral_id`, `qty`, `advance_amount`, `status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES ('1', '1', '2', '4.000', '0.00', 'in', '', '1', '1', '2026-05-24 11:11:32', '2026-05-24 17:19:29');
INSERT INTO `supply_stock` (`id`, `supplier_id`, `mineral_id`, `qty`, `advance_amount`, `status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES ('2', '8', '3', '15.000', '0.00', 'in', '', '1', NULL, '2026-05-24 17:13:31', '2026-05-24 17:13:31');
INSERT INTO `supply_stock` (`id`, `supplier_id`, `mineral_id`, `qty`, `advance_amount`, `status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES ('3', '5', '2', '12.000', '1000000.00', 'in', '', '1', NULL, '2026-05-26 20:20:41', '2026-05-26 20:20:41');

-- ----------------------------
-- Table: supply_stock_payments
-- ----------------------------
DROP TABLE IF EXISTS `supply_stock_payments`;
CREATE TABLE `supply_stock_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supply_stock_id` int(11) NOT NULL,
  `payment_method` enum('cash','bank','momo') NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `supply_stock_id` (`supply_stock_id`),
  KEY `account_id` (`account_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `supply_stock_payments_ibfk_1` FOREIGN KEY (`supply_stock_id`) REFERENCES `supply_stock` (`id`) ON DELETE CASCADE,
  CONSTRAINT `supply_stock_payments_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `company_accounts` (`id`),
  CONSTRAINT `supply_stock_payments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `supply_stock_payments` (`id`, `supply_stock_id`, `payment_method`, `account_id`, `amount`, `created_by`, `created_at`) VALUES ('1', '3', 'cash', '1', '1000000.00', '1', '2026-05-26 20:20:41');

-- ----------------------------
-- Table: transactions
-- ----------------------------
DROP TABLE IF EXISTS `transactions`;
CREATE TABLE `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_code` varchar(50) NOT NULL,
  `transaction_type` enum('IN','OUT') NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `mineral_type_id` int(11) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `transaction_date` date NOT NULL,
  `price_per_unit` decimal(15,2) DEFAULT NULL,
  `total_amount` decimal(15,2) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `recipient_company` varchar(200) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_code` (`transaction_code`),
  KEY `batch_id` (`batch_id`),
  KEY `mineral_type_id` (`mineral_type_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_tx_type_date` (`transaction_type`,`transaction_date`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`),
  CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `transactions` (`id`, `transaction_code`, `transaction_type`, `batch_id`, `mineral_type_id`, `quantity`, `transaction_date`, `price_per_unit`, `total_amount`, `reference_number`, `recipient_company`, `driver_name`, `vehicle_number`, `notes`, `created_by`, `created_at`) VALUES ('1', 'TRX-20260623173858-95', 'IN', '1', '2', '10.000', '2026-06-23', '284878.46', '2848784.55', NULL, NULL, NULL, NULL, NULL, '1', '2026-06-23 17:38:58');
INSERT INTO `transactions` (`id`, `transaction_code`, `transaction_type`, `batch_id`, `mineral_type_id`, `quantity`, `transaction_date`, `price_per_unit`, `total_amount`, `reference_number`, `recipient_company`, `driver_name`, `vehicle_number`, `notes`, `created_by`, `created_at`) VALUES ('2', 'TRX-20260705222551-20', 'IN', '2', '2', '10.000', '2026-07-05', '252981.96', '2529819.60', NULL, NULL, NULL, NULL, NULL, '1', '2026-07-05 22:25:51');

-- ----------------------------
-- Table: users
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','manager','storekeeper','superadmin') NOT NULL DEFAULT 'manager',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `created_at`) VALUES ('1', 'admin', '$2y$10$yZbJIFYptQeUGb3XDjguJeqA646QcZASRNw5XDlCe/KUnPxO6a2Mm', 'Claudine', '', 'admin', '2026-04-24 12:51:37');
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `created_at`) VALUES ('2', 'user', '$2y$10$2JX.mR5MhOCEic0TLO2gHe3y5fJ1th.aM9LUJPvhwBoXWLSTIdzue', 'muhizi gaston', '', 'manager', '2026-04-24 13:41:38');
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `created_at`) VALUES ('3', 'system', '$2y$10$NDGsmCG4767/JlXh8hPV8uzoYpTT6gjdMjl0rvhOFzBQ9B2oIgEdq', 'System Owner', 'system@mdms.local', '', '2026-06-23 10:00:56');
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `created_at`) VALUES ('4', 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', NULL, 'superadmin', '2026-07-05 22:28:01');

SET FOREIGN_KEY_CHECKS=1;
