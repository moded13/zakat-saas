-- ============================================================
-- Zakat SaaS - Database Schema
-- Single Organization (single-tenant)
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET collation_connection = 'utf8mb4_unicode_ci';

CREATE DATABASE IF NOT EXISTS `zakat_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `zakat_db`;

-- ============================================================
-- admins
-- ============================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name`     VARCHAR(100) DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- beneficiary_types  (5 fixed types, seeded below)
-- ============================================================
CREATE TABLE IF NOT EXISTS `beneficiary_types` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `code`       VARCHAR(50)  NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- beneficiaries  (unified table)
-- ============================================================
CREATE TABLE IF NOT EXISTS `beneficiaries` (
  `id`                   INT           NOT NULL AUTO_INCREMENT,
  `beneficiary_type_id`  INT           NOT NULL,
  `file_number`          INT           NOT NULL,
  `full_name`            VARCHAR(200)  NOT NULL,
  `id_number`            VARCHAR(50)   DEFAULT NULL,
  `phone`                VARCHAR(30)   DEFAULT NULL,
  `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `notes`                TEXT          DEFAULT NULL,
  `created_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_file`      (`beneficiary_type_id`, `file_number`),
  -- Allow NULL id_number (no duplicate id within same type when provided)
  UNIQUE KEY `uq_type_id_number` (`beneficiary_type_id`, `id_number`),
  KEY `idx_full_name`            (`full_name`(100)),
  KEY `idx_id_number`            (`id_number`),
  KEY `idx_phone`                (`phone`),
  CONSTRAINT `fk_ben_type` FOREIGN KEY (`beneficiary_type_id`)
    REFERENCES `beneficiary_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- distributions  (header)
-- ============================================================
CREATE TABLE IF NOT EXISTS `distributions` (
  `id`                   INT           NOT NULL AUTO_INCREMENT,
  `title`                VARCHAR(200)  NOT NULL,
  `distribution_date`    DATE          NOT NULL,
  `beneficiary_type_id`  INT           DEFAULT NULL,
  `notes`                TEXT          DEFAULT NULL,
  `created_by`           INT           DEFAULT NULL,
  `created_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dist_type` (`beneficiary_type_id`),
  KEY `idx_dist_date` (`distribution_date`),
  CONSTRAINT `fk_dist_type`    FOREIGN KEY (`beneficiary_type_id`) REFERENCES `beneficiary_types` (`id`),
  CONSTRAINT `fk_dist_creator` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- distribution_items  (per-beneficiary record)
-- ============================================================
CREATE TABLE IF NOT EXISTS `distribution_items` (
  `id`               INT            NOT NULL AUTO_INCREMENT,
  `distribution_id`  INT            NOT NULL,
  `beneficiary_id`   INT            NOT NULL,
  `cash_amount`      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `details_text`     TEXT           DEFAULT NULL,
  `notes`            TEXT           DEFAULT NULL,
  `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_dist` (`distribution_id`),
  KEY `idx_item_ben`  (`beneficiary_id`),
  CONSTRAINT `fk_item_dist` FOREIGN KEY (`distribution_id`)
    REFERENCES `distributions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_ben`  FOREIGN KEY (`beneficiary_id`)
    REFERENCES `beneficiaries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- attachments  (stub, for future use)
-- ============================================================
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `entity_type` VARCHAR(50)  DEFAULT NULL,
  `entity_id`   INT          DEFAULT NULL,
  `file_name`   VARCHAR(255) DEFAULT NULL,
  `file_path`   VARCHAR(500) DEFAULT NULL,
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- settings  (org-level key/value)
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEEDS
-- ============================================================

-- Beneficiary types (5 fixed Arabic labels)
INSERT INTO `beneficiary_types` (`id`, `name`, `code`) VALUES
  (1, 'الأسر الفقيرة',      'poor_families'),
  (2, 'الأيتام',            'orphans'),
  (3, 'الكفالات',           'sponsorships'),
  (4, 'رواتب الأسر',        'family_salaries'),
  (5, 'مستفيدون عامّون',    'general')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Default admin  (password: 123@123)
-- To generate a new hash run:
--   php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT, ['cost'=>12]);"
-- Then replace the hash below before going to production.
INSERT INTO `admins` (`username`, `password_hash`, `full_name`) VALUES
  ('admin', '$2y$12$wGCm6niUrIsFVojLFvvlGOnm0rKA2YG.LsJMPcmaZg0uGXqEeJSgW', 'مدير النظام')
ON DUPLICATE KEY UPDATE `username` = `username`;

-- Default org settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('org_name',         'لجنة الزكاة'),
  ('org_address',      ''),
  ('org_phone',        ''),
  ('org_email',        ''),
  ('currency_symbol',  'ريال')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;
