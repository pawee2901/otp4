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
