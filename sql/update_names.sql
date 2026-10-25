-- Run this in phpMyAdmin (or the mysql client) on an EXISTING database
-- to apply the new names without re-importing database.sql.
-- Select your database first (the one created by database.sql).

UPDATE department SET department_name = 'College of Computing Studies' WHERE department_name = 'College of Computer Studies';

UPDATE user_account SET full_name = 'Josiah Bandong'           WHERE username = 'admin01';
UPDATE user_account SET full_name = 'Dr. George F. Barundia'   WHERE username = 'reg01';
UPDATE user_account SET full_name = 'Ms. April B. Galang'      WHERE username = 'cash01';
UPDATE user_account SET full_name = 'Dr. Gima Montecillo'              WHERE username = 'dept01';

UPDATE student SET first_name = 'Regie',     last_name = 'Apues', email = 'regie.apues@cuyotech.edu.ph'     WHERE student_number = '2023-00418';
UPDATE student SET first_name = 'Jovic',     last_name = 'Apues', email = 'jovic.apues@cuyotech.edu.ph'     WHERE student_number = '2023-00502';
UPDATE student SET first_name = 'Nathaniel', last_name = 'Apues', email = 'nathaniel.apues@cuyotech.edu.ph' WHERE student_number = '2023-00377';

-- Colleges (department dropdown in Create Account)
INSERT IGNORE INTO department (department_name, department_code) VALUES
('College of Engineering', 'COE'),
('College of Education, Arts, and Sciences', 'CEAS'),
('College of Health and Allied Sciences', 'CHAS'),
('College of Business, Accountancy and Administration', 'CBAA');

-- Degree programs now use the full degree name
ALTER TABLE student ALTER COLUMN program SET DEFAULT 'Bachelor of Science in Information Technology';
UPDATE student SET program = 'Bachelor of Science in Information Technology' WHERE program = 'BS Information Technology';
