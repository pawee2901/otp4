<?php
// =========================================================================
// ที่เก็บข้อมูลสำหรับ "ทดลองรันในเครื่อง" เท่านั้น (ไม่ต้องมี MySQL)
// ใช้งานเมื่อรันผ่าน local_test/run_local.bat (ตั้ง OTP_LOCAL=1) — บนเว็บจริงไฟล์นี้ไม่ถูกเรียกใช้
// ข้อมูลเก็บที่ local_test/config.local.json (ลบไฟล์นี้ = รีเซ็ตกลับเป็นข้อมูลตัวอย่าง)
// =========================================================================

define('LOCAL_STORE_FILE', __DIR__ . '/config.local.json');

function local_seed_config() {
    return [
        'shop_name'        => 'Monalishop',
        'logo_path'        => '/static/monali_logo.jpg',
        'disney_logo_path' => '',
        'trueid_logo_path' => '',
        'admin_username'   => 'admin',
        'admin_password'   => '1234',
        'api_key_1'        => '',
        'api_key_2'        => '',
        'apps' => [
            ['id' => 'app_prime',   'name' => 'Prime Video', 'code' => 'PR',  'logo_path' => '/static/app_tile_prime.png',   'keywords' => 'prime',   'bg_color' => '', 'border_color' => '', 'active' => true],
            ['id' => 'app_netflix', 'name' => 'Netflix',     'code' => 'NF',  'logo_path' => '/static/app_tile_netflix.png', 'keywords' => 'netflix', 'bg_color' => '', 'border_color' => '', 'active' => true],
            ['id' => 'app_disney',  'name' => 'Disney+',     'code' => 'DN',  'logo_path' => '/static/app_tile_disney.png',  'keywords' => 'disney',  'bg_color' => '', 'border_color' => '', 'active' => true],
            ['id' => 'app_chatgpt', 'name' => 'ChatGPT',     'code' => 'GPT', 'logo_path' => '/static/app_tile_chatgpt.png', 'keywords' => 'gpt',     'bg_color' => '', 'border_color' => '', 'active' => true],
            ['id' => 'app_trueid',  'name' => 'TrueID',      'code' => 'TM',  'logo_path' => '/static/app_tile_trueid.png',  'keywords' => 'true',    'bg_color' => '', 'border_color' => '', 'active' => true],
        ],
        'imap_emails' => [
            ['id' => 'demo_1', 'email' => 'netflix@demo.local', 'provider' => 'gmail', 'host' => 'imap.gmail.com', 'port' => 993, 'password' => '', 'app_id' => 'app_netflix', 'access_pin' => '1234', 'netflix_6digit_allowed' => true, 'netflix_otp_4digit' => false, 'netflix_otp_6digit' => false, 'active' => true],
            ['id' => 'demo_2', 'email' => 'disney@demo.local',  'provider' => 'gmail', 'host' => 'imap.gmail.com', 'port' => 993, 'password' => '', 'app_id' => 'app_disney',  'access_pin' => '5678', 'netflix_6digit_allowed' => true, 'netflix_otp_4digit' => false, 'netflix_otp_6digit' => false, 'active' => true],
            ['id' => 'demo_3', 'email' => 'nopin@demo.local',   'provider' => 'gmail', 'host' => 'imap.gmail.com', 'port' => 993, 'password' => '', 'app_id' => 'all',         'access_pin' => '',     'netflix_6digit_allowed' => true, 'netflix_otp_4digit' => false, 'netflix_otp_6digit' => false, 'active' => true],
        ],
        'api_providers'          => [],
        'gmail_central_accounts' => [],
        'maily_central_accounts' => [],
    ];
}

function local_load_config() {
    if (!file_exists(LOCAL_STORE_FILE)) {
        local_save_config(local_seed_config());
    }
    $data = json_decode((string)file_get_contents(LOCAL_STORE_FILE), true);
    return is_array($data) ? $data : local_seed_config();
}

function local_save_config($config) {
    return file_put_contents(LOCAL_STORE_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

// โหมดทดลองไม่มีฐานข้อมูล — ตัวนับรหัสผิด/ล็อก 15 นาทีจะไม่ทำงาน (บนเว็บจริงทำงานปกติ)
function local_db_connect() {
    throw new RuntimeException('local mode: no database');
}
