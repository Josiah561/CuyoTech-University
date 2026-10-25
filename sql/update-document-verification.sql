-- Migration: Add verification code and issuance tracking to document_request
-- CuyoTech University SSIS

ALTER TABLE `document_request`
  ADD COLUMN `verification_code` VARCHAR(64) UNIQUE NULL AFTER `release_date`,
  ADD COLUMN `issued_by` INT NULL AFTER `verification_code`,
  ADD COLUMN `issued_at` DATETIME NULL AFTER `issued_by`,
  ADD CONSTRAINT `fk_doc_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `user_account`(`user_id`);

-- Generate verification codes for any existing Ready or Released documents
UPDATE `document_request`
SET `verification_code` = CONCAT('CTU-VER-', UPPER(SUBSTRING(MD5(CONCAT(request_id, student_id, request_date, 'CuyoTech-Secret')), 1, 12))),
    `issued_at` = NOW()
WHERE `request_status` IN ('Ready', 'Released') AND `verification_code` IS NULL;
