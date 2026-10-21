# Monali OTP

เว็บกดรับรหัส OTP เอง รันฟรีด้วย **Render** (ตัวเว็บ) + **TiDB Cloud** (ฐานข้อมูล) + **cron-job.org** (กันเว็บหลับ)

## ขั้นตอนตั้งค่า (ทำครั้งเดียว)

### 1. ฐานข้อมูล — TiDB Cloud (ฟรี)
1. สมัครที่ https://tidbcloud.com แล้วสร้าง instance แบบ **Starter (Free)** เลือก region **Singapore**
2. เข้า instance > **SQL Editor** > วางไฟล์ `tidb_setup.sql` ทั้งหมด > Run
3. กดปุ่ม **Connect** จดค่า: `HOST`, `PORT` (ปกติ 4000), `USER` (เช่น `xxxx.root`) และกด Generate Password จดรหัสผ่าน

### 2. ตัวเว็บ — Render (ฟรี)
1. สมัคร https://render.com ด้วยบัญชี GitHub
2. **New + > Blueprint** > เลือก repo นี้ (Render จะอ่าน `render.yaml` ให้เอง)
3. กรอกค่าที่ถาม:
   - `DB_HOST` = HOST จาก TiDB
   - `DB_USER` = USER จาก TiDB
   - `DB_PASS` = รหัสผ่านจาก TiDB
4. กด Apply รอ build ประมาณ 5–10 นาที จะได้ลิงก์เว็บ เช่น `https://monali-otp.onrender.com`

### 3. กันเว็บหลับ — cron-job.org (ฟรี)
Render ฟรีจะหลับถ้าไม่มีคนเข้า 15 นาที (ปลุกใช้เวลา ~1 นาที)
1. สมัคร https://cron-job.org
2. Create cronjob > URL = `https://<ชื่อเว็บ>.onrender.com/ping.php` > ทุก 10 นาที

### 4. เริ่มใช้งาน
- หน้าแอดมิน: `/admin.php` เข้าด้วย `admin` / `admin1234` แล้ว **เปลี่ยนรหัสทันที** (แท็บ ตั้งค่า API)
- เพิ่มอีเมลลูกค้า + ตั้งรหัสผ่าน 4 หลัก ในแท็บ อีเมล IMAP

## ย้ายข้อมูลจาก Hostinger (ถ้ามี)
phpMyAdmin บน Hostinger > Export > เลือกตาราง `apps`, `imap_emails`, `api_providers`, `gmail_central_accounts`, `maily_central_accounts`, `settings`
> Custom > **Data only** > Export แล้วนำไฟล์ไปรันใน TiDB SQL Editor (หลังจากรัน `tidb_setup.sql` แล้ว — ถ้าชนกับข้อมูลตัวอย่างให้ลบตาราง `apps`/`settings` ตัวอย่างก่อน)

## ข้อจำกัดของแพ็กเกจฟรี
- Render ฟรีมี 750 ชั่วโมง/เดือน พอสำหรับ 1 เว็บเปิดตลอด — อย่าเปิดเว็บฟรีอื่นในบัญชีเดียวกัน
- ไฟล์ในเครื่องเซิร์ฟเวอร์หายทุกครั้งที่รีสตาร์ต → โลโก้ที่อัปโหลดผ่านแอดมินถูกเก็บในฐานข้อมูลแทน (ไม่เกิน 400KB ต่อรูป)
- เชื่อม Hotmail ผ่าน Microsoft: ตั้ง Redirect URI ใน Azure เป็น `https://<ชื่อเว็บ>.onrender.com/ms_callback.php`

## ทดลองในเครื่อง (ไม่ต้องมีฐานข้อมูล)
ดับเบิลคลิก `local_test/run_local.bat` (ต้องมี PHP ในเครื่อง)
