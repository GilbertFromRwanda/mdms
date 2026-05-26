-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 26, 2026 at 09:24 PM
-- Server version: 10.4.28-MariaDB-log
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

--
-- Dumping data for table `account_transactions`
--

INSERT INTO `account_transactions` (`id`, `account_id`, `txn_type`, `amount`, `balance_after`, `reference_type`, `reference_id`, `description`, `created_by`, `created_at`) VALUES
(1, 1, 'credit', 30000000.00, 30000000.00, 'manual', NULL, 'Opening balance', 1, '2026-05-23 16:46:02'),
(2, 2, 'credit', 2000.00, 2000.00, 'manual', NULL, 'Opening balance', 1, '2026-05-23 16:50:15'),
(3, 3, 'credit', 5000000.00, 5000000.00, 'manual', NULL, 'Opening balance', 1, '2026-05-23 16:50:44'),
(4, 1, 'credit', 1000000.00, 31000000.00, 'manual', NULL, 'boss', 1, '2026-05-23 16:52:31'),
(5, 1, 'debit', 1424390.00, 29575610.00, 'purchase', 1, 'Supplier payment', 1, '2026-05-23 16:54:55'),
(6, 1, 'debit', 272851.00, 29302759.00, 'purchase', 2, 'Supplier payment', 1, '2026-05-23 17:04:57'),
(7, 1, 'debit', 2279027.00, 27023732.00, 'purchase', 3, 'Supplier payment', 1, '2026-05-23 17:11:08'),
(8, 1, 'debit', 945944.00, 26077788.00, 'purchase', 4, 'Supplier payment', 1, '2026-05-23 17:15:13'),
(9, 1, 'debit', 242927.00, 25834861.00, 'purchase', 5, 'Supplier payment', 1, '2026-05-23 17:17:37'),
(10, 1, 'debit', 3000000.00, 22834861.00, 'purchase', 6, 'Supplier payment', 1, '2026-05-23 17:20:21'),
(11, 3, 'debit', 1000000.00, 4000000.00, 'supplier_loan', NULL, 'Advance to supplier#6', 1, '2026-05-23 17:23:05'),
(12, 3, 'debit', 2000000.00, 2000000.00, 'supplier_loan', NULL, 'Advance to supplier#4', 1, '2026-05-23 17:24:28'),
(13, 1, 'debit', 1529819.00, 21305042.00, 'purchase', 7, 'Supplier payment', 1, '2026-05-23 17:27:15'),
(14, 1, 'debit', 2814206.00, 18490836.00, 'purchase', 8, 'Supplier payment', 1, '2026-05-23 17:30:20'),
(15, 1, 'debit', 2000.00, 18488836.00, 'expense', NULL, 'Water bill', 1, '2026-05-23 17:58:52'),
(16, 1, 'debit', 5000.00, 18483836.00, 'expense', NULL, 'Airtime top-up', 1, '2026-05-23 17:59:30'),
(17, 1, 'credit', 3000000.00, 21483836.00, 'manual', NULL, 'bosss', 1, '2026-05-23 18:02:20'),
(18, 1, 'credit', 2800000.00, 24283836.00, 'sale', 1, 'Payment from buyer#1', 1, '2026-05-23 18:16:58'),
(19, 1, 'debit', 3000000.00, 21283836.00, 'purchase', 10, 'Supplier payment', 1, '2026-05-24 10:50:09'),
(20, 1, 'debit', 1113650.00, 20170186.00, 'purchase', 11, 'Supplier payment', 1, '2026-05-24 15:30:35'),
(21, 1, 'debit', 272850.00, 19897336.00, 'purchase', 12, 'Supplier payment', 1, '2026-05-24 15:38:08'),
(22, 1, 'credit', 20000000.00, 39897336.00, 'sale', 2, 'Payment from buyer#1', 1, '2026-05-24 15:41:05'),
(23, 1, 'credit', 600000.00, 40497336.00, 'sale', 3, 'Payment from buyer#2', 1, '2026-05-24 15:53:52'),
(24, 1, 'credit', 224000.00, 40721336.00, 'sale', 4, 'Payment from buyer#2', 1, '2026-05-24 15:57:22'),
(25, 1, 'debit', 1.00, 40721335.00, 'purchase', 13, 'Supplier payment', 1, '2026-05-25 18:07:37'),
(26, 1, 'debit', 1.00, 40721334.00, 'purchase', 15, 'Supplier payment', 1, '2026-05-25 18:10:19'),
(27, 1, 'debit', 1891891.00, 38829443.00, 'purchase', 16, 'Supplier payment', 1, '2026-05-25 18:11:26'),
(28, 1, 'debit', 1000000.00, 37829443.00, 'supply_stock', 3, 'Advance to supplier (supply stock)', 1, '2026-05-26 18:20:41'),
(29, 1, 'debit', 2003317.00, 35826126.00, 'purchase', 17, 'Supplier payment', 1, '2026-05-26 18:24:03'),
(30, 1, 'debit', 2290999.00, 33535127.00, 'purchase', 19, 'Supplier payment', 1, '2026-05-26 18:27:11'),
(31, 1, 'debit', 560996.00, 32974131.00, 'purchase', 21, 'Supplier payment', 1, '2026-05-26 18:52:03'),
(32, 1, 'debit', 15000000.00, 17974131.00, 'purchase', 22, 'Supplier payment', 1, '2026-05-26 18:57:13');

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

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'CREATE', 'sales', 3, 'Added sale: SALE-20260524-9514', '127.0.0.1', '2026-05-24 15:53:52'),
(2, 1, 'CREATE', 'sales', 4, 'Added sale: SALE-20260524-7075', '127.0.0.1', '2026-05-24 15:57:22'),
(3, 1, 'CREATE', 'batches', 13, 'Added batch: BATCH-20260525-1865', '127.0.0.1', '2026-05-25 18:07:37'),
(4, 1, 'CREATE', 'batches', 14, 'Added batch: BATCH-20260525-8097', '127.0.0.1', '2026-05-25 18:07:37'),
(5, 1, 'CREATE', 'batches', 15, 'Added batch: BATCH-20260525-2810', '127.0.0.1', '2026-05-25 18:10:19'),
(6, 1, 'CREATE', 'batches', 16, 'Added batch: BATCH-20260525-4661', '127.0.0.1', '2026-05-25 18:11:26'),
(7, 1, 'CREATE', 'supply_stock', 3, 'Added supply stock entry', '127.0.0.1', '2026-05-26 18:20:41'),
(8, 1, 'CREATE', 'batches', 17, 'Added batch: BATCH-20260526-3535', '127.0.0.1', '2026-05-26 18:24:03'),
(9, 1, 'CREATE', 'batches', 18, 'Added batch: BATCH-20260526-7001', '127.0.0.1', '2026-05-26 18:24:03'),
(10, 1, 'CREATE', 'batches', 19, 'Added batch: BATCH-20260526-3634', '127.0.0.1', '2026-05-26 18:27:11'),
(11, 1, 'CREATE', 'batches', 20, 'Added batch: BATCH-20260526-6107', '127.0.0.1', '2026-05-26 18:27:11'),
(12, 1, 'CREATE', 'suppliers', 9, 'Added supplier: gaju', '127.0.0.1', '2026-05-26 18:50:50'),
(13, 1, 'CREATE', 'batches', 21, 'Added batch: BATCH-20260526-7705', '127.0.0.1', '2026-05-26 18:52:03'),
(14, 1, 'CREATE', 'buyers', 3, 'Added buyer: manzi', '127.0.0.1', '2026-05-26 18:53:37'),
(15, 1, 'CREATE', 'batches', 22, 'Added batch: BATCH-20260526-3681', '127.0.0.1', '2026-05-26 18:57:13');

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

--
-- Dumping data for table `batches`
--

INSERT INTO `batches` (`id`, `batch_id`, `mineral_type_id`, `supplier_id`, `quantity`, `quality_grade`, `origin_location`, `received_date`, `expiry_date`, `certificate_number`, `notes`, `created_by`, `created_at`) VALUES
(1, 'BATCH-20260523-8784', 2, 1, 5.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 16:54:55'),
(2, 'BATCH-20260523-5805', 3, 2, 10.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:04:57'),
(3, 'BATCH-20260523-8364', 2, 4, 8.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:11:08'),
(4, 'BATCH-20260523-4235', 2, 5, 5.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:15:13'),
(5, 'BATCH-20260523-3132', 4, 6, 11.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:17:37'),
(6, 'BATCH-20260523-6114', 2, 6, 10.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:20:21'),
(7, 'BATCH-20260523-4155', 2, 6, 10.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:27:15'),
(8, 'BATCH-20260523-8873', 2, 6, 15.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:30:20'),
(9, 'BATCH-20260523-6077', 2, 4, 5.000, 'Standard', '', '2026-05-23', NULL, '', '', 1, '2026-05-23 17:36:14'),
(10, 'BATCH-20260524-3531', 2, 3, 10.000, 'Standard', '', '2026-05-24', NULL, '', '', 1, '2026-05-24 10:50:09'),
(11, 'BATCH-20260524-6058', 2, 3, 5.000, 'Standard', '', '2026-05-24', NULL, '', '', 1, '2026-05-24 15:30:35'),
(12, 'BATCH-20260524-4508', 3, 5, 10.000, 'Standard', '', '2026-05-24', NULL, '', '', 1, '2026-05-24 15:38:08'),
(13, 'BATCH-20260525-1865', 2, 3, 4.000, 'Standard', '', '2026-05-25', NULL, '', '', 1, '2026-05-25 18:07:37'),
(14, 'BATCH-20260525-8097', 4, 3, 10.000, 'Standard', '', '2026-05-25', NULL, '', '', 1, '2026-05-25 18:07:37'),
(15, 'BATCH-20260525-2810', 2, 5, 10.000, 'Standard', '', '2026-05-25', NULL, '', '', 1, '2026-05-25 18:10:19'),
(16, 'BATCH-20260525-4661', 2, 5, 2.000, 'Standard', '', '2026-05-25', NULL, '', '', 1, '2026-05-25 18:11:26'),
(17, 'BATCH-20260526-3535', 3, 2, 15.000, 'Standard', '', '2026-05-26', NULL, '', '', 1, '2026-05-26 18:24:03'),
(18, 'BATCH-20260526-7001', 2, 2, 23.000, 'Standard', '', '2026-05-26', NULL, '', '', 1, '2026-05-26 18:24:03'),
(19, 'BATCH-20260526-3634', 2, 5, 5.000, 'Standard', '', '2026-05-26', NULL, '', '', 1, '2026-05-26 18:27:11'),
(20, 'BATCH-20260526-6107', 4, 5, 5.000, 'Standard', '', '2026-05-26', NULL, '', '', 1, '2026-05-26 18:27:11'),
(21, 'BATCH-20260526-7705', 2, 9, 6.000, 'Standard', '', '2026-05-26', NULL, '', '', 1, '2026-05-26 18:52:03'),
(22, 'BATCH-20260526-3681', 2, 4, 45.000, 'Standard', '', '2026-05-26', NULL, '', '', 1, '2026-05-26 18:57:13');

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
(1, 'BUY-20260523-854', 'mugurusu', '', '0783547548', '', '', '2026-05-23 18:13:13'),
(2, 'BUY-20260524-171', 'Kalisa john muhozi', '', '0789047264', '', '', '2026-05-24 10:59:01'),
(3, 'BUY-20260526-809', 'manzi', '', '0773563428', '', '', '2026-05-26 18:53:37');

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

--
-- Dumping data for table `buyer_loans`
--

INSERT INTO `buyer_loans` (`id`, `buyer_id`, `sale_id`, `type`, `amount`, `notes`, `created_by`, `created_at`, `payment_method`, `account_id`, `is_advance`) VALUES
(1, 1, 2, 'repayment', 2360000.00, 'Advance consumed by sale', 1, '2026-05-24 15:41:05', NULL, NULL, 0);

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
(1, 'cash', 'cash rwf', 17974131.00, '', 1, 1, '2026-05-23 16:46:02', '2026-05-26 18:57:13'),
(2, 'cash', 'cash usd', 2000.00, '', 1, 1, '2026-05-23 16:50:15', '2026-05-23 16:50:15'),
(3, 'momo', 'momo', 2000000.00, '', 1, 1, '2026-05-23 16:50:44', '2026-05-23 17:24:28');

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

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `expense_date`, `category`, `description`, `amount`, `payment_method`, `account_id`, `notes`, `created_by`, `created_at`) VALUES
(1, '2026-05-23', 'Water', 'Water bill', 2000.00, 'cash', 1, '', 1, '2026-05-23 17:58:52'),
(2, '2026-05-23', 'Airtime', 'Airtime top-up', 5000.00, 'cash', 1, '', 1, '2026-05-23 17:59:30');

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

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `mineral_type_id`, `current_stock`, `last_updated`) VALUES
(1, 2, 95.000, '2026-05-26 18:57:13'),
(2, 3, 15.000, '2026-05-26 18:24:03'),
(5, 4, 18.000, '2026-05-26 18:27:11');

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

--
-- Dumping data for table `purchase_details`
--

INSERT INTO `purchase_details` (`id`, `batch_id`, `mineral_id`, `supplier_id`, `purchase_date`, `currency_used`, `qty`, `sample`, `rwf_rate`, `fees_1`, `fees_2`, `tag`, `rma`, `rra`, `lma`, `tmt`, `tantal`, `unit_price`, `take_home`, `loan_action`, `loan_amount`, `amount_paid`, `comment`, `created_by`, `created_at`) VALUES
(1, 1, 2, 1, '2026-05-23', 'FRW', 5.000, 45.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 9796.545000, NULL, NULL, 4.5000, 284878.455000, 1424392.28, 'none', 0.00, 1424390.00, '', 1, '2026-05-23 16:54:55'),
(2, 2, 3, 2, '2026-05-23', 'FRW', 10.000, 50.000000, 1466.00, 2500.00, 3000.00, 2000.00, 70.00, 1064.316000, 50000.0000, NULL, NULL, 27285.184000, 272851.84, 'none', 0.00, 272851.00, '', 1, '2026-05-23 17:04:57'),
(3, 3, 2, 4, '2026-05-23', 'FRW', 8.000, 45.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 9796.545000, NULL, NULL, 4.5000, 284878.455000, 2279027.64, 'none', 0.00, 2279027.00, '', 1, '2026-05-23 17:11:08'),
(4, 4, 2, 5, '2026-05-23', 'FRW', 5.000, 30.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 6531.030000, NULL, NULL, 4.5000, 189188.970000, 945944.85, 'none', 0.00, 945944.00, '', 1, '2026-05-23 17:15:13'),
(5, 5, 4, 6, '2026-05-23', 'FRW', 11.000, 17.000000, 1466.00, NULL, NULL, 2000.00, 90.00, 747.660000, NULL, 1000.0000, NULL, 22084.340000, 242927.74, 'none', 0.00, 242927.00, '', 1, '2026-05-23 17:17:37'),
(6, 6, 2, 6, '2026-05-23', 'FRW', 10.000, 35.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 7619.535000, NULL, NULL, 4.5000, 221085.465000, 2210854.65, 'none', 0.00, 3000000.00, '', 1, '2026-05-23 17:20:21'),
(7, 7, 2, 6, '2026-05-23', 'FRW', 10.000, 40.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 8708.040000, NULL, NULL, 4.5000, 252981.960000, 2529819.60, 'deduct', 1000000.00, 1529819.00, '', 1, '2026-05-23 17:27:15'),
(8, 8, 2, 6, '2026-05-23', 'FRW', 15.000, 38.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 8272.638000, NULL, NULL, 4.5000, 240223.362000, 3603350.43, 'deduct', 789144.00, 2814206.00, '', 1, '2026-05-23 17:30:20'),
(9, 9, 2, 4, '2026-05-23', 'FRW', 5.000, 40.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 8708.040000, NULL, NULL, 4.5000, 252981.960000, 1264909.80, 'give', 1000000.00, 0.00, '', 1, '2026-05-23 17:36:14'),
(10, 10, 2, 3, '2026-05-24', 'FRW', 10.000, 45.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 9796.545000, NULL, NULL, 4.5000, 284878.455000, 2848784.55, 'none', 0.00, 3000000.00, '', 1, '2026-05-24 10:50:09'),
(11, 11, 2, 3, '2026-05-24', 'FRW', 5.000, 40.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 8708.040000, NULL, NULL, 4.5000, 252981.960000, 1264909.80, 'deduct', 151215.45, 1113650.00, '', 1, '2026-05-24 15:30:35'),
(12, 12, 3, 5, '2026-05-24', 'FRW', 10.000, 50.000000, 1466.00, 2500.00, 3000.00, 2000.00, 70.00, 1064.316000, 50000.0000, NULL, NULL, 27285.184000, 272851.84, 'none', 0.00, 272850.00, '', 1, '2026-05-24 15:38:08'),
(13, 13, 2, 3, '2026-05-25', 'FRW', 4.000, 50.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 10885.050000, NULL, NULL, 4.5000, 316774.950000, 1267099.80, 'none', 0.00, 1.00, '', 1, '2026-05-25 18:07:37'),
(14, 14, 4, 3, '2026-05-25', 'FRW', 10.000, 30.000000, 1466.00, NULL, NULL, 2000.00, 90.00, 1319.400000, NULL, 1000.0000, NULL, 40570.600000, 405706.00, 'none', 0.00, 1.00, '', 1, '2026-05-25 18:07:37'),
(15, 15, 2, 5, '2026-05-25', 'FRW', 10.000, 30.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 6531.030000, NULL, NULL, 4.5000, 189188.970000, 1891889.70, 'none', 0.00, 1.00, '', 1, '2026-05-25 18:10:19'),
(16, 16, 2, 5, '2026-05-25', 'FRW', 2.000, 60.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 13062.060000, NULL, NULL, 4.5000, 380567.940000, 761135.88, 'none', 0.00, 1891891.00, '', 1, '2026-05-25 18:11:26'),
(17, 17, 3, 2, '2026-05-26', 'FRW', 15.000, 24.000000, 1466.00, 2500.00, 3000.00, 2000.00, 70.00, 492.576000, 50000.0000, NULL, NULL, 9751.824000, 146277.36, 'none', 0.00, 2003317.00, '', 1, '2026-05-26 18:24:03'),
(18, 18, 2, 2, '2026-05-26', 'FRW', 23.000, 13.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 2830.113000, NULL, NULL, 4.5000, 80740.887000, 1857040.40, 'none', 0.00, 2003317.00, '', 1, '2026-05-26 18:24:03'),
(19, 19, 2, 5, '2026-05-26', 'FRW', 5.000, 30.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 6531.030000, NULL, NULL, 4.5000, 189188.970000, 945944.85, 'give', 1000000.00, 2290999.00, '', 1, '2026-05-26 18:27:11'),
(20, 20, 4, 5, '2026-05-26', 'FRW', 5.000, 50.000000, 1466.00, NULL, NULL, 2000.00, 90.00, 2199.000000, NULL, 1000.0000, NULL, 69011.000000, 345055.00, 'give', 1000000.00, 2290999.00, '', 1, '2026-05-26 18:27:11'),
(21, 21, 2, 9, '2026-05-26', 'FRW', 6.000, 15.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 3265.515000, NULL, NULL, 4.5000, 93499.485000, 560996.91, 'none', 0.00, 560996.00, '', 1, '2026-05-26 18:52:03'),
(22, 22, 2, 4, '2026-05-26', 'FRW', 45.000, 67.000000, 1466.00, NULL, NULL, 2000.00, 190.00, 14585.967000, NULL, NULL, 4.5000, 425223.033000, 19135036.49, 'deduct', 2000000.00, 15000000.00, '', 1, '2026-05-26 18:57:13');

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

--
-- Dumping data for table `purchase_payments`
--

INSERT INTO `purchase_payments` (`id`, `batch_id`, `supplier_id`, `payment_method`, `account_id`, `amount`, `created_by`, `created_at`) VALUES
(1, 1, 1, 'cash', 1, 1424390.00, 1, '2026-05-23 16:54:55'),
(2, 2, 2, 'cash', 1, 272851.00, 1, '2026-05-23 17:04:57'),
(3, 3, 4, 'cash', 1, 2279027.00, 1, '2026-05-23 17:11:08'),
(4, 4, 5, 'cash', 1, 945944.00, 1, '2026-05-23 17:15:13'),
(5, 5, 6, 'cash', 1, 242927.00, 1, '2026-05-23 17:17:37'),
(6, 6, 6, 'cash', 1, 3000000.00, 1, '2026-05-23 17:20:21'),
(7, 7, 6, 'cash', 1, 1529819.00, 1, '2026-05-23 17:27:15'),
(8, 8, 6, 'cash', 1, 2814206.00, 1, '2026-05-23 17:30:20'),
(9, 10, 3, 'cash', 1, 3000000.00, 1, '2026-05-24 10:50:09'),
(10, 11, 3, 'cash', 1, 1113650.00, 1, '2026-05-24 15:30:35'),
(11, 12, 5, 'cash', 1, 272850.00, 1, '2026-05-24 15:38:08'),
(12, 13, 3, 'cash', 1, 1.00, 1, '2026-05-25 18:07:37'),
(13, 15, 5, 'cash', 1, 1.00, 1, '2026-05-25 18:10:19'),
(14, 16, 5, 'cash', 1, 1891891.00, 1, '2026-05-25 18:11:26'),
(15, 17, 2, 'cash', 1, 2003317.00, 1, '2026-05-26 18:24:03'),
(16, 19, 5, 'cash', 1, 2290999.00, 1, '2026-05-26 18:27:11'),
(17, 21, 9, 'cash', 1, 560996.00, 1, '2026-05-26 18:52:03'),
(18, 22, 4, 'cash', 1, 15000000.00, 1, '2026-05-26 18:57:13');

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

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `sale_id`, `mineral_type_id`, `buyer_id`, `quantity`, `sale_date`, `notes`, `created_by`, `created_at`) VALUES
(1, 'SALE-20260523-2877', 2, 1, 10.000, '2026-05-23', '', 1, '2026-05-23 18:16:58'),
(2, 'SALE-20260524-6322', 2, 1, 63.000, '2026-05-24', '', 1, '2026-05-24 15:41:05'),
(3, 'SALE-20260524-9514', 3, 2, 20.000, '2026-05-24', '', 1, '2026-05-24 15:53:52'),
(4, 'SALE-20260524-7075', 4, 2, 8.000, '2026-05-24', '', 1, '2026-05-24 15:57:22');

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

--
-- Dumping data for table `sale_details`
--

INSERT INTO `sale_details` (`id`, `sale_id`, `mineral_id`, `buyer_id`, `sale_date`, `currency_used`, `qty`, `selling_price`, `cost_price`, `total_revenue`, `total_cost`, `benefit`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 2, 1, '2026-05-23', 'FRW', 10.000, 280000.0000, 252981.9600, 2800000.00, 2529819.60, 270180.40, '', 1, '2026-05-23 18:16:58'),
(2, 2, 2, 1, '2026-05-24', 'FRW', 63.000, 280000.0000, 252981.9600, 17640000.00, 15937863.48, 1702136.52, '', 1, '2026-05-24 15:41:05'),
(3, 3, 3, 2, '2026-05-24', 'FRW', 20.000, 30000.0000, 27285.1840, 600000.00, 545703.68, 54296.32, '', 1, '2026-05-24 15:53:52'),
(4, 4, 4, 2, '2026-05-24', 'FRW', 8.000, 28000.0000, 22084.3400, 224000.00, 176674.72, 47325.28, '', 1, '2026-05-24 15:57:22');

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
(1, 'SUP-20260523-979', 'jilbert', '', '0789179400', '', '', '', '2026-05-23 16:33:53'),
(2, 'SUP-20260523-501', 'kwizera', '', '0725047173', '', '', '', '2026-05-23 16:57:52'),
(3, 'SUP-20260523-307', 'amina', '', '0789047170', '', '', '', '2026-05-23 17:07:17'),
(4, 'SUP-20260523-946', 'alex', '', '079989776', '', '', '', '2026-05-23 17:09:56'),
(5, 'SUP-20260523-376', 'amire', '', '07311301', '', '', '', '2026-05-23 17:12:36'),
(6, 'SUP-20260523-532', 'mutabaruka', '', '07878572', '', '', '', '2026-05-23 17:16:21'),
(8, 'SUP-20260524-600', 'mucyo gaston', 'Niyonsaba Gilbert', '+250789047190', '', '', NULL, '2026-05-24 10:54:41'),
(9, 'SUP-20260526-507', 'gaju', '', '078364787', '', '', '', '2026-05-26 18:50:50');

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

--
-- Dumping data for table `supplier_loans`
--

INSERT INTO `supplier_loans` (`id`, `supplier_id`, `batch_id`, `type`, `amount`, `notes`, `created_by`, `created_at`, `is_deferred`, `payment_method`, `account_id`) VALUES
(1, 1, 1, 'loan', 2.28, '', 1, '2026-05-23 16:54:55', 1, NULL, NULL),
(2, 2, 2, 'loan', 0.84, '', 1, '2026-05-23 17:04:57', 1, NULL, NULL),
(3, 4, 3, 'loan', 0.64, '', 1, '2026-05-23 17:11:08', 1, NULL, NULL),
(4, 5, 4, 'loan', 0.85, '', 1, '2026-05-23 17:15:13', 1, NULL, NULL),
(5, 6, 5, 'loan', 0.74, '', 1, '2026-05-23 17:17:37', 1, NULL, NULL),
(6, 6, 6, 'repayment', 0.74, 'Offset from overpayment', 1, '2026-05-23 17:20:21', 1, NULL, NULL),
(7, 6, 6, 'loan', 789144.61, 'Advance from overpayment', 1, '2026-05-23 17:20:21', 0, NULL, NULL),
(8, 6, NULL, 'loan', 1000000.00, '', 1, '2026-05-23 17:23:05', 0, 'momo', 3),
(9, 4, NULL, 'loan', 2000000.00, '', 1, '2026-05-23 17:24:28', 0, 'momo', 3),
(10, 6, 7, 'repayment', 1000000.00, '', 1, '2026-05-23 17:27:15', 0, NULL, NULL),
(11, 6, 7, 'loan', 0.60, '', 1, '2026-05-23 17:27:15', 1, NULL, NULL),
(12, 6, 8, 'repayment', 789144.00, '', 1, '2026-05-23 17:30:20', 0, NULL, NULL),
(13, 6, 8, 'loan', 0.43, '', 1, '2026-05-23 17:30:20', 1, NULL, NULL),
(14, 4, 9, 'loan', 1000000.00, '', 1, '2026-05-23 17:36:14', 0, NULL, NULL),
(15, 4, 9, 'loan', 2264909.80, '', 1, '2026-05-23 17:36:14', 1, NULL, NULL),
(16, 3, 10, 'loan', 151215.45, 'Advance from overpayment', 1, '2026-05-24 10:50:09', 0, NULL, NULL),
(17, 3, 11, 'repayment', 151215.45, '', 1, '2026-05-24 15:30:35', 0, NULL, NULL),
(18, 3, 11, 'loan', 44.35, '', 1, '2026-05-24 15:30:35', 1, NULL, NULL),
(19, 5, 12, 'loan', 1.84, '', 1, '2026-05-24 15:38:08', 1, NULL, NULL),
(20, 3, 13, 'loan', 1672804.80, '', 1, '2026-05-25 18:07:37', 1, NULL, NULL),
(21, 5, 15, 'loan', 1891888.70, '', 1, '2026-05-25 18:10:19', 1, NULL, NULL),
(22, 5, 16, 'repayment', 1130755.12, 'Offset from overpayment', 1, '2026-05-25 18:11:26', 1, NULL, NULL),
(23, 5, NULL, 'loan', 1000000.00, 'Advance via supply stock entry', 1, '2026-05-26 18:20:41', 0, NULL, NULL),
(24, 2, 17, 'loan', 0.76, '', 1, '2026-05-26 18:24:03', 1, NULL, NULL),
(25, 5, 19, 'loan', 1000000.00, '', 1, '2026-05-26 18:27:11', 0, NULL, NULL),
(26, 5, 19, 'loan', 0.85, '', 1, '2026-05-26 18:27:11', 1, NULL, NULL),
(27, 9, 21, 'loan', 0.91, '', 1, '2026-05-26 18:52:03', 1, NULL, NULL),
(28, 4, 22, 'repayment', 2000000.00, '', 1, '2026-05-26 18:57:13', 0, NULL, NULL),
(29, 4, 22, 'loan', 2135036.49, '', 1, '2026-05-26 18:57:13', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `supply_stock`
--

CREATE TABLE `supply_stock` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `mineral_id` int(11) NOT NULL,
  `qty` decimal(10,3) NOT NULL DEFAULT 0.000,
  `advance_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('in','out','sold') NOT NULL DEFAULT 'in',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supply_stock`
--

INSERT INTO `supply_stock` (`id`, `supplier_id`, `mineral_id`, `qty`, `advance_amount`, `status`, `notes`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 4.000, 0.00, 'in', '', 1, 1, '2026-05-24 09:11:32', '2026-05-24 15:19:29'),
(2, 8, 3, 15.000, 0.00, 'in', '', 1, NULL, '2026-05-24 15:13:31', '2026-05-24 15:13:31'),
(3, 5, 2, 12.000, 1000000.00, 'in', '', 1, NULL, '2026-05-26 18:20:41', '2026-05-26 18:20:41');

-- --------------------------------------------------------

--
-- Table structure for table `supply_stock_payments`
--

CREATE TABLE `supply_stock_payments` (
  `id` int(11) NOT NULL,
  `supply_stock_id` int(11) NOT NULL,
  `payment_method` enum('cash','bank','momo') NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supply_stock_payments`
--

INSERT INTO `supply_stock_payments` (`id`, `supply_stock_id`, `payment_method`, `account_id`, `amount`, `created_by`, `created_at`) VALUES
(1, 3, 'cash', 1, 1000000.00, 1, '2026-05-26 18:20:41');

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
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `transaction_code`, `transaction_type`, `batch_id`, `mineral_type_id`, `quantity`, `transaction_date`, `price_per_unit`, `total_amount`, `reference_number`, `recipient_company`, `driver_name`, `vehicle_number`, `notes`, `created_by`, `created_at`) VALUES
(1, 'TRX-20260523185455-99', 'IN', 1, 2, 5.000, '2026-05-23', 284878.46, 1424392.28, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 16:54:55'),
(2, 'TRX-20260523190457-22', 'IN', 2, 3, 10.000, '2026-05-23', 27285.18, 272851.84, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:04:57'),
(3, 'TRX-20260523191108-25', 'IN', 3, 2, 8.000, '2026-05-23', 284878.46, 2279027.64, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:11:08'),
(4, 'TRX-20260523191513-12', 'IN', 4, 2, 5.000, '2026-05-23', 189188.97, 945944.85, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:15:13'),
(5, 'TRX-20260523191737-42', 'IN', 5, 4, 11.000, '2026-05-23', 22084.34, 242927.74, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:17:37'),
(6, 'TRX-20260523192021-23', 'IN', 6, 2, 10.000, '2026-05-23', 221085.47, 2210854.65, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:20:21'),
(7, 'TRX-20260523192715-23', 'IN', 7, 2, 10.000, '2026-05-23', 252981.96, 2529819.60, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:27:15'),
(8, 'TRX-20260523193020-29', 'IN', 8, 2, 15.000, '2026-05-23', 240223.36, 3603350.43, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:30:20'),
(9, 'TRX-20260523193614-62', 'IN', 9, 2, 5.000, '2026-05-23', 252981.96, 1264909.80, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 17:36:14'),
(10, 'TRX-20260523201658-87', 'OUT', NULL, 2, 10.000, '2026-05-23', 280000.00, 2800000.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-23 18:16:58'),
(11, 'TRX-20260524125009-84', 'IN', 10, 2, 10.000, '2026-05-24', 284878.46, 2848784.55, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-24 10:50:09'),
(12, 'TRX-20260524173035-48', 'IN', 11, 2, 5.000, '2026-05-24', 252981.96, 1264909.80, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-24 15:30:35'),
(13, 'TRX-20260524173808-13', 'IN', 12, 3, 10.000, '2026-05-24', 27285.18, 272851.84, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-24 15:38:08'),
(14, 'TRX-20260524174105-71', 'OUT', NULL, 2, 63.000, '2026-05-24', 280000.00, 17640000.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-24 15:41:05'),
(15, 'TRX-20260524175352-67', 'OUT', NULL, 3, 20.000, '2026-05-24', 30000.00, 600000.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-24 15:53:52'),
(16, 'TRX-20260524175722-80', 'OUT', NULL, 4, 8.000, '2026-05-24', 28000.00, 224000.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-24 15:57:22'),
(17, 'TRX-20260525200737-99', 'IN', 13, 2, 4.000, '2026-05-25', 316774.95, 1267099.80, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-25 18:07:37'),
(18, 'TRX-20260525200737-88', 'IN', 14, 4, 10.000, '2026-05-25', 40570.60, 405706.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-25 18:07:37'),
(19, 'TRX-20260525201019-26', 'IN', 15, 2, 10.000, '2026-05-25', 189188.97, 1891889.70, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-25 18:10:19'),
(20, 'TRX-20260525201126-90', 'IN', 16, 2, 2.000, '2026-05-25', 380567.94, 761135.88, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-25 18:11:26'),
(21, 'TRX-20260526202403-35', 'IN', 17, 3, 15.000, '2026-05-26', 9751.82, 146277.36, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-26 18:24:03'),
(22, 'TRX-20260526202403-88', 'IN', 18, 2, 23.000, '2026-05-26', 80740.89, 1857040.40, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-26 18:24:03'),
(23, 'TRX-20260526202711-71', 'IN', 19, 2, 5.000, '2026-05-26', 189188.97, 945944.85, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-26 18:27:11'),
(24, 'TRX-20260526202711-92', 'IN', 20, 4, 5.000, '2026-05-26', 69011.00, 345055.00, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-26 18:27:11'),
(25, 'TRX-20260526205203-42', 'IN', 21, 2, 6.000, '2026-05-26', 93499.49, 560996.91, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-26 18:52:03'),
(26, 'TRX-20260526205713-43', 'IN', 22, 2, 45.000, '2026-05-26', 425223.03, 19135036.49, NULL, NULL, NULL, NULL, NULL, 1, '2026-05-26 18:57:13');

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
(1, 'admin', '$2y$10$yZbJIFYptQeUGb3XDjguJeqA646QcZASRNw5XDlCe/KUnPxO6a2Mm', 'Claudine', '', 'admin', '2026-04-24 10:51:37'),
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
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_received_date` (`received_date`);

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
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_sl_type` (`type`);

--
-- Indexes for table `supply_stock`
--
ALTER TABLE `supply_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `mineral_id` (`mineral_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `supply_stock_payments`
--
ALTER TABLE `supply_stock_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supply_stock_id` (`supply_stock_id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_code` (`transaction_code`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `mineral_type_id` (`mineral_type_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_tx_type_date` (`transaction_type`,`transaction_date`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `buyers`
--
ALTER TABLE `buyers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `buyer_loans`
--
ALTER TABLE `buyer_loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `company_accounts`
--
ALTER TABLE `company_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sale_details`
--
ALTER TABLE `sale_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `supplier_loans`
--
ALTER TABLE `supplier_loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `supply_stock`
--
ALTER TABLE `supply_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `supply_stock_payments`
--
ALTER TABLE `supply_stock_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

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
-- Constraints for table `supply_stock`
--
ALTER TABLE `supply_stock`
  ADD CONSTRAINT `supply_stock_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `supply_stock_ibfk_2` FOREIGN KEY (`mineral_id`) REFERENCES `mineral_types` (`id`),
  ADD CONSTRAINT `supply_stock_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `supply_stock_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `supply_stock_payments`
--
ALTER TABLE `supply_stock_payments`
  ADD CONSTRAINT `supply_stock_payments_ibfk_1` FOREIGN KEY (`supply_stock_id`) REFERENCES `supply_stock` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supply_stock_payments_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `company_accounts` (`id`),
  ADD CONSTRAINT `supply_stock_payments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

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
