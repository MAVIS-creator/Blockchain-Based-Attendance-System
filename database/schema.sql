-- High-Q Solid Academy Biometric Attendance System Schema Extension
-- 100% Compatible with highqsol_highq database dump

-- 1. Ensure `is_active` column exists on central `users` table if not present
SET @dbname = DATABASE();
SET @tablename = "users";
SET @columnname = "is_active";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE `users` ADD `is_active` TINYINT(1) DEFAULT 0 COMMENT '0=Pending Approval, 1=Active';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Fingerprints Biometric Templates Table
CREATE TABLE IF NOT EXISTS `fingerprints` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `finger_index` INT DEFAULT 1 COMMENT '1=Right Thumb, 2=Right Index, 3=Left Thumb, etc.',
    `template` LONGTEXT NOT NULL COMMENT 'Base64 encoded SDK fingerprint template',
    `quality` VARCHAR(20) DEFAULT 'Good',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_fp_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Attendance Records Table
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `check_in` TIME DEFAULT NULL,
    `check_out` TIME DEFAULT NULL,
    `status` ENUM('Present', 'Late', 'Absent', 'Half Day') DEFAULT 'Present',
    `verified_by` VARCHAR(50) DEFAULT 'Biometric Scanner',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `student_daily_attendance` (`student_id`, `date`),
    KEY `idx_att_date` (`date`),
    KEY `idx_att_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Kiosk & System Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Kiosk Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('terminal_pin', '1234'),
('check_in_time', '08:00'),
('late_time', '08:30'),
('check_out_time', '15:00')
ON DUPLICATE KEY UPDATE `setting_key`=`setting_key`;
