-- Adds the real-time Gmail code columns to email_verifications.
-- Safe to import more than once only if the columns are not already present.
--   C:/xampp/mysql/bin/mysql.exe -u root barangay_eservices < upgrade_add_email_code.sql

USE barangay_eservices;

ALTER TABLE email_verifications
  ADD COLUMN code_hash CHAR(64) NULL AFTER token_hash,
  ADD COLUMN code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER code_hash;
