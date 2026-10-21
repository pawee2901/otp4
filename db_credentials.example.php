<?php
// =========================================================================
// ตัวอย่างไฟล์ db_credentials.php — คัดลอกไฟล์นี้ไปตั้งชื่อใหม่เป็น "db_credentials.php"
// แล้วใส่ค่าจริงจากหน้า Hostinger > Databases > Management
//
// ไฟล์ db_credentials.php (ตัวจริง) ต้องอัปโหลดเองผ่าน Hostinger File Manager เท่านั้น
// ห้าม commit ขึ้น git เด็ดขาด (อยู่ใน .gitignore แล้ว) เพราะมีรหัสผ่านฐานข้อมูลจริง
// และไฟล์นี้จะไม่ถูกลบ/ทับเวลา auto-deploy จาก GitHub เพราะ git ไม่รู้จักไฟล์นี้เลย
// =========================================================================

define('DB_HOST', '127.0.0.1');                      // ปกติใช้ 127.0.0.1 หรือ localhost (เพราะ PHP กับ MySQL อยู่บนโฮสต์เดียวกัน)
define('DB_NAME', 'u458279693_pspapp_db');            // ชื่อฐานข้อมูลเต็ม (มี prefix u458279693_ นำหน้าแล้ว)
define('DB_USER', 'u458279693_admin01');              // ชื่อผู้ใช้เต็ม (มี prefix นำหน้าแล้ว)
define('DB_PASS', 'REPLACE_WITH_YOUR_REAL_DB_PASSWORD');
