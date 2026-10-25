-- Migration: Add term to grade and create enrolled_subject table
-- CuyoTech University SSIS

-- 1. Alter grade table to add school_year and semester
ALTER TABLE `grade` 
  ADD COLUMN `school_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027' AFTER `subject_id`,
  ADD COLUMN `semester` VARCHAR(20) NOT NULL DEFAULT '1st Sem' AFTER `school_year`;

-- Update foreign key and unique constraint
ALTER TABLE `grade` DROP FOREIGN KEY `grade_ibfk_1`;
ALTER TABLE `grade` DROP INDEX `uq_grade`;
ALTER TABLE `grade` ADD UNIQUE KEY `uq_grade_term` (`student_id`, `subject_id`, `school_year`, `semester`);
ALTER TABLE `grade` ADD CONSTRAINT `grade_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `student` (`student_id`) ON DELETE CASCADE;

-- 2. Create enrolled_subject table
CREATE TABLE IF NOT EXISTS `enrolled_subject` (
  `enrolled_subject_id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `school_year` VARCHAR(20) NOT NULL DEFAULT '2026-2027',
  `semester` VARCHAR(20) NOT NULL DEFAULT '1st Sem',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`student_id`) REFERENCES `student`(`student_id`) ON DELETE CASCADE,
  FOREIGN KEY (`subject_id`) REFERENCES `subject`(`subject_id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_enr_sub` (`student_id`, `subject_id`, `school_year`, `semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Backfill enrolled_subject for AY 2026-2027, 1st Sem
-- Backfill from existing grades
INSERT IGNORE INTO `enrolled_subject` (`student_id`, `subject_id`, `school_year`, `semester`)
SELECT DISTINCT `student_id`, `subject_id`, '2026-2027', '1st Sem'
FROM `grade`;

-- Backfill from enrolled students based on their program & year level
INSERT IGNORE INTO `enrolled_subject` (`student_id`, `subject_id`, `school_year`, `semester`)
SELECT s.student_id, sub.subject_id, '2026-2027', '1st Sem'
FROM student s
JOIN enrollment e ON e.student_id = s.student_id AND e.enrollment_status = 'Enrolled'
JOIN subject sub ON sub.program = s.program 
  AND sub.year_level = CAST(SUBSTRING(s.section, LOCATE(' ', s.section) + 1, 1) AS UNSIGNED)
WHERE CAST(SUBSTRING(s.section, LOCATE(' ', s.section) + 1, 1) AS UNSIGNED) > 0;
