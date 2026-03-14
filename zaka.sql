-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Mar 14, 2026 at 04:24 PM
-- Server version: 10.5.29-MariaDB
-- PHP Version: 8.4.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `zaka`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(100) NOT NULL DEFAULT 'مدير النظام',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password_hash`, `display_name`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$MJubXwY5mfsTY6TActfp0efh5tmKWRMPDaxwvfUV.LWOFX4wkYDUi', 'مدير النظام', 1, NULL, '2026-03-14 09:39:23', '2026-03-14 12:02:26');

-- --------------------------------------------------------

--
-- Table structure for table `attachments`
--

CREATE TABLE `attachments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` bigint(20) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `storage_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `beneficiaries`
--

CREATE TABLE `beneficiaries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `beneficiary_type_id` int(10) UNSIGNED NOT NULL,
  `file_number` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `monthly_cash` decimal(10,2) DEFAULT NULL,
  `default_item` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `beneficiaries`
--

INSERT INTO `beneficiaries` (`id`, `beneficiary_type_id`, `file_number`, `full_name`, `id_number`, `phone`, `monthly_cash`, `default_item`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(32, 3, 25, 'أية نائل عبد العزيز طعم الله', '2000350578', '785972095', 40.00, 'كفالة 1', 'active', NULL, '2026-03-14 14:50:07', '2026-03-14 14:50:07'),
(33, 3, 63, 'نهى رياض محمد السلطي', '9742001185', '790057659', 20.00, 'كفالة 1', 'active', NULL, '2026-03-14 14:50:07', '2026-03-14 14:50:07'),
(34, 4, 5, 'ايات فتحي سالم ابو جويفل', '400814', '796379590', 20.00, 'راتب 2', 'active', NULL, '2026-03-14 14:51:07', '2026-03-14 14:51:07'),
(35, 4, 9, 'عائشة احمد عمر المنايعة', '5000005217', '785813090', 20.00, 'راتب 2', 'active', NULL, '2026-03-14 14:51:07', '2026-03-14 14:51:07');

-- --------------------------------------------------------

--
-- Table structure for table `beneficiary_types`
--

CREATE TABLE `beneficiary_types` (
  `id` int(10) UNSIGNED NOT NULL,
  `name_ar` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `beneficiary_types`
--

INSERT INTO `beneficiary_types` (`id`, `name_ar`, `slug`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'الأسر الفقيرة', 'poor_families', 1, 10, '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(2, 'الأيتام', 'orphans', 1, 20, '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(3, 'الكفالات', 'sponsorships', 1, 30, '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(4, 'رواتب الأسر', 'family_salaries', 1, 40, '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(5, 'مستفيدون عامّون', 'external_beneficiaries', 1, 50, '2026-03-14 09:39:23', '2026-03-14 09:39:23');

-- --------------------------------------------------------

--
-- Table structure for table `distributions`
--

CREATE TABLE `distributions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `beneficiary_type_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `distribution_date` date NOT NULL,
  `distribution_kind` enum('cash','in_kind','mixed') NOT NULL DEFAULT 'cash',
  `category` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distribution_items`
--

CREATE TABLE `distribution_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `distribution_id` bigint(20) UNSIGNED NOT NULL,
  `beneficiary_id` bigint(20) UNSIGNED NOT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `details_text` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES
(1, 'org_name', 'لجنة الزكاة والصدقات', '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(2, 'org_phone', '', '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(3, 'org_email', '', '2026-03-14 09:39:23', '2026-03-14 09:39:23'),
(4, 'currency_symbol', 'ر.ع', '2026-03-14 09:39:23', '2026-03-14 09:39:23');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admins_username` (`username`);

--
-- Indexes for table `attachments`
--
ALTER TABLE `attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attachments_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_beneficiaries_type_id_number` (`beneficiary_type_id`,`id_number`),
  ADD KEY `idx_beneficiaries_type_file` (`beneficiary_type_id`,`file_number`),
  ADD KEY `idx_beneficiaries_name` (`full_name`),
  ADD KEY `idx_beneficiaries_id_number` (`id_number`),
  ADD KEY `idx_beneficiaries_phone` (`phone`);

--
-- Indexes for table `beneficiary_types`
--
ALTER TABLE `beneficiary_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_beneficiary_types_slug` (`slug`);

--
-- Indexes for table `distributions`
--
ALTER TABLE `distributions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_distributions_date` (`distribution_date`),
  ADD KEY `idx_distributions_type_date` (`beneficiary_type_id`,`distribution_date`),
  ADD KEY `fk_distributions_admin` (`created_by_admin_id`);

--
-- Indexes for table `distribution_items`
--
ALTER TABLE `distribution_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_distribution_beneficiary` (`distribution_id`,`beneficiary_id`),
  ADD KEY `idx_items_distribution` (`distribution_id`),
  ADD KEY `idx_items_beneficiary` (`beneficiary_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_settings_key` (`setting_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attachments`
--
ALTER TABLE `attachments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `beneficiary_types`
--
ALTER TABLE `beneficiary_types`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `distributions`
--
ALTER TABLE `distributions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `distribution_items`
--
ALTER TABLE `distribution_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `beneficiaries`
--
ALTER TABLE `beneficiaries`
  ADD CONSTRAINT `fk_beneficiaries_type` FOREIGN KEY (`beneficiary_type_id`) REFERENCES `beneficiary_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `distributions`
--
ALTER TABLE `distributions`
  ADD CONSTRAINT `fk_distributions_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_distributions_type` FOREIGN KEY (`beneficiary_type_id`) REFERENCES `beneficiary_types` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `distribution_items`
--
ALTER TABLE `distribution_items`
  ADD CONSTRAINT `fk_items_beneficiary` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_items_distribution` FOREIGN KEY (`distribution_id`) REFERENCES `distributions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
