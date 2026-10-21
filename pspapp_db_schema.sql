-- =========================================================================
-- Schema สำหรับ u458279693_pspapp_db
-- แปลงจากโครงสร้างข้อมูลใน config.json ของ gooddaypremiumotp (admin.php/get_otp.php/app.py)
-- วิธีใช้: เปิด phpMyAdmin -> เลือกฐานข้อมูล u458279693_pspapp_db -> แท็บ "SQL" -> วางทั้งหมดนี้ -> Go
-- =========================================================================

-- 1) ตั้งค่าร้าน/แอดมิน/API key หลัก (มีแถวเดียวเสมอ)
CREATE TABLE settings (
    id                 TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    shop_name          VARCHAR(191)  NOT NULL DEFAULT 'gooddaypremium',
    logo_path          VARCHAR(255)  NOT NULL DEFAULT '/static/logo.jpg',
    disney_logo_path   VARCHAR(255)  NOT NULL DEFAULT '',
    trueid_logo_path   VARCHAR(255)  NOT NULL DEFAULT '',
    admin_username     VARCHAR(100)  NOT NULL DEFAULT 'admin',
    admin_password     VARCHAR(255)  NOT NULL DEFAULT 'admin1234',
    api_key_1          VARCHAR(255)  NULL,
    api_key_2          VARCHAR(255)  NULL,
    ms_client_id       VARCHAR(255)  NULL,
    ms_client_secret   VARCHAR(255)  NULL,
    updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_settings_single_row CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (id, shop_name) VALUES (1, 'gooddaypremium');

-- 2) รายการแอป (Netflix, Disney+, TrueID, ChatGPT ฯลฯ)
CREATE TABLE apps (
    id            VARCHAR(64)   NOT NULL PRIMARY KEY,   -- เช่น app_disney, app_1785027508
    name          VARCHAR(191)  NOT NULL,
    code          VARCHAR(10)   NOT NULL,                -- DN, TM, GPT, NF, PR ...
    logo_path     VARCHAR(255)  NULL,
    keywords      VARCHAR(255)  NULL,                    -- ใช้จับคู่กับหัวข้อ/เนื้อหาอีเมล
    bg_color      VARCHAR(20)   NULL,
    border_color  VARCHAR(20)   NULL,
    sort_order    INT           NOT NULL DEFAULT 0,
    active        TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) บัญชีอีเมล IMAP / Microsoft Graph ที่ระบบใช้ดึง OTP
CREATE TABLE imap_emails (
    id              VARCHAR(64)   NOT NULL PRIMARY KEY,   -- email_1, msgraph_<timestamp> ...
    email           VARCHAR(191)  NOT NULL,
    provider        ENUM('gmail','hotmail','maily','microsoft_graph') NOT NULL DEFAULT 'gmail',
    host            VARCHAR(191)  NULL,
    port            SMALLINT UNSIGNED NULL,
    password        VARCHAR(255)  NULL,                   -- IMAP app password (เข้ารหัสเพิ่มถ้าจะใช้จริง)
    refresh_token   TEXT          NULL,                    -- สำหรับ provider = microsoft_graph
    app_id          VARCHAR(64)   NOT NULL DEFAULT 'all',  -- 'all' หรือผูกกับ apps.id
    netflix_otp_4digit TINYINT(1)  NOT NULL DEFAULT 0,      -- เฉพาะ Netflix: เปิด = บังคับลองหารหัส OTP 4 หลักก่อน (ปุ่มสีเขียว/แดงในหน้า Admin)
    netflix_otp_6digit TINYINT(1)  NOT NULL DEFAULT 0,      -- เฉพาะ Netflix: เปิด = บังคับลองหารหัส OTP 6 หลักก่อน (ปิดทั้งคู่ = ตรวจจับความยาวอัตโนมัติแบบเดิม)
    active          TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_imap_emails_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) API provider ภายนอก (Maily Space keys, Cloud Run)
CREATE TABLE api_providers (
    id       VARCHAR(64)   NOT NULL PRIMARY KEY,          -- maily_space_1, cloud_run_rdcw ...
    name     VARCHAR(191)  NOT NULL,
    type     ENUM('maily','cloud_run') NOT NULL DEFAULT 'maily',
    token    VARCHAR(255)  NULL,
    url      VARCHAR(255)  NULL,
    active   TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Gmail กลางสำรอง (fallback) สำหรับรับ Forwarding
CREATE TABLE gmail_central_accounts (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    email    VARCHAR(191) NOT NULL,
    password VARCHAR(255) NOT NULL,        -- Gmail App Password
    UNIQUE KEY uq_gmail_central_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) บัญชี Maily Space กลาง
CREATE TABLE maily_central_accounts (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(191) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Migration: เพิ่มคอลัมน์เปิด/ปิดรับรหัส OTP Netflix 4 หลัก และ 6 หลัก (ปุ่มสีเขียว/แดงในหน้า Admin)
-- ให้ตาราง imap_emails ที่ "สร้างไปแล้วก่อนหน้านี้"
-- ถ้าเพิ่งสร้างฐานข้อมูลใหม่ทั้งหมดด้วยไฟล์นี้ (มีคอลัมน์เหล่านี้ในตาราง imap_emails ด้านบนอยู่แล้ว) ข้ามคำสั่งนี้ได้เลย
-- วิธีใช้: เปิด phpMyAdmin -> เลือกฐานข้อมูล u458279693_pspapp_db -> แท็บ "SQL" -> วางคำสั่งนี้ -> Go
-- =========================================================================
ALTER TABLE imap_emails
    ADD COLUMN netflix_otp_4digit TINYINT(1) NOT NULL DEFAULT 0 AFTER app_id,
    ADD COLUMN netflix_otp_6digit TINYINT(1) NOT NULL DEFAULT 0 AFTER netflix_otp_4digit;

-- =========================================================================
-- Migration (ต.ค. 2026): รหัสผ่านลูกค้า 4 หลัก + เปิด/ปิดรหัส Netflix 6 หลัก + กันสุ่มรหัส
-- ปกติ "ไม่ต้องรันเอง" — config_store.php เพิ่มให้อัตโนมัติตอนเปิดหน้าเว็บครั้งแรก
-- ถ้าโฮสต์ไม่ให้สิทธิ์ ALTER TABLE ค่อยวางคำสั่งนี้ใน phpMyAdmin > SQL เอง
-- =========================================================================
ALTER TABLE imap_emails
    ADD COLUMN access_pin VARCHAR(10) NULL AFTER app_id,
    ADD COLUMN netflix_6digit_allowed TINYINT(1) NOT NULL DEFAULT 1 AFTER netflix_otp_6digit;

CREATE TABLE IF NOT EXISTS pin_attempts (
    attempt_key  VARCHAR(191) NOT NULL PRIMARY KEY,
    fails        INT NOT NULL DEFAULT 0,
    locked_until INT NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
