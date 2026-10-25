-- Run once in phpMyAdmin (database `ssis`) on an EXISTING install, instead of re-importing database.sql.
-- Adds per-program curriculum, the admin-managed section list, and the new building room names.

-- 1) Subjects now belong to a program + year level
ALTER TABLE subject
  ADD COLUMN program    VARCHAR(100) NOT NULL DEFAULT 'Bachelor of Science in Information Technology' AFTER subject_id,
  ADD COLUMN year_level TINYINT      NOT NULL DEFAULT 3 AFTER program,
  MODIFY room VARCHAR(60) NOT NULL,
  DROP INDEX code,
  ADD UNIQUE KEY uq_subject (program, code),
  ADD INDEX (program, year_level);

-- Existing BSIT rooms -> real buildings (IT Building no longer exists)
UPDATE subject SET room = 'Bagong Cabuyao Hall · BCH 301'                WHERE code = 'IT 301';
UPDATE subject SET room = 'Bagong Cabuyao Hall · BCH 501 (Computer Lab)' WHERE code = 'IT 302';
UPDATE subject SET room = 'Bagong Cabuyao Hall · BCH 502 (Computer Lab)' WHERE code = 'IT 303';
UPDATE subject SET room = 'Bagong Cabuyao Hall · BCH 303'                WHERE code = 'IT 304';
UPDATE subject SET room = 'Bagong Cabuyao Hall · BCH 304'                WHERE code = 'IT 305';

-- 2) Nursing 1st-year load (placeholder curriculum, Mayor Etok Building)
INSERT INTO subject (program,year_level,code,title,schedule,room,units) VALUES
('Bachelor of Science in Nursing',1,'NCM 101','Theoretical Foundations in Nursing','Mon / Wed 8:00-9:30','Mayor Etok Building · 201',3),
('Bachelor of Science in Nursing',1,'ANAT 101','Anatomy & Physiology','Tue / Thu 8:00-9:30','Mayor Etok Building · 202',3),
('Bachelor of Science in Nursing',1,'CHEM 101','Chemistry for Health Sciences','Mon / Wed 10:00-11:30','Mayor Etok Building · 203',3),
('Bachelor of Science in Nursing',1,'GE 101','Understanding the Self','Tue / Thu 10:00-11:30','Main Building · 105',3),
('Bachelor of Science in Nursing',1,'GE 102','Purposive Communication','Mon / Wed 13:00-14:30','Main Building · 106',3),
('Bachelor of Science in Nursing',1,'NSTP 101','National Service Training Program 1','Sat 8:00-11:00','Main Building · 107',3);

-- 3) Section list (admin can add / delete more from Create account)
CREATE TABLE IF NOT EXISTS section (
  section_id   INT AUTO_INCREMENT PRIMARY KEY,
  section_name VARCHAR(20)  NOT NULL UNIQUE,
  program      VARCHAR(100) NOT NULL,
  year_level   TINYINT      NOT NULL,
  INDEX (program, year_level)
) ENGINE=InnoDB;

INSERT IGNORE INTO section (section_name,program,year_level) VALUES
('BSIT 1A','Bachelor of Science in Information Technology',1),
('BSIT 1B','Bachelor of Science in Information Technology',1),
('BSIT 3A','Bachelor of Science in Information Technology',3),
('BSIT 3B','Bachelor of Science in Information Technology',3),
('BSN 1A','Bachelor of Science in Nursing',1),
('BSN 1B','Bachelor of Science in Nursing',1);

-- Also register any section already used by a student
INSERT IGNORE INTO section (section_name,program,year_level)
SELECT DISTINCT section, program, CAST(REGEXP_SUBSTR(section, '[0-9]') AS UNSIGNED)
FROM student WHERE section REGEXP '[0-9][A-Za-z]*$';

ALTER TABLE student ALTER COLUMN section SET DEFAULT 'TBA';
