-- =====================================================================
--  St. Mark's Laptop Tracking System — fresh-install schema
--  Create an empty database first (e.g. via cPanel's MySQL Databases
--  wizard, or phpMyAdmin), select it, then import this file from the
--  phpMyAdmin Import tab. It does NOT create or switch databases itself,
--  so it always imports into whichever database you have selected.
--  The app auto-creates extra tables (settings, notifications_log,
--  overdue_alerts) and adds new columns on first page load.
-- =====================================================================

-- ----------------------------- USERS / STAFF -----------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `user_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(64) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `role`       VARCHAR(32) NOT NULL DEFAULT 'admin',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin login: username = "admin", password = "admin123"
-- (Hash generated with PHP password_hash('admin123', PASSWORD_DEFAULT))
INSERT INTO `users` (`username`, `password`, `role`)
VALUES ('admin', '$2y$10$qIZWpTl5vbLZvQEcCbhH7uzJ4Xp1KxC8C8jLYnYvNHA0qZQ4f5lRm', 'admin')
ON DUPLICATE KEY UPDATE username = username;

-- ----------------------------- PARENTS -----------------------------
CREATE TABLE IF NOT EXISTS `parents` (
    `parent_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(150) NOT NULL,
    `contact`    VARCHAR(32) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------- STUDENTS -----------------------------
CREATE TABLE IF NOT EXISTS `students` (
    `student_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(150) NOT NULL,
    `class`      VARCHAR(64) NULL,
    `stream`     VARCHAR(64) NULL,
    `parent_id`  INT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (`parent_id`),
    CONSTRAINT `fk_students_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents`(`parent_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------- LAPTOPS -----------------------------
CREATE TABLE IF NOT EXISTS `laptops` (
    `laptop_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `laptop_number` VARCHAR(64) NOT NULL UNIQUE,
    `serial_number` VARCHAR(128) NOT NULL,
    `model`         VARCHAR(150) NULL,
    `status`        VARCHAR(32) NOT NULL DEFAULT 'back_to_school',
    `notes`         TEXT NULL,
    `student_id`    INT NULL,
    `due_date`      DATETIME NULL,
    `issued_at`     DATETIME NULL,
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (`student_id`), INDEX (`status`), INDEX (`serial_number`),
    CONSTRAINT `fk_laptops_student` FOREIGN KEY (`student_id`) REFERENCES `students`(`student_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------- LOGS -----------------------------
CREATE TABLE IF NOT EXISTS `logs` (
    `log_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `laptop_id`  INT NULL,
    `student_id` INT NULL,
    `action`     VARCHAR(64) NOT NULL,
    `staff_name` VARCHAR(64) NOT NULL,
    `timestamp`  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (`laptop_id`), INDEX (`student_id`), INDEX (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE laptop_usage_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    laptop_id INT,
    staff_name VARCHAR(100),
    login_time DATETIME,
    logout_time DATETIME,
    activity TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE laptop_usage_log
ADD COLUMN duration_minutes INT DEFAULT 0;