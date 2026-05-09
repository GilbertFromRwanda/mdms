-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 09, 2026 at 10:01 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `minerals_depot`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_transactions`
--

CREATE TABLE `account_transactions` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `txn_type` enum('credit','debit') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `balance_after` decimal(15,2) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `buyers`
--

CREATE TABLE `buyers` (
  `id` int(11) NOT NULL,
  `buyer_code` varchar(30) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `buyers`
--

INSERT INTO `buyers` (`id`, `buyer_code`, `name`, `contact`, `phone`, `email`, `address`, `created_at`) VALUES
(1, 'BUY-20260430-810', 'Abc minerals', 'Kamarade james', '0789402645', '', 'kigali', '2026-04-30 14:08:46'),
(2, 'BUY-20260508-970', 'Albert supply', 'Muhizi gaston', '+2507890471731', '', 'KG 59 St +54', '2026-05-08 13:40:56');

-- --------------------------------------------------------

--
-- Table structure for table `buyer_loans`
--

CREATE TABLE `buyer_loans` (
  `id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `type` enum('loan','repayment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` enum('cash','bank','momo') DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `is_advance` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_accounts`
--

CREATE TABLE `company_accounts` (
  `id` int(11) NOT NULL,
  `account_type` enum('cash','bank','momo') NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_accounts`
--

INSERT INTO `company_accounts` (`id`, `account_type`, `account_name`, `balance`, `notes`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'cash', 'Cash box', 60125.00, '', 1, 1, '2026-05-05 10:48:51', '2026-05-09 18:38:10'),
(2, 'momo', 'Mobile Money', 900000.00, '', 1, 1, '2026-05-05 10:49:54', '2026-05-09 19:57:08'),
(3, 'bank', 'Bk', 1458000.00, '', 1, 1, '2026-05-05 10:50:15', '2026-05-09 19:32:50'),
(4, 'bank', 'Equity', 14850000.00, '', 1, 1, '2026-05-05 10:51:00', '2026-05-09 19:37:54');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Other',
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank','momo','mixed') NOT NULL DEFAULT 'cash',
  `account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `mineral_type_id` int(11) DEFAULT NULL,
  `current_stock` decimal(12,3) DEFAULT 0.000,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mineral_price_settings`
--

CREATE TABLE `mineral_price_settings` (
  `id` int(11) NOT NULL,
  `mineral_type_id` int(11) NOT NULL,
  `quality_grade` varchar(20) NOT NULL,
  `purchase_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mineral_types`
--

CREATE TABLE `mineral_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `unit` varchar(20) DEFAULT 'kg',
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mineral_types`
--

INSERT INTO `mineral_types` (`id`, `name`, `unit`, `description`) VALUES
(2, 'Coltan', 'kg', NULL),
(3, 'Cassiterite', 'kg', NULL),
(4, 'Wolframite', 'kg', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_details`
--

CREATE TABLE `purchase_details` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments`
--

CREATE TABLE `purchase_payments` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `payment_method` enum('cash','bank','momo') NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `sale_id` varchar(50) NOT NULL,
  `mineral_type_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `sale_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sale_details`
--

CREATE TABLE `sale_details` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `supplier_code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_code`, `name`, `contact_person`, `phone`, `email`, `license_number`, `address`, `created_at`) VALUES
(1, 'SUP-20260430-662', 'Niyonsaba Gilbert', 'Niyonsaba Gilbert', '+250789047173', '', '', 'KG 59 St +54', '2026-04-30 14:07:48'),
(2, 'SUP-20260430-653', 'bamukunde amina', '', '07890471732', '', '', '', '2026-04-30 14:08:08'),
(3, 'SUP-20260506-115', 'kwizera elyse', 'kwizera elyse', '+250789047173', '', '', 'KG 59 St +54', '2026-05-06 10:39:33'),
(4, 'SUP-20260509-227', 'muhozi ltd', 'James muvara', '+250789047173', '', '', 'KG 59 St +54', '2026-05-09 17:17:19'),
(5, 'SUP-20260509-675', 'Mizero mining', 'Mizero oda', '+250789047171', '', '', 'KG 59 St +54', '2026-05-09 19:05:12');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_loans`
--

CREATE TABLE `supplier_loans` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `type` enum('loan','repayment') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deferred` tinyint(1) NOT NULL DEFAULT 0,
  `payment_method` enum('cash','bank','momo') DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `transactions`
--
DELIMITER $$
CREATE TRIGGER `update_inventory_after_transaction` AFTER INSERT ON `transactions` FOR EACH ROW BEGIN
    IF NEW.transaction_type = 'IN' THEN
        INSERT INTO inventory (mineral_type_id, current_stock)
        VALUES (NEW.mineral_type_id, NEW.quantity)
        ON DUPLICATE KEY UPDATE 
        current_stock = current_stock + NEW.quantity;
    ELSEIF NEW.transaction_type = 'OUT' THEN
        INSERT INTO inventory (mineral_type_id, current_stock)
        VALUES (NEW.mineral_type_id, -NEW.quantity)
        ON DUPLICATE KEY UPDATE 
        current_stock = current_stock - NEW.quantity;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','manager','storekeeper') DEFAULT 'storekeeper',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `email`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$yZbJIFYptQeUGb3XDjguJeqA646QcZASRNw5XDlCe/KUnPxO6a2Mm', 'Administrator', NULL, 'admin', '2026-04-24 10:51:37'),
(2, 'user', '$2y$10$2JX.mR5MhOCEic0TLO2gHe3y5fJ1th.aM9LUJPvhwBoXWLSTIdzue', 'muhizi gaston', '', 'manager', '2026-04-24 11:41:38');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_transactions`
--
ALTER TABLE `account_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `batch_id` (`batch_id`),
  ADD KEY `mineral_type_id` (`mineral_type_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `buyers`
--
ALTER TABLE `buyers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `buyer_loans`
--
ALTER TABLE `buyer_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `company_accounts`
--
ALTER TABLE `company_accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expense_date` (`expense_date`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mineral_type_id` (`mineral_type_id`);

--
-- Indexes for table `mineral_price_settings`
--
ALTER TABLE `mineral_price_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mineral_grade` (`mineral_type_id`,`quality_grade`);

--
-- Indexes for table `mineral_types`
--
ALTER TABLE `mineral_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `purchase_details`
--
ALTER TABLE `purchase_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `mineral_id` (`mineral_id`),
  ADD KEY `purchase_date` (`purchase_date`);

--
-- Indexes for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sale_id` (`sale_id`),
  ADD KEY `mineral_type_id` (`mineral_type_id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `sale_date` (`sale_date`);

--
-- Indexes for table `sale_details`
--
ALTER TABLE `sale_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `mineral_id` (`mineral_id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `sale_date` (`sale_date`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`);

--
-- Indexes for table `supplier_loans`
--
ALTER TABLE `supplier_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_code` (`transaction_code`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `mineral_type_id` (`mineral_type_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_transactions`
--
ALTER TABLE `account_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `buyers`
--
ALTER TABLE `buyers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `buyer_loans`
--
ALTER TABLE `buyer_loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_accounts`
--
ALTER TABLE `company_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mineral_price_settings`
--
ALTER TABLE `mineral_price_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mineral_types`
--
ALTER TABLE `mineral_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `purchase_details`
--
ALTER TABLE `purchase_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_details`
--
ALTER TABLE `sale_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `supplier_loans`
--
ALTER TABLE `supplier_loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `batches`
--
ALTER TABLE `batches`
  ADD CONSTRAINT `batches_ibfk_1` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`),
  ADD CONSTRAINT `batches_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `batches_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`);

--
-- Constraints for table `mineral_price_settings`
--
ALTER TABLE `mineral_price_settings`
  ADD CONSTRAINT `mineral_price_settings_ibfk_1` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_loans`
--
ALTER TABLE `supplier_loans`
  ADD CONSTRAINT `supplier_loans_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `supplier_loans_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  ADD CONSTRAINT `supplier_loans_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`mineral_type_id`) REFERENCES `mineral_types` (`id`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
