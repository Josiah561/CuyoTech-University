-- Run once in phpMyAdmin (database `ssis`) to move an existing install to AY 2026-2027 without re-importing.
UPDATE enrollment SET school_year = '2026-2027' WHERE school_year = '2025-2026';
