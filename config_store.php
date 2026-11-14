<?php
// =========================================================================
// config_store.php — ชั้นเก็บข้อมูลตั้งค่าระบบผ่าน MySQL แทนไฟล์ config.json เดิม
//
// load_config()/save_config($config) คืนค่า/รับค่าเป็น array รูปร่างเดียวกับที่
// config.json เคยให้ทุกประการ (shop_name, apps[], imap_emails[], api_providers[] ฯลฯ)
// เพื่อให้ admin.php, get_otp.php, index.php, ms_callback.php ไม่ต้องแก้ตรรกะอื่นเลย
// นอกจากจุดอ่าน/เขียนไฟล์เดิม
//
// รหัสผ่านฐานข้อมูลจริงอยู่ใน db_credentials.php ซึ่ง "ไม่ track ใน git" (ดู .gitignore)
// ต้องอัปโหลดไฟล์นั้นเองครั้งเดียวผ่าน Hostinger File Manager (ดู db_credentials.example.php)
// =========================================================================

// โหมดทดลองรันในเครื่อง (local_test/run_local.bat ตั้ง OTP_LOCAL=1 ให้) — ไม่ใช้ MySQL เก็บข้อมูลเป็นไฟล์ JSON แทน
define('OTP_LOCAL_MODE', (bool)getenv('OTP_LOCAL') && file_exists(__DIR__ . '/local_test/local_store.php'));
if (OTP_LOCAL_MODE) {
    require_once __DIR__ . '/local_test/local_store.php';
}

// ลำดับการอ่านค่าฐานข้อมูล:
//  1) Environment Variables (ใช้บน Render: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, DB_SSL)
//  2) ไฟล์ db_credentials.php (ใช้บนโฮสต์แบบเดิม เช่น Hostinger)
if (getenv('DB_HOST')) {
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST'));
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'otp');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: '');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
}
$__db_credentials_file = __DIR__ . '/db_credentials.php';
if (!defined('DB_HOST') && file_exists($__db_credentials_file)) {
    require_once $__db_credentials_file;
}
// ค่า default เผื่อยังไม่ได้ตั้งค่า
if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'u458279693_pspapp_db');
if (!defined('DB_USER')) define('DB_USER', 'u458279693_admin01');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
// TiDB Cloud บังคับเชื่อมต่อแบบเข้ารหัส (SSL) — ตั้ง DB_SSL=1
if (!defined('DB_SSL')) define('DB_SSL', (bool)getenv('DB_SSL'));

function db_connect() {
    if (OTP_LOCAL_MODE) return local_db_connect();
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ];
        if (DB_SSL) {
            foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $ca) {
                if (file_exists($ca)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                    break;
                }
            }
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $options
        );
    }
    return $pdo;
}

// เพิ่มคอลัมน์/ตารางใหม่ให้อัตโนมัติ (ไม่ต้องเข้า phpMyAdmin เอง) — รันครั้งเดียวต่อคำขอ เช็คเร็วมาก
//   imap_emails.access_pin             = รหัสผ่าน 4 หลักที่แอดมินตั้งให้อีเมลนี้ (ลูกค้าต้องกรอกก่อนกดขอรหัส OTP)
//   imap_emails.netflix_6digit_allowed = เปิด/ปิด ให้ลูกค้าเห็นรหัส Netflix 6 หลักของอีเมลนี้ (1=เปิด 0=ปิด)
//   pin_attempts                        = นับจำนวนครั้งที่กรอกรหัสผิด กันการสุ่มเดารหัส 4 หลัก
function ensure_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    // ฐานข้อมูลใหม่ที่ยังไม่มีตาราง (เช่น TiDB Cloud ที่เพิ่งสร้าง) -> สร้างตาราง + ข้อมูลเริ่มต้นจาก tidb_setup.sql ให้เอง
    try {
        $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $required = ['settings', 'apps', 'imap_emails', 'api_providers', 'gmail_central_accounts', 'maily_central_accounts', 'pin_attempts'];
        if (array_diff($required, $existing)) {
            bootstrap_schema($pdo); // ทุกคำสั่งเป็น IF NOT EXISTS / INSERT IGNORE รันซ้ำได้ปลอดภัย
        }
        // เปลี่ยนชื่อร้านจากค่าเริ่มต้นเดิม 'Monali Premium' เป็น 'Monalishop' (เฉพาะกรณียังไม่เคยแก้ชื่อเองในแอดมิน)
        $pdo->exec("UPDATE settings SET shop_name = 'Monalishop' WHERE id = 1 AND shop_name = 'Monali Premium'");
    } catch (Throwable $e) {
        error_log('bootstrap_schema failed: ' . $e->getMessage());
    }
    try {
        if (!$pdo->query("SHOW COLUMNS FROM imap_emails LIKE 'access_pin'")->fetch()) {
            $pdo->exec("ALTER TABLE imap_emails ADD COLUMN access_pin VARCHAR(10) NULL AFTER app_id");
        }
        if (!$pdo->query("SHOW COLUMNS FROM imap_emails LIKE 'netflix_6digit_allowed'")->fetch()) {
            $pdo->exec("ALTER TABLE imap_emails ADD COLUMN netflix_6digit_allowed TINYINT(1) NOT NULL DEFAULT 1 AFTER netflix_otp_6digit");
        }
        // โลโก้ที่อัปโหลดเก็บในฐานข้อมูลเป็น data URI (Render ฟรีลบไฟล์ที่อัปโหลดทิ้งทุกครั้งที่รีสตาร์ต) -> ต้องขยายคอลัมน์
        foreach ([['apps', 'logo_path', 'MEDIUMTEXT NULL'], ['settings', 'logo_path', 'MEDIUMTEXT NULL']] as [$t, $c, $def]) {
            $col = $pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch();
            if ($col && stripos($col['Type'], 'varchar') !== false) {
                $pdo->exec("ALTER TABLE `$t` MODIFY `$c` $def");
            }
        }
        // เพลงพื้นหลัง (ลิงก์ YouTube) + ตัวโหลดตอนค้นหารหัส (รูป/GIF เก็บเป็น data URI) + ข้อความใต้ตัวโหลด
        foreach ([['bg_music_url', 'VARCHAR(500) NULL'], ['loader_image', 'MEDIUMTEXT NULL'], ['loader_text', 'VARCHAR(255) NULL']] as [$c, $def]) {
            if (!$pdo->query("SHOW COLUMNS FROM settings LIKE '$c'")->fetch()) {
                $pdo->exec("ALTER TABLE settings ADD COLUMN `$c` $def");
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS pin_attempts (
            attempt_key  VARCHAR(191) NOT NULL PRIMARY KEY,
            fails        INT NOT NULL DEFAULT 0,
            locked_until INT NOT NULL DEFAULT 0,
            updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ensure_schema failed: ' . $e->getMessage());
    }
}

// รันไฟล์ tidb_setup.sql ทีละคำสั่ง (ข้าม CREATE DATABASE / USE เพราะเชื่อมต่อเข้าฐานข้อมูลอยู่แล้ว)
function bootstrap_schema($pdo) {
    $file = __DIR__ . '/tidb_setup.sql';
    if (!file_exists($file)) return;
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $stmt)) continue;
        $pdo->exec($stmt);
    }
}

// เช็คว่าคอลัมน์ใหม่มีอยู่จริงในฐานข้อมูลหรือยัง (กันบันทึกพังกรณีโฮสต์ไม่ให้สิทธิ์ ALTER TABLE)
function imap_column_exists($pdo, $col) {
    static $cache = [];
    if (!array_key_exists($col, $cache)) {
        try {
            $cache[$col] = (bool)$pdo->query("SHOW COLUMNS FROM imap_emails LIKE " . $pdo->quote($col))->fetch();
        } catch (Throwable $e) {
            $cache[$col] = false;
        }
    }
    return $cache[$col];
}

function settings_column_exists($pdo, $col) {
    static $cache = [];
    if (!array_key_exists($col, $cache)) {
        try {
            $cache[$col] = (bool)$pdo->query("SHOW COLUMNS FROM settings LIKE " . $pdo->quote($col))->fetch();
        } catch (Throwable $e) {
            $cache[$col] = false;
        }
    }
    return $cache[$col];
}

// ดึงรหัสวิดีโอ/เพลย์ลิสต์ จากลิงก์ YouTube ทุกรูปแบบ (watch?v=, youtu.be/, shorts/, embed/, music.youtube.com, &list=)
function parse_youtube_url($url) {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) return ['video' => $url, 'list' => '', 'start' => 0];
    $parts = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://' . $url);
    $host = strtolower($parts['host'] ?? '');
    if (!preg_match('/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host)) return null;
    parse_str($parts['query'] ?? '', $q);
    $video = '';
    if ($host === 'youtu.be' || substr($host, -9) === '.youtu.be') {
        $video = trim($parts['path'] ?? '', '/');
    } elseif (!empty($q['v'])) {
        $video = $q['v'];
    } elseif (preg_match('#/(?:shorts|embed|live|v)/([A-Za-z0-9_-]{11})#', $parts['path'] ?? '', $m)) {
        $video = $m[1];
    }
    $video = preg_match('/^[A-Za-z0-9_-]{11}/', $video, $m) ? $m[0] : '';
    $list = (!empty($q['list']) && preg_match('/^[A-Za-z0-9_-]+$/', $q['list'])) ? $q['list'] : '';
    if ($video === '' && $list === '') return null;
    $start = 0;
    if (!empty($q['t']) || !empty($q['start'])) {
        $t = (string)($q['t'] ?? $q['start']);
        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s?)?$/', $t, $tm)) {
            $start = (int)($tm[1] ?? 0) * 3600 + (int)($tm[2] ?? 0) * 60 + (int)($tm[3] ?? 0);
        }
    }
    return ['video' => $video, 'list' => $list, 'start' => $start];
}

function load_config() {
    if (OTP_LOCAL_MODE) return local_load_config();
    $pdo = db_connect();
    ensure_schema($pdo);

    $settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
    if (!$settings) {
        $settings = [
            'shop_name' => 'gooddaypremium', 'logo_path' => '/static/logo.jpg',
            'disney_logo_path' => '', 'trueid_logo_path' => '',
            'admin_username' => 'admin', 'admin_password' => 'admin1234',
            'api_key_1' => '', 'api_key_2' => '', 'ms_client_id' => '', 'ms_client_secret' => '',
        ];
    }

    $config = [
        'shop_name'        => $settings['shop_name'],
        'logo_path'        => $settings['logo_path'],
        'disney_logo_path' => $settings['disney_logo_path'],
        'trueid_logo_path' => $settings['trueid_logo_path'],
        'admin_username'   => $settings['admin_username'],
        'admin_password'   => $settings['admin_password'],
        'api_key_1'        => $settings['api_key_1'],
        'api_key_2'        => $settings['api_key_2'],
        'bg_music_url'     => (string)($settings['bg_music_url'] ?? ''),
        'loader_image'     => (string)($settings['loader_image'] ?? ''),
        'loader_text'      => (string)($settings['loader_text'] ?? ''),
    ];

    if (!empty($settings['ms_client_id']) || !empty($settings['ms_client_secret'])) {
        $config['microsoft_oauth'] = [
            'client_id'     => $settings['ms_client_id'],
            'client_secret' => $settings['ms_client_secret'],
        ];
    }

    $apps = $pdo->query("SELECT * FROM apps ORDER BY sort_order, created_at")->fetchAll();
    $config['apps'] = array_map(function ($row) {
        return [
            'id'            => $row['id'],
            'name'          => $row['name'],
            'code'          => $row['code'],
            'logo_path'     => $row['logo_path'],
            'keywords'      => $row['keywords'],
            'bg_color'      => $row['bg_color'],
            'border_color'  => $row['border_color'],
            'active'        => (bool)$row['active'],
        ];
    }, $apps);

    $emails = $pdo->query("SELECT * FROM imap_emails ORDER BY created_at")->fetchAll();
    $config['imap_emails'] = array_map(function ($row) {
        $item = [
            'id'       => $row['id'],
            'email'    => $row['email'],
            'provider' => $row['provider'],
            'host'     => $row['host'],
            'port'     => $row['port'] !== null ? (int)$row['port'] : null,
            'password' => $row['password'],
            'app_id'   => $row['app_id'],
            'access_pin' => (string)($row['access_pin'] ?? ''),
            'netflix_6digit_allowed' => array_key_exists('netflix_6digit_allowed', $row) ? (bool)$row['netflix_6digit_allowed'] : true,
            'netflix_otp_4digit' => (bool)($row['netflix_otp_4digit'] ?? false),
            'netflix_otp_6digit' => (bool)($row['netflix_otp_6digit'] ?? false),
            'active'   => (bool)$row['active'],
        ];
        if (!empty($row['refresh_token'])) {
            $item['refresh_token'] = $row['refresh_token'];
        }
        return $item;
    }, $emails);

    $providers = $pdo->query("SELECT * FROM api_providers ORDER BY id")->fetchAll();
    $config['api_providers'] = array_map(function ($row) {
        $item = [
            'id'     => $row['id'],
            'name'   => $row['name'],
            'type'   => $row['type'],
            'active' => (bool)$row['active'],
        ];
        if ($row['type'] === 'cloud_run') {
            $item['url'] = $row['url'];
        } else {
            $item['token'] = $row['token'];
        }
        return $item;
    }, $providers);

    $config['gmail_central_accounts'] = $pdo
        ->query("SELECT email AS user, password AS pass FROM gmail_central_accounts ORDER BY id")
        ->fetchAll();

    $config['maily_central_accounts'] = $pdo
        ->query("SELECT email FROM maily_central_accounts ORDER BY id")
        ->fetchAll(PDO::FETCH_COLUMN);

    return $config;
}

// sync ตารางลูก (apps / imap_emails / api_providers) ให้ตรงกับ array ที่ส่งมาทั้งชุด
// (upsert ทุกแถวที่มี id ตรงกัน + ลบแถวที่ไม่มีใน array แล้ว)
function __config_store_sync_table($pdo, $table, $rows, $mapFn) {
    $keepIds = [];
    $i = 0;
    foreach ($rows as $row) {
        $data = $mapFn($row, $i++);
        $keepIds[] = $data['id'];
        $cols = array_keys($data);
        $placeholders = array_map(function ($c) { return ':' . $c; }, $cols);
        $updates = array_map(function ($c) { return "`$c` = VALUES(`$c`)"; }, $cols);
        $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")
                ON DUPLICATE KEY UPDATE " . implode(',', $updates);
        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        $stmt->execute($params);
    }
    if (!empty($keepIds)) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id NOT IN ($placeholders)");
        $stmt->execute($keepIds);
    } else {
        $pdo->exec("DELETE FROM `$table`");
    }
}

function save_config($config) {
    if (OTP_LOCAL_MODE) return local_save_config($config);
    $pdo = db_connect();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO settings (id, shop_name, logo_path, disney_logo_path, trueid_logo_path, admin_username, admin_password, api_key_1, api_key_2, ms_client_id, ms_client_secret)
            VALUES (1, :shop_name, :logo_path, :disney_logo_path, :trueid_logo_path, :admin_username, :admin_password, :api_key_1, :api_key_2, :ms_client_id, :ms_client_secret)
            ON DUPLICATE KEY UPDATE
                shop_name = VALUES(shop_name), logo_path = VALUES(logo_path),
                disney_logo_path = VALUES(disney_logo_path), trueid_logo_path = VALUES(trueid_logo_path),
                admin_username = VALUES(admin_username), admin_password = VALUES(admin_password),
                api_key_1 = VALUES(api_key_1), api_key_2 = VALUES(api_key_2),
                ms_client_id = VALUES(ms_client_id), ms_client_secret = VALUES(ms_client_secret)
        ");
        $stmt->execute([
            ':shop_name'        => $config['shop_name'] ?? 'gooddaypremium',
            ':logo_path'        => $config['logo_path'] ?? '/static/logo.jpg',
            ':disney_logo_path' => $config['disney_logo_path'] ?? '',
            ':trueid_logo_path' => $config['trueid_logo_path'] ?? '',
            ':admin_username'   => $config['admin_username'] ?? 'admin',
            ':admin_password'   => $config['admin_password'] ?? 'admin1234',
            ':api_key_1'        => $config['api_key_1'] ?? null,
            ':api_key_2'        => $config['api_key_2'] ?? null,
            ':ms_client_id'     => $config['microsoft_oauth']['client_id'] ?? null,
            ':ms_client_secret' => $config['microsoft_oauth']['client_secret'] ?? null,
        ]);

        // เพลงพื้นหลัง / ตัวโหลด — แยกอัปเดต และข้ามคอลัมน์ที่ยังไม่มี (กรณีโฮสต์ไม่ให้สิทธิ์ ALTER TABLE)
        foreach (['bg_music_url', 'loader_image', 'loader_text'] as $c) {
            if (settings_column_exists($pdo, $c)) {
                $v = isset($config[$c]) && $config[$c] !== '' ? (string)$config[$c] : null;
                $pdo->prepare("UPDATE settings SET `$c` = ? WHERE id = 1")->execute([$v]);
            }
        }

        __config_store_sync_table($pdo, 'apps', $config['apps'] ?? [], function ($row, $i) {
            return [
                'id'           => $row['id'],
                'name'         => $row['name'],
                'code'         => $row['code'] ?? '',
                'logo_path'    => $row['logo_path'] ?? null,
                'keywords'     => $row['keywords'] ?? null,
                'bg_color'     => $row['bg_color'] ?? null,
                'border_color' => $row['border_color'] ?? null,
                'sort_order'   => $i,
                'active'       => !empty($row['active']) ? 1 : 0,
            ];
        });

        $has_pin_col = imap_column_exists($pdo, 'access_pin');
        $has_nf6_col = imap_column_exists($pdo, 'netflix_6digit_allowed');
        __config_store_sync_table($pdo, 'imap_emails', $config['imap_emails'] ?? [], function ($row, $i) use ($has_pin_col, $has_nf6_col) {
            $data = [
                'id'            => $row['id'],
                'email'         => $row['email'],
                'provider'      => $row['provider'] ?? 'gmail',
                'host'          => $row['host'] ?? null,
                'port'          => $row['port'] ?? null,
                'password'      => $row['password'] ?? null,
                'refresh_token' => $row['refresh_token'] ?? null,
                'app_id'        => $row['app_id'] ?? 'all',
                'access_pin'    => (isset($row['access_pin']) && $row['access_pin'] !== '') ? (string)$row['access_pin'] : null,
                'netflix_6digit_allowed' => (!isset($row['netflix_6digit_allowed']) || !empty($row['netflix_6digit_allowed'])) ? 1 : 0,
                'netflix_otp_4digit' => !empty($row['netflix_otp_4digit']) ? 1 : 0,
                'netflix_otp_6digit' => !empty($row['netflix_otp_6digit']) ? 1 : 0,
                'active'        => !empty($row['active']) ? 1 : 0,
            ];
            if (!$has_pin_col) unset($data['access_pin']);
            if (!$has_nf6_col) unset($data['netflix_6digit_allowed']);
            return $data;
        });

        __config_store_sync_table($pdo, 'api_providers', $config['api_providers'] ?? [], function ($row, $i) {
            return [
                'id'     => $row['id'],
                'name'   => $row['name'],
                'type'   => $row['type'] ?? 'maily',
                'token'  => $row['token'] ?? null,
                'url'    => $row['url'] ?? null,
                'active' => !empty($row['active']) ? 1 : 0,
            ];
        });

        // gmail_central_accounts / maily_central_accounts ไม่มี id คงที่ใน config เดิม -> ล้างแล้วใส่ใหม่ทั้งชุด
        $pdo->exec("DELETE FROM gmail_central_accounts");
        $insGmail = $pdo->prepare("INSERT INTO gmail_central_accounts (email, password) VALUES (:email, :password)");
        foreach (($config['gmail_central_accounts'] ?? []) as $acc) {
            $insGmail->execute([':email' => $acc['user'], ':password' => $acc['pass']]);
        }

        $pdo->exec("DELETE FROM maily_central_accounts");
        $insMaily = $pdo->prepare("INSERT INTO maily_central_accounts (email) VALUES (:email)");
        foreach (($config['maily_central_accounts'] ?? []) as $email) {
            $insMaily->execute([':email' => $email]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('save_config failed: ' . $e->getMessage());
        return false;
    }
}

// แปลข้อผิดพลาดจาก Microsoft (ตอนขอ token / อ่านกล่องเมล) เป็นภาษาไทยพร้อมวิธีแก้
// คืน ['kind' => 'reconnect'|'secret'|'mailbox'|'other', 'message' => '...']
function ms_graph_explain_error($stage, $http_code, $body) {
    $body = (string)$body;
    $j = json_decode($body, true);
    $code = is_array($j) ? (string)($j['error']['code'] ?? $j['error'] ?? '') : '';
    $desc = is_array($j) ? (string)($j['error_description'] ?? $j['error']['message'] ?? '') : '';
    if ($http_code === 0) {
        return ['kind' => 'other', 'message' => 'เซิร์ฟเวอร์ติดต่อ Microsoft ไม่ได้ (เครือข่ายขัดข้อง/หมดเวลา) ลองใหม่อีกครั้ง'];
    }
    if ($stage === 'token') {
        if ($code === 'invalid_client' || preg_match('/AADSTS(7000215|7000222|700016|7000218)/', $desc)) {
            return ['kind' => 'secret', 'message' => 'Client Secret / Client ID ไม่ถูกต้องหรือหมดอายุ → เข้า Azure Portal สร้าง Client Secret ใหม่ แล้วใส่ในแท็บ "ตั้งค่า API" (ช่อง Microsoft App) — ใช้ "Value" ไม่ใช่ "Secret ID"'];
        }
        if ($code === 'invalid_grant' || preg_match('/AADSTS(70000|700082|50173|50076|50079|65001|70008)/', $desc)) {
            return ['kind' => 'reconnect', 'message' => 'การเชื่อมต่อของบัญชีนี้หมดอายุ/ถูกยกเลิก (เช่น เปลี่ยนรหัสผ่าน Microsoft, ไม่ได้ใช้นาน หรือยกเลิกสิทธิ์แอป) → กดปุ่ม "เชื่อมต่อ Hotmail" แล้วล็อกอินบัญชีนี้ใหม่ (รหัส 4 หลักเดิมยังอยู่)'];
        }
    } else {
        if (stripos($body, 'MailboxNotEnabledForRESTAPI') !== false || stripos($body, 'MailboxNotHostedInExchangeOnline') !== false) {
            return ['kind' => 'mailbox', 'message' => 'กล่องเมลของบัญชีนี้ยังไม่เปิดใช้งาน → ล็อกอินบัญชีนี้ที่ outlook.live.com 1 ครั้ง (เปิดกล่องเมลให้ขึ้น) แล้วลองใหม่'];
        }
        if ($http_code === 401 || $http_code === 403) {
            return ['kind' => 'reconnect', 'message' => 'บัญชีนี้ไม่ได้ให้สิทธิ์อ่านอีเมล → กด "เชื่อมต่อ Hotmail" ใหม่ และกดยอมรับสิทธิ์ทั้งหมด'];
        }
        if ($http_code === 429) {
            return ['kind' => 'other', 'message' => 'Microsoft จำกัดการเรียกใช้ชั่วคราว (ขอถี่เกินไป) รอสักครู่แล้วลองใหม่'];
        }
    }
    return ['kind' => 'other', 'message' => 'Microsoft ตอบกลับผิดพลาด (HTTP ' . $http_code . ($code !== '' ? ', ' . $code : '') . ')' . ($desc !== '' ? ': ' . mb_substr($desc, 0, 200) : '')];
}

// ขอ access token ใหม่จาก refresh token ของบัญชี Hotmail/Outlook
// คืน ['ok' => bool, 'access_token' => ..., 'refresh_token' => (ตัวใหม่ถ้ามี), 'error' => ms_graph_explain_error(...)]
function ms_graph_refresh($client_id, $client_secret, $refresh_token) {
    $ch = curl_init('https://login.microsoftonline.com/consumers/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
            'scope'         => 'offline_access Mail.Read',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($http !== 200 || empty($data['access_token'])) {
        return ['ok' => false, 'error' => ms_graph_explain_error('token', $http, $res), 'raw' => substr((string)$res, 0, 300)];
    }
    return ['ok' => true, 'access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'] ?? ''];
}

// อ่านอีเมลล่าสุดของบัญชี (ทุกโฟลเดอร์ รวมจดหมายขยะ) — คืน ['ok' => bool, 'messages' => [...], 'error' => ...]
function ms_graph_list_messages($access_token, $top = 15, $with_body = true) {
    $query = http_build_query([
        '$top'     => $top,
        '$orderby' => 'receivedDateTime desc',
        '$select'  => 'subject,from,receivedDateTime,parentFolderId' . ($with_body ? ',body' : ''),
    ]);
    $ch = curl_init('https://graph.microsoft.com/v1.0/me/messages?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access_token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$res, true);
    if ($http !== 200 || !is_array($data)) {
        return ['ok' => false, 'error' => ms_graph_explain_error('mail', $http, $res), 'raw' => substr((string)$res, 0, 300)];
    }
    return ['ok' => true, 'messages' => $data['value'] ?? []];
}

// บันทึก refresh token ตัวใหม่ (Microsoft ออกใหม่ทุกครั้งที่ใช้) โดยอัปเดตเฉพาะแถวนั้น
// ไม่ save_config ทั้งก้อน — กันไปทับข้อมูลที่แอดมินเพิ่งแก้ในคำขออื่นพร้อมกัน
function ms_graph_store_refresh_token(&$config_data, $email, $new_token) {
    if ($new_token === '') return;
    foreach (($config_data['imap_emails'] ?? []) as $idx => $item) {
        if (strtolower($item['email'] ?? '') === strtolower($email) && ($item['provider'] ?? '') === 'microsoft_graph') {
            if (($item['refresh_token'] ?? '') === $new_token) return;
            $config_data['imap_emails'][$idx]['refresh_token'] = $new_token;
            try {
                if (OTP_LOCAL_MODE) { local_save_config($config_data); return; }
                db_connect()->prepare("UPDATE imap_emails SET refresh_token = ? WHERE id = ?")->execute([$new_token, $item['id']]);
            } catch (Throwable $e) {
                error_log('[OTP] store refresh token failed: ' . $e->getMessage());
            }
            return;
        }
    }
}
