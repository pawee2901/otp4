-- =========================================================================
-- สร้างฐานข้อมูลบน TiDB Cloud (รันครั้งเดียว)
-- วิธีใช้: TiDB Cloud > เลือก instance > SQL Editor > วางทั้งหมดนี้ > Run
-- =========================================================================
CREATE DATABASE IF NOT EXISTS otp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE otp;

CREATE TABLE IF NOT EXISTS settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    shop_name VARCHAR(191) NOT NULL DEFAULT 'Monali Premium',
    logo_path MEDIUMTEXT NULL,
    disney_logo_path VARCHAR(255) NOT NULL DEFAULT '',
    trueid_logo_path VARCHAR(255) NOT NULL DEFAULT '',
    admin_username VARCHAR(100) NOT NULL DEFAULT 'admin',
    admin_password VARCHAR(255) NOT NULL DEFAULT 'admin1234',
    api_key_1 VARCHAR(255) NULL,
    api_key_2 VARCHAR(255) NULL,
    ms_client_id VARCHAR(255) NULL,
    ms_client_secret VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS apps (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(10) NOT NULL,
    logo_path MEDIUMTEXT NULL,
    keywords VARCHAR(255) NULL,
    bg_color VARCHAR(20) NULL,
    border_color VARCHAR(20) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imap_emails (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    provider ENUM('gmail','hotmail','maily','microsoft_graph') NOT NULL DEFAULT 'gmail',
    host VARCHAR(191) NULL,
    port SMALLINT UNSIGNED NULL,
    password VARCHAR(255) NULL,
    refresh_token TEXT NULL,
    app_id VARCHAR(64) NOT NULL DEFAULT 'all',
    access_pin VARCHAR(10) NULL,
    netflix_otp_4digit TINYINT(1) NOT NULL DEFAULT 0,
    netflix_otp_6digit TINYINT(1) NOT NULL DEFAULT 0,
    netflix_6digit_allowed TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_imap_emails_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_providers (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    type ENUM('maily','cloud_run') NOT NULL DEFAULT 'maily',
    token VARCHAR(255) NULL,
    url VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gmail_central_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL,
    password VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_gmail_central_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maily_central_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pin_attempts (
    attempt_key VARCHAR(191) NOT NULL PRIMARY KEY,
    fails INT NOT NULL DEFAULT 0,
    locked_until INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ค่าเริ่มต้น: แอดมิน admin / admin1234 (เข้าแล้วเปลี่ยนรหัสในแท็บตั้งค่า API ทันที)
INSERT IGNORE INTO settings (id, shop_name, logo_path, admin_username, admin_password)
VALUES (1, 'Monali Premium', '/static/monali_logo.jpg', 'admin', 'admin1234');

INSERT IGNORE INTO apps (id, name, code, logo_path, keywords, sort_order, active) VALUES
('app_netflix', 'Netflix', 'NF', '', 'netflix', 0, 1),
('app_disney',  'Disney+', 'DN', '/static/logo_disney_1780413683.jpg', 'disney', 1, 1),
('app_trueid',  'TrueID',  'TM', '/static/logo_trueid_1780413707.jpg', 'true', 2, 1);
