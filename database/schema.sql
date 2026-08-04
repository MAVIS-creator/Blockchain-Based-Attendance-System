-- High-Q Solid Academy Biometric Attendance System Database Schema

CREATE DATABASE IF NOT EXISTS `highq_attendance` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `highq_attendance`;

-- Admin Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `fullname` VARCHAR(100) NOT NULL,
    `role` ENUM('superadmin', 'admin', 'operator') DEFAULT 'admin',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial Admin Account (Password: admin123)
INSERT INTO `users` (`username`, `password`, `fullname`, `role`)
VALUES ('admin', '$2y$10$a1j8y.VvowGitS1EgZpy4u/tRI7JaTUbc3ELkfYiBeANq2lhjihF.', 'Administrator', 'superadmin')
ON DUPLICATE KEY UPDATE `id`=`id`;

-- Students Table
CREATE TABLE IF NOT EXISTS `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admission_number` VARCHAR(50) NOT NULL UNIQUE,
    `surname` VARCHAR(50) NOT NULL,
    `firstname` VARCHAR(50) NOT NULL,
    `middlename` VARCHAR(50) DEFAULT NULL,
    `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
    `dob` DATE DEFAULT NULL,
    `class` VARCHAR(50) NOT NULL,
    `address` TEXT DEFAULT NULL,
    `parent_name` VARCHAR(100) DEFAULT NULL,
    `parent_phone` VARCHAR(30) DEFAULT NULL,
    `parent_email` VARCHAR(100) DEFAULT NULL,
    `photo` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('Registered', 'Awaiting Fingerprint', 'Fingerprint Linked', 'Inactive') DEFAULT 'Awaiting Fingerprint',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fingerprints Table
CREATE TABLE IF NOT EXISTS `fingerprints` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `finger_index` INT DEFAULT 1 COMMENT '1=Right Thumb, 2=Right Index, 3=Left Thumb, etc.',
    `template` LONGTEXT NOT NULL COMMENT 'Base64 encoded SDK fingerprint template',
    `quality` VARCHAR(20) DEFAULT 'Good',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance Records Table
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `check_in` TIME DEFAULT NULL,
    `check_out` TIME DEFAULT NULL,
    `status` ENUM('Present', 'Late', 'Absent', 'Currently In School', 'Completed', 'Early Departure') DEFAULT 'Present',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_student_date` (`student_id`, `date`),
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(50) PRIMARY KEY,
    `setting_value` TEXT NOT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('school_name', 'High-Q Solid Academy'),
('school_logo', ''),
('attendance_start_time', '07:30:00'),
('attendance_closing_time', '15:30:00'),
('late_threshold_time', '08:00:00')
ON DUPLICATE KEY UPDATE `setting_key`=`setting_key`;

-- Classes Table
CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `sort_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default Classes
INSERT INTO `classes` (`name`, `sort_order`) VALUES
('Basic 1', 1), ('Basic 2', 2), ('Basic 3', 3), ('Basic 4', 4), ('Basic 5', 5),
('JSS 1', 6), ('JSS 2', 7), ('JSS 3', 8),
('SSS 1', 9), ('SSS 2', 10), ('SSS 3', 11)
ON DUPLICATE KEY UPDATE `name`=`name`;

