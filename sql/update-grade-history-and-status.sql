-- Migration: Add is_published to grade and create grade_history table
-- CuyoTech University SSIS

-- 1. Add is_published column to grade table
ALTER TABLE `grade`
  ADD COLUMN `is_published` TINYINT(1) NOT NULL DEFAULT 0 AFTER `final_grade`;

-- Existing seeded grades are marked published so current students have their initial grades
UPDATE `grade` SET `is_published` = 1;

-- 2. Create grade_history table for audit and traceability
CREATE TABLE IF NOT EXISTS `grade_history` (
  `history_id` INT AUTO_INCREMENT PRIMARY KEY,
  `grade_id` INT NULL,
  `student_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `school_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
  `semester` VARCHAR(20) NOT NULL DEFAULT '1st Sem',
  `period` ENUM('prelim', 'midterm', 'final_grade') NOT NULL,
  `old_value` DECIMAL(3,2) NULL,
  `new_value` DECIMAL(3,2) NULL,
  `changed_by` INT NOT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `student`(`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subject`(`subject_id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `user_account`(`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
