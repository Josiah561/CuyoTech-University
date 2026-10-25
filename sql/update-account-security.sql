-- Migration: Account security enhancements (password_reset table, must_change_password, login IP throttling)
-- CuyoTech University SSIS

-- 1. Add must_change_password to user_account
ALTER TABLE `user_account`
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- 2. Add ip_address to login_attempt table
ALTER TABLE `login_attempt`
  ADD COLUMN `ip_address` VARCHAR(45) NOT NULL DEFAULT '127.0.0.1' AFTER `username`,
  ADD INDEX `idx_ip_attempt` (`ip_address`, `attempted_at`);

-- 3. Create password_reset table
CREATE TABLE IF NOT EXISTS `password_reset` (
  `reset_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `user_account`(`user_id`) ON DELETE CASCADE,
  INDEX `idx_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
