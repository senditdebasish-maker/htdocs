-- =============================================================
-- GP Procurement & Work Management Portal — MySQL schema
-- Target: XAMPP (MySQL 8 / MariaDB 10). Import into phpMyAdmin
-- or run: mysql -u root < install.sql
-- =============================================================

CREATE DATABASE IF NOT EXISTS `gp_portal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gp_portal`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `user_roles`;
DROP TABLE IF EXISTS `panchayats`;
DROP TABLE IF EXISTS `panchayat_members`;
DROP TABLE IF EXISTS `financial_years`;
DROP TABLE IF EXISTS `rulesets`;
DROP TABLE IF EXISTS `rules`;
DROP TABLE IF EXISTS `rule_references`;
DROP TABLE IF EXISTS `rule_evaluations`;
DROP TABLE IF EXISTS `schemes`;
DROP TABLE IF EXISTS `funds`;
DROP TABLE IF EXISTS `budget_allocations`;
DROP TABLE IF EXISTS `projects`;
DROP TABLE IF EXISTS `procurement_plans`;
DROP TABLE IF EXISTS `contractors`;
DROP TABLE IF EXISTS `contractor_documents`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `tender_approval_docs`;
DROP TABLE IF EXISTS `tenders`;
DROP TABLE IF EXISTS `tender_versions`;
DROP TABLE IF EXISTS `nit_versions`;
DROP TABLE IF EXISTS `boq_items`;
DROP TABLE IF EXISTS `boq_versions`;
DROP TABLE IF EXISTS `tender_bidders`;
DROP TABLE IF EXISTS `bid_documents`;
DROP TABLE IF EXISTS `technical_openings`;
DROP TABLE IF EXISTS `evaluation_criteria`;
DROP TABLE IF EXISTS `technical_evaluations`;
DROP TABLE IF EXISTS `financial_bids`;
DROP TABLE IF EXISTS `financial_bid_items`;
DROP TABLE IF EXISTS `awards`;
DROP TABLE IF EXISTS `agreements`;
DROP TABLE IF EXISTS `work_orders`;
DROP TABLE IF EXISTS `work_progress`;
DROP TABLE IF EXISTS `site_instructions`;
DROP TABLE IF EXISTS `extension_requests`;
DROP TABLE IF EXISTS `measurements`;
DROP TABLE IF EXISTS `measurement_items`;
DROP TABLE IF EXISTS `bills`;
DROP TABLE IF EXISTS `bill_items`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `completions`;
DROP TABLE IF EXISTS `contractor_performance`;
DROP TABLE IF EXISTS `corrigenda`;
DROP TABLE IF EXISTS `tender_cancellations`;
DROP TABLE IF EXISTS `retenders`;
DROP TABLE IF EXISTS `approval_steps`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `numbering_sequences`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `templates`;
DROP TABLE IF EXISTS `document_generations`;
DROP TABLE IF EXISTS `external_refs`;
DROP TABLE IF EXISTS `imports`;
DROP TABLE IF EXISTS `backups`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `roles` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `code` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(120) NOT NULL,
  `description` TEXT NOT NULL,
  `is_system` INTEGER NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `permissions` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(160) NOT NULL,
  `category` VARCHAR(40) NOT NULL
);

CREATE TABLE `role_permissions` (
  `role_id` INTEGER NOT NULL,
  `permission_id` INTEGER NOT NULL,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(id) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(id) ON DELETE CASCADE,
  PRIMARY KEY (`role_id`, `permission_id`)
);

CREATE TABLE `users` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `panchayat_id` INTEGER NULL,
  `email` VARCHAR(190) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `designation` VARCHAR(120) NULL,
  `phone` VARCHAR(40) NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `is_global_admin` INTEGER NOT NULL DEFAULT 0,
  `totp_secret` VARCHAR(128) NULL,
  `totp_enabled` INTEGER NOT NULL DEFAULT 0,
  `last_login_at` DATETIME NULL,
  `failed_login_count` INTEGER NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  FOREIGN KEY (`panchayat_id`) REFERENCES `panchayats`(id) ON DELETE RESTRICT
);

CREATE TABLE `user_roles` (
  `user_id` INTEGER NOT NULL,
  `role_id` INTEGER NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(id) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(id) ON DELETE CASCADE,
  PRIMARY KEY (`user_id`, `role_id`)
);

CREATE TABLE `panchayats` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `state` VARCHAR(60) NOT NULL,
  `district` VARCHAR(80) NULL,
  `block` VARCHAR(80) NULL,
  `gram_panchayat` VARCHAR(160) NOT NULL,
  `gp_code` VARCHAR(40) NULL UNIQUE,
  `office_address` TEXT NOT NULL,
  `pin` VARCHAR(10) NULL,
  `phone` VARCHAR(40) NULL,
  `email` VARCHAR(190) NULL,
  `pradhan` VARCHAR(120) NULL,
  `upa_pradhan` VARCHAR(120) NULL,
  `panchayat_secretary` VARCHAR(120) NULL,
  `technical_officer` VARCHAR(120) NULL,
  `accounts_officer` VARCHAR(120) NULL,
  `letterhead_html` TEXT NULL,
  `document_header_html` TEXT NULL,
  `document_footer_html` TEXT NULL,
  `signature_config` TEXT NULL,
  `seal_config` TEXT NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `panchayat_members` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `panchayat_id` INTEGER NOT NULL,
  `role_name` VARCHAR(80) NOT NULL,
  `person_name` VARCHAR(160) NOT NULL,
  `designation` VARCHAR(120) NULL,
  `phone` VARCHAR(40) NULL,
  `email` VARCHAR(190) NULL,
  `is_authority` INTEGER NOT NULL DEFAULT 0,
  `authority_level` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`panchayat_id`) REFERENCES `panchayats`(id) ON DELETE CASCADE
);

CREATE TABLE `financial_years` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `label` VARCHAR(9) NOT NULL UNIQUE,
  `start_year` INTEGER NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'open',
  `is_current` INTEGER NOT NULL DEFAULT 0,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('open','closed'))
);

CREATE TABLE `rulesets` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `issuing_authority` VARCHAR(200) NULL,
  `jurisdiction` VARCHAR(80) NULL,
  `effective_date` DATE NULL,
  `expiry_date` DATE NULL,
  `reference_number` VARCHAR(160) NULL,
  `source_url` VARCHAR(500) NULL,
  `source_document_id` INTEGER NULL,
  `version` INTEGER NOT NULL DEFAULT 1,
  `procurement_type` VARCHAR(40) NULL,
  `tender_type` VARCHAR(40) NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `notes` TEXT NOT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `rules` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `ruleset_id` INTEGER NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NOT NULL,
  `category` VARCHAR(40) NOT NULL,
  `severity` VARCHAR(64) NOT NULL DEFAULT 'info',
  `rule_type` VARCHAR(64) NOT NULL DEFAULT 'manual',
  `config` TEXT NOT NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `reference_id` INTEGER NULL,
  `sort_order` INTEGER NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ruleset_id`) REFERENCES `rulesets`(id) ON DELETE CASCADE,
  CHECK (`severity` IN ('info','warning','blocking','verification_required')),
  CHECK (`rule_type` IN ('manual','approval_required','document_required','notice_period','amount_threshold','date_sequence','field_required'))
);

CREATE TABLE `rule_references` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `title` VARCHAR(200) NOT NULL,
  `authority` VARCHAR(200) NULL,
  `manual_name` VARCHAR(160) NULL,
  `reference_number` VARCHAR(160) NULL,
  `publication_date` DATE NULL,
  `effective_date` DATE NULL,
  `source_url` VARCHAR(500) NULL,
  `source_document_id` INTEGER NULL,
  `version` VARCHAR(40) NULL,
  `notes` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `rule_evaluations` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `ruleset_id` INTEGER NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` INTEGER NULL,
  `results` TEXT NOT NULL,
  `summary` TEXT NOT NULL,
  `evaluated_by` INTEGER NULL,
  `evaluated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ruleset_id`) REFERENCES `rulesets`(id) ON DELETE SET NULL,
  FOREIGN KEY (`evaluated_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `schemes` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `code` VARCHAR(40) NULL UNIQUE,
  `description` TEXT NULL,
  `sponsoring_authority` VARCHAR(200) NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `funds` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `name` VARCHAR(200) NOT NULL,
  `code` VARCHAR(40) NULL UNIQUE,
  `funding_source` VARCHAR(120) NULL,
  `head_of_account` VARCHAR(40) NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `budget_allocations` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `fy_id` INTEGER NOT NULL,
  `scheme_id` INTEGER NULL,
  `fund_id` INTEGER NULL,
  `sanctioned_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`scheme_id`) REFERENCES `schemes`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`fund_id`) REFERENCES `funds`(id) ON DELETE RESTRICT
);

CREATE TABLE `projects` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `fy_id` INTEGER NOT NULL,
  `scheme_id` INTEGER NULL,
  `fund_id` INTEGER NULL,
  `head_of_account` VARCHAR(40) NULL,
  `project_code` VARCHAR(60) NULL UNIQUE,
  `work_name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `location` VARCHAR(200) NULL,
  `administrative_approval_no` VARCHAR(120) NULL,
  `administrative_approval_date` DATE NULL,
  `administrative_approval_authority` VARCHAR(120) NULL,
  `administrative_approval_amount_minor` BIGINT NULL,
  `technical_sanction_no` VARCHAR(120) NULL,
  `technical_sanction_date` DATE NULL,
  `technical_sanction_authority` VARCHAR(120) NULL,
  `technical_sanction_amount_minor` BIGINT NULL,
  `estimate_amount_minor` BIGINT NULL,
  `sanctioned_amount_minor` BIGINT NULL,
  `tender_value_minor` BIGINT NULL,
  `awarded_amount_minor` BIGINT NULL,
  `contractor_id` INTEGER NULL,
  `work_order_id` INTEGER NULL,
  `start_date` DATE NULL,
  `planned_completion_date` DATE NULL,
  `actual_completion_date` DATE NULL,
  `physical_progress` DOUBLE NOT NULL DEFAULT 0,
  `financial_progress` DOUBLE NOT NULL DEFAULT 0,
  `status` VARCHAR(64) NOT NULL DEFAULT 'planned',
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`scheme_id`) REFERENCES `schemes`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`fund_id`) REFERENCES `funds`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('planned','approved','tendered','awarded','in_progress','completed','closed','cancelled'))
);

CREATE TABLE `procurement_plans` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `fy_id` INTEGER NOT NULL,
  `scheme_id` INTEGER NULL,
  `fund_id` INTEGER NULL,
  `project_id` INTEGER NULL,
  `work_name` VARCHAR(255) NOT NULL,
  `estimated_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `funding_source` VARCHAR(120) NULL,
  `procurement_method` VARCHAR(60) NULL,
  `expected_date` DATE NULL,
  `responsible_officer` VARCHAR(120) NULL,
  `budget_provision` TEXT NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'planned',
  `remarks` TEXT NOT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`scheme_id`) REFERENCES `schemes`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`fund_id`) REFERENCES `funds`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('planned','initiated','tender_created','cancelled'))
);

CREATE TABLE `contractors` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `contractor_code` VARCHAR(40) NULL UNIQUE,
  `legal_name` VARCHAR(200) NOT NULL,
  `business_name` VARCHAR(200) NULL,
  `address` TEXT NULL,
  `mobile` VARCHAR(40) NULL,
  `email` VARCHAR(190) NULL,
  `registration_no` VARCHAR(120) NULL,
  `registration_class` VARCHAR(60) NULL,
  `registration_valid_from` DATE NULL,
  `registration_valid_to` DATE NULL,
  `pan` VARCHAR(20) NULL,
  `gst` VARCHAR(20) NULL,
  `bank_name` VARCHAR(160) NULL,
  `bank_account_no` VARCHAR(40) NULL,
  `bank_ifsc` VARCHAR(20) NULL,
  `experience_summary` TEXT NULL,
  `performance_rating` DOUBLE NULL,
  `performance_remarks` TEXT NULL,
  `is_debarred` INTEGER NOT NULL DEFAULT 0,
  `debarment_reason` TEXT NULL,
  `debarment_from` DATE NULL,
  `debarment_to` DATE NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'active',
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('active','inactive','debarred'))
);

CREATE TABLE `contractor_documents` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `contractor_id` INTEGER NOT NULL,
  `doc_type` VARCHAR(40) NOT NULL,
  `doc_name` VARCHAR(200) NOT NULL,
  `document_id` INTEGER NULL,
  `issued_date` DATE NULL,
  `expiry_date` DATE NULL,
  `expiry_status` VARCHAR(64) NOT NULL DEFAULT 'valid',
  `remarks` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE CASCADE,
  CHECK (`expiry_status` IN ('valid','expiring_soon','expired','verification_required','not_applicable'))
);

CREATE TABLE `documents` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` INTEGER NULL,
  `category` VARCHAR(60) NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) NULL,
  `size_bytes` INTEGER NULL,
  `sha256` VARCHAR(64) NULL,
  `uploaded_by` INTEGER NULL,
  `version` INTEGER NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `tender_approval_docs` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `doc_kind` VARCHAR(40) NOT NULL,
  `doc_number` VARCHAR(120) NULL,
  `doc_date` DATE NULL,
  `amount_minor` BIGINT NULL,
  `authority` VARCHAR(120) NULL,
  `document_id` INTEGER NULL,
  `remarks` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE
);

CREATE TABLE `tenders` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `fy_id` INTEGER NOT NULL,
  `project_id` INTEGER NULL,
  `scheme_id` INTEGER NULL,
  `fund_id` INTEGER NULL,
  `ruleset_id` INTEGER NULL,
  `tender_number` VARCHAR(80) NOT NULL UNIQUE,
  `nit_number` VARCHAR(80) NULL,
  `tender_type` VARCHAR(40) NOT NULL,
  `procurement_category` VARCHAR(40) NOT NULL,
  `procurement_method` VARCHAR(60) NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `location` VARCHAR(200) NULL,
  `work_name` VARCHAR(255) NULL,
  `admin_approval_no` VARCHAR(120) NULL,
  `admin_approval_date` DATE NULL,
  `admin_approval_authority` VARCHAR(120) NULL,
  `admin_approval_amount_minor` BIGINT NULL,
  `tech_sanction_no` VARCHAR(120) NULL,
  `tech_sanction_date` DATE NULL,
  `tech_sanction_authority` VARCHAR(120) NULL,
  `tech_sanction_amount_minor` BIGINT NULL,
  `estimated_cost_minor` BIGINT NOT NULL DEFAULT 0,
  `tender_value_minor` BIGINT NOT NULL DEFAULT 0,
  `emd_minor` BIGINT NULL,
  `emd_exemption_notes` TEXT NULL,
  `tender_fee_minor` BIGINT NULL,
  `security_deposit_minor` BIGINT NULL,
  `security_deposit_pct` DOUBLE NULL,
  `tax_config` TEXT NULL,
  `budget_provision` TEXT NULL,
  `head_of_account` VARCHAR(40) NULL,
  `completion_period_days` INTEGER NULL,
  `technical_specification` TEXT NULL,
  `eligibility_notes` TEXT NULL,
  `general_conditions` TEXT NULL,
  `special_conditions` TEXT NULL,
  `payment_conditions` TEXT NULL,
  `completion_conditions` TEXT NULL,
  `extension_conditions` TEXT NULL,
  `penalty_provisions` TEXT NULL,
  `defect_liability` TEXT NULL,
  `publication_date` DATE NULL,
  `bid_start_date` DATE NULL,
  `bid_close_date` DATE NULL,
  `technical_open_date` DATE NULL,
  `financial_open_date` DATE NULL,
  `bid_validity_days` INTEGER NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `compliance_status` VARCHAR(20) NULL,
  `workflow_stage` VARCHAR(40) NOT NULL,
  `award_recommendation_id` INTEGER NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE SET NULL,
  FOREIGN KEY (`scheme_id`) REFERENCES `schemes`(id) ON DELETE SET NULL,
  FOREIGN KEY (`fund_id`) REFERENCES `funds`(id) ON DELETE SET NULL,
  FOREIGN KEY (`ruleset_id`) REFERENCES `rulesets`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('draft','under_approval','approved','nit_generated','published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','cancelled','retendered','closed'))
);

CREATE TABLE `tender_versions` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `version_no` INTEGER NULL,
  `change_type` VARCHAR(40) NOT NULL,
  `reason` TEXT NOT NULL,
  `snapshot` TEXT NOT NULL,
  `changed_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `nit_versions` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `version_no` INTEGER NULL,
  `template_id` INTEGER NULL,
  `content_json` TEXT NOT NULL,
  `generated_by` INTEGER NULL,
  `document_id` INTEGER NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`generated_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `boq_items` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `item_no` VARCHAR(40) NOT NULL,
  `group_name` VARCHAR(120) NULL,
  `description` VARCHAR(500) NOT NULL,
  `specification` TEXT NULL,
  `unit` VARCHAR(40) NULL,
  `quantity` DOUBLE NOT NULL DEFAULT 0,
  `estimated_rate_minor` BIGINT NOT NULL DEFAULT 0,
  `tax_pct` DOUBLE NOT NULL DEFAULT 0,
  `sort_order` INTEGER NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE
);

CREATE TABLE `boq_versions` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `version_no` INTEGER NULL,
  `locked` INTEGER NOT NULL DEFAULT 0,
  `snapshot` TEXT NOT NULL,
  `locked_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`locked_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `tender_bidders` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `contractor_id` INTEGER NOT NULL,
  `bidder_label` VARCHAR(200) NULL,
  `submission_time` DATETIME NOT NULL,
  `bid_status` VARCHAR(64) NOT NULL DEFAULT 'submitted',
  `emd_paid_minor` BIGINT NULL,
  `emd_details` TEXT NULL,
  `technical_info` TEXT NULL,
  `financial_info` TEXT NULL,
  `rejection_reason` TEXT NULL,
  `rank` INTEGER NULL,
  `is_confidential` INTEGER NOT NULL DEFAULT 1,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`bid_status` IN ('submitted','technical_opened','technically_qualified','technically_disqualified','clarification_required','financial_opened','awarded','rejected','withdrawn'))
);

CREATE TABLE `bid_documents` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `bidder_id` INTEGER NOT NULL,
  `doc_type` VARCHAR(40) NOT NULL,
  `document_id` INTEGER NULL,
  `is_confidential` INTEGER NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bidder_id`) REFERENCES `tender_bidders`(id) ON DELETE CASCADE
);

CREATE TABLE `technical_openings` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `opening_date` DATE NOT NULL,
  `opened_by` INTEGER NULL,
  `attendees` TEXT NULL,
  `observations` TEXT NULL,
  `minutes_document_id` INTEGER NULL,
  `status` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`opened_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `evaluation_criteria` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `code` VARCHAR(64) NOT NULL,
  `criterion` VARCHAR(300) NOT NULL,
  `requirement` TEXT NULL,
  `is_required` INTEGER NOT NULL DEFAULT 1,
  `sort_order` INTEGER NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE
);

CREATE TABLE `technical_evaluations` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `bidder_id` INTEGER NOT NULL,
  `criterion_id` INTEGER NULL,
  `result` VARCHAR(64) NOT NULL DEFAULT 'pass',
  `bidder_response` TEXT NULL,
  `remarks` TEXT NULL,
  `evidence_document_id` INTEGER NULL,
  `evaluated_by` INTEGER NULL,
  `evaluated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`bidder_id`) REFERENCES `tender_bidders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`criterion_id`) REFERENCES `evaluation_criteria`(id) ON DELETE SET NULL,
  FOREIGN KEY (`evaluated_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`result` IN ('pass','fail','clarification_required','not_applicable','verification_required'))
);

CREATE TABLE `financial_bids` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `bidder_id` INTEGER NOT NULL,
  `total_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `base_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `tax_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `discount_minor` BIGINT NOT NULL DEFAULT 0,
  `quoted_on` DATETIME NULL,
  `is_confidential` INTEGER NOT NULL DEFAULT 1,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`bidder_id`) REFERENCES `tender_bidders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `financial_bid_items` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `financial_bid_id` INTEGER NOT NULL,
  `boq_item_id` INTEGER NULL,
  `item_no` VARCHAR(40) NULL,
  `bidder_rate_minor` BIGINT NOT NULL DEFAULT 0,
  `quantity` DOUBLE NOT NULL DEFAULT 0,
  `amount_minor` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`financial_bid_id`) REFERENCES `financial_bids`(id) ON DELETE CASCADE,
  FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items`(id) ON DELETE SET NULL
);

CREATE TABLE `awards` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `contractor_id` INTEGER NOT NULL,
  `bidder_id` INTEGER NULL,
  `awarded_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `rank` INTEGER NULL,
  `approval_authority` VARCHAR(120) NULL,
  `approval_date` DATE NULL,
  `loa_number` VARCHAR(80) NULL UNIQUE,
  `loa_date` DATE NULL,
  `security_deposit_minor` BIGINT NULL,
  `agreement_id` INTEGER NULL,
  `work_order_id` INTEGER NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'recommended',
  `remarks` TEXT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`bidder_id`) REFERENCES `tender_bidders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('recommended','approved','loa_issued','agreement_done','work_order_issued','completed','cancelled'))
);

CREATE TABLE `agreements` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `award_id` INTEGER NULL,
  `agreement_number` VARCHAR(80) NULL UNIQUE,
  `contractor_id` INTEGER NOT NULL,
  `amount_minor` BIGINT NOT NULL DEFAULT 0,
  `completion_period_days` INTEGER NULL,
  `conditions` TEXT NULL,
  `security_deposit_minor` BIGINT NULL,
  `execution_date` DATE NULL,
  `document_id` INTEGER NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`award_id`) REFERENCES `awards`(id) ON DELETE SET NULL,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `work_orders` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `award_id` INTEGER NULL,
  `agreement_id` INTEGER NULL,
  `project_id` INTEGER NULL,
  `contractor_id` INTEGER NOT NULL,
  `work_order_number` VARCHAR(80) NULL UNIQUE,
  `amount_minor` BIGINT NOT NULL DEFAULT 0,
  `start_date` DATE NULL,
  `completion_date` DATE NULL,
  `conditions` TEXT NULL,
  `document_id` INTEGER NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`award_id`) REFERENCES `awards`(id) ON DELETE SET NULL,
  FOREIGN KEY (`agreement_id`) REFERENCES `agreements`(id) ON DELETE SET NULL,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE SET NULL,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `work_progress` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `project_id` INTEGER NOT NULL,
  `tender_id` INTEGER NULL,
  `progress_date` DATE NOT NULL,
  `physical_progress` DOUBLE NOT NULL DEFAULT 0,
  `financial_progress` DOUBLE NOT NULL DEFAULT 0,
  `milestone` VARCHAR(200) NULL,
  `notes` TEXT NULL,
  `site_photo_document_id` INTEGER NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE CASCADE,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `site_instructions` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `project_id` INTEGER NOT NULL,
  `instruction_date` DATE NOT NULL,
  `instruction` TEXT NOT NULL,
  `issued_by` INTEGER NULL,
  `document_id` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE CASCADE,
  FOREIGN KEY (`issued_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `extension_requests` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `project_id` INTEGER NOT NULL,
  `requested_days` INTEGER NOT NULL DEFAULT 0,
  `reason` TEXT NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'pending',
  `decided_by` INTEGER NULL,
  `decided_at` DATETIME NULL,
  `remarks` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE CASCADE,
  FOREIGN KEY (`decided_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('pending','approved','rejected'))
);

CREATE TABLE `measurements` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `project_id` INTEGER NOT NULL,
  `tender_id` INTEGER NULL,
  `measurement_number` VARCHAR(60) NOT NULL,
  `measurement_date` DATE NOT NULL,
  `location` VARCHAR(200) NULL,
  `remarks` TEXT NULL,
  `measured_by` INTEGER NULL,
  `checked_by` INTEGER NULL,
  `approved_by` INTEGER NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE CASCADE,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`measured_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  FOREIGN KEY (`checked_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('draft','checked','approved','final'))
);

CREATE TABLE `measurement_items` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `measurement_id` INTEGER NOT NULL,
  `boq_item_id` INTEGER NULL,
  `item_no` VARCHAR(40) NULL,
  `description` VARCHAR(500) NOT NULL,
  `unit` VARCHAR(40) NULL,
  `previous_quantity` DOUBLE NOT NULL DEFAULT 0,
  `current_quantity` DOUBLE NOT NULL DEFAULT 0,
  `cumulative_quantity` DOUBLE NOT NULL DEFAULT 0,
  `rate_minor` BIGINT NOT NULL DEFAULT 0,
  `amount_minor` BIGINT NOT NULL DEFAULT 0,
  `overrun_flag` INTEGER NOT NULL DEFAULT 0,
  `remarks` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`measurement_id`) REFERENCES `measurements`(id) ON DELETE CASCADE,
  FOREIGN KEY (`boq_item_id`) REFERENCES `boq_items`(id) ON DELETE SET NULL
);

CREATE TABLE `bills` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `fy_id` INTEGER NOT NULL,
  `project_id` INTEGER NOT NULL,
  `tender_id` INTEGER NULL,
  `work_order_id` INTEGER NULL,
  `contractor_id` INTEGER NULL,
  `bill_number` VARCHAR(60) NOT NULL,
  `bill_type` VARCHAR(64) NOT NULL DEFAULT 'running',
  `bill_date` DATE NOT NULL,
  `gross_work_value_minor` BIGINT NOT NULL DEFAULT 0,
  `previous_certified_minor` BIGINT NOT NULL DEFAULT 0,
  `current_bill_minor` BIGINT NOT NULL DEFAULT 0,
  `cumulative_minor` BIGINT NOT NULL DEFAULT 0,
  `retention_minor` BIGINT NOT NULL DEFAULT 0,
  `tax_minor` BIGINT NOT NULL DEFAULT 0,
  `deductions_minor` BIGINT NOT NULL DEFAULT 0,
  `recoveries_minor` BIGINT NOT NULL DEFAULT 0,
  `net_payable_minor` BIGINT NOT NULL DEFAULT 0,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `certified_by` INTEGER NULL,
  `approved_by` INTEGER NULL,
  `remarks` TEXT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE CASCADE,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`work_order_id`) REFERENCES `work_orders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE SET NULL,
  FOREIGN KEY (`certified_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`bill_type` IN ('running','final')),
  CHECK (`status` IN ('draft','submitted','checked','certified','approved','paid','partially_paid','rejected','returned'))
);

CREATE TABLE `bill_items` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `bill_id` INTEGER NOT NULL,
  `measurement_item_id` INTEGER NULL,
  `item_no` VARCHAR(40) NULL,
  `description` VARCHAR(500) NOT NULL,
  `quantity` DOUBLE NOT NULL DEFAULT 0,
  `unit` VARCHAR(40) NULL,
  `rate_minor` BIGINT NOT NULL DEFAULT 0,
  `amount_minor` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bill_id`) REFERENCES `bills`(id) ON DELETE CASCADE,
  FOREIGN KEY (`measurement_item_id`) REFERENCES `measurement_items`(id) ON DELETE SET NULL
);

CREATE TABLE `payments` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `fy_id` INTEGER NOT NULL,
  `bill_id` INTEGER NULL,
  `project_id` INTEGER NULL,
  `contractor_id` INTEGER NULL,
  `voucher_no` VARCHAR(60) NULL,
  `payment_date` DATE NOT NULL,
  `gross_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `deductions_minor` BIGINT NOT NULL DEFAULT 0,
  `net_amount_minor` BIGINT NOT NULL DEFAULT 0,
  `payment_method` VARCHAR(40) NULL,
  `transaction_reference` VARCHAR(120) NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'recorded',
  `is_bank_integrated` INTEGER NOT NULL DEFAULT 0,
  `approval_authority` VARCHAR(120) NULL,
  `remarks` TEXT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`bill_id`) REFERENCES `bills`(id) ON DELETE SET NULL,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE SET NULL,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('recorded','approved','cancelled'))
);

CREATE TABLE `completions` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `project_id` INTEGER NOT NULL,
  `tender_id` INTEGER NULL,
  `completion_date` DATE NULL,
  `inspection_date` DATE NULL,
  `final_measurement_id` INTEGER NULL,
  `final_bill_id` INTEGER NULL,
  `final_payment_id` INTEGER NULL,
  `security_release_date` DATE NULL,
  `handover_date` DATE NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'in_progress',
  `certificate_document_id` INTEGER NULL,
  `closure_report_document_id` INTEGER NULL,
  `remarks` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE CASCADE,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`final_measurement_id`) REFERENCES `measurements`(id) ON DELETE SET NULL,
  FOREIGN KEY (`final_bill_id`) REFERENCES `bills`(id) ON DELETE SET NULL,
  FOREIGN KEY (`final_payment_id`) REFERENCES `payments`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('in_progress','inspection_done','final_measurement_done','final_bill_done','final_payment_done','security_released','closed'))
);

CREATE TABLE `contractor_performance` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `contractor_id` INTEGER NOT NULL,
  `project_id` INTEGER NULL,
  `tender_id` INTEGER NULL,
  `quality_rating` DOUBLE NULL,
  `timeliness_rating` DOUBLE NULL,
  `completion_rating` DOUBLE NULL,
  `defect_notes` TEXT NOT NULL,
  `delay_notes` TEXT NOT NULL,
  `extension_notes` TEXT NOT NULL,
  `overall_remarks` TEXT NOT NULL,
  `rated_by` INTEGER NULL,
  `rated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`contractor_id`) REFERENCES `contractors`(id) ON DELETE CASCADE,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(id) ON DELETE SET NULL,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`rated_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `corrigenda` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `corrigendum_number` VARCHAR(60) NOT NULL,
  `reason` TEXT NOT NULL,
  `changes` TEXT NOT NULL,
  `new_dates` TEXT NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'draft',
  `document_id` INTEGER NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('draft','approved','published'))
);

CREATE TABLE `tender_cancellations` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `tender_id` INTEGER NOT NULL,
  `reason` TEXT NOT NULL,
  `authority` VARCHAR(120) NULL,
  `cancel_date` DATE NULL,
  `approval_authority` VARCHAR(120) NULL,
  `notice_document_id` INTEGER NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tender_id`) REFERENCES `tenders`(id) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `retenders` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `original_tender_id` INTEGER NOT NULL,
  `new_tender_id` INTEGER NULL,
  `reason` TEXT NULL,
  `carry_forward` TEXT NOT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`original_tender_id`) REFERENCES `tenders`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`new_tender_id`) REFERENCES `tenders`(id) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `approval_steps` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` INTEGER NULL,
  `step_order` INTEGER NULL,
  `step_name` VARCHAR(160) NOT NULL,
  `required_role` VARCHAR(60) NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'pending',
  `actor_id` INTEGER NULL,
  `action` VARCHAR(40) NULL,
  `remarks` TEXT NULL,
  `acted_at` DATETIME NULL,
  `version_no` INTEGER NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`actor_id`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('pending','in_progress','approved','rejected','returned','clarification','skipped','cancelled'))
);

CREATE TABLE `notifications` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `user_id` INTEGER NOT NULL,
  `type` VARCHAR(60) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT NOT NULL,
  `entity_type` VARCHAR(40) NULL,
  `entity_id` INTEGER NULL,
  `link` VARCHAR(300) NULL,
  `severity` VARCHAR(64) NOT NULL DEFAULT 'info',
  `is_read` INTEGER NOT NULL DEFAULT 0,
  `channel` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(id) ON DELETE CASCADE,
  CHECK (`severity` IN ('info','warning','critical'))
);

CREATE TABLE `audit_logs` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `actor_id` INTEGER NULL,
  `actor_name` VARCHAR(160) NULL,
  `actor_role` VARCHAR(60) NULL,
  `panchayat_id` INTEGER NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` INTEGER NULL,
  `entity_label` VARCHAR(255) NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  `reason` TEXT NULL,
  `ip_address` VARCHAR(64) NULL,
  `user_agent` TEXT NULL,
  `request_id` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`actor_id`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `numbering_sequences` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `scope` VARCHAR(60) NOT NULL,
  `fy_id` INTEGER NULL,
  `panchayat_id` INTEGER NULL,
  `prefix` VARCHAR(60) NOT NULL,
  `last_value` INTEGER NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`fy_id`) REFERENCES `financial_years`(id) ON DELETE RESTRICT,
  FOREIGN KEY (`panchayat_id`) REFERENCES `panchayats`(id) ON DELETE RESTRICT
);

CREATE TABLE `settings` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `value` TEXT NOT NULL,
  `description` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `templates` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `code` VARCHAR(60) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `doc_type` VARCHAR(60) NOT NULL,
  `version` INTEGER NOT NULL DEFAULT 1,
  `content_html` TEXT NOT NULL,
  `merge_fields` TEXT NOT NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `is_default` INTEGER NOT NULL DEFAULT 0,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `document_generations` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `doc_type` VARCHAR(60) NOT NULL,
  `entity_type` VARCHAR(40) NULL,
  `entity_id` INTEGER NULL,
  `template_id` INTEGER NULL,
  `template_version` INTEGER NULL,
  `document_id` INTEGER NULL,
  `verification_code` VARCHAR(32) NULL,
  `generated_by` INTEGER NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`generated_by`) REFERENCES `users`(id) ON DELETE SET NULL
);

CREATE TABLE `external_refs` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` INTEGER NULL,
  `official_portal` VARCHAR(200) NULL,
  `external_tender_id` VARCHAR(120) NULL,
  `external_reference_no` VARCHAR(120) NULL,
  `publication_status` VARCHAR(60) NULL,
  `official_url` VARCHAR(500) NULL,
  `publication_timestamp` DATETIME NULL,
  `external_status` VARCHAR(60) NULL,
  `last_synced_at` DATETIME NULL,
  `sync_method` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `imports` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `import_type` VARCHAR(60) NOT NULL,
  `file_name` VARCHAR(255) NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'validating',
  `total_rows` INTEGER NOT NULL DEFAULT 0,
  `valid_rows` INTEGER NOT NULL DEFAULT 0,
  `error_report` TEXT NOT NULL,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL,
  CHECK (`status` IN ('validating','preview','committed','failed'))
);

CREATE TABLE `backups` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid VARCHAR(36) NOT NULL UNIQUE,
  `backup_type` VARCHAR(20) NOT NULL,
  `file_name` VARCHAR(255) NULL,
  `size_bytes` INTEGER NULL,
  `status` VARCHAR(20) NOT NULL,
  `verified` INTEGER NOT NULL DEFAULT 0,
  `created_by` INTEGER NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(id) ON DELETE SET NULL
);


