<?php
session_start();

// การตั้งค่าทั้งหมดเก็บใน MySQL ผ่าน config_store.php (เดิมเก็บเป็นไฟล์ config.json)
require_once __DIR__ . '/config_store.php';
$config_path = __DIR__ . '/config.json'; // เก็บชื่อตัวแปรไว้เผื่อโค้ดจุดอื่นอ้างอิง (ไม่ได้ใช้อ่าน/เขียนไฟล์แล้ว)

try {
    $config = load_config();
} catch (Throwable $e) {
    http_response_code(500);
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบไฟล์ db_credentials.php บนโฮสต์: ' . htmlspecialchars($e->getMessage()));
}

// แปลงรูปที่อัปโหลดเป็น data URI เก็บในฐานข้อมูล (โฮสต์ฟรีอย่าง Render ลบไฟล์ที่อัปโหลดทิ้งทุกครั้งที่รีสตาร์ต)
function uploaded_image_to_data_uri($tmp_path, $ext) {
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'][$ext] ?? null;
    if (!$mime || !is_uploaded_file($tmp_path)) return null;
    if (filesize($tmp_path) > 400 * 1024) return null; // จำกัด 400KB ต่อรูป
    $data = file_get_contents($tmp_path);
    return $data === false ? null : 'data:' . $mime . ';base64,' . base64_encode($data);
}

// เช็คความปลอดภัยการล็อกอิน
// คุกกี้ล็อกอินแบบเซ็นลายเซ็น (อยู่รอดแม้เซิร์ฟเวอร์รีสตาร์ต — Render ฟรีลบ session ทุกครั้งที่รีสตาร์ต/อัปเดต)
// เปลี่ยนรหัสแอดมินเมื่อไหร่ คุกกี้เก่าใช้ไม่ได้ทันที
define('ADMIN_COOKIE', 'otp_admin');
define('ADMIN_COOKIE_DAYS', 30);
function admin_cookie_sig($config, $expires) {
    $secret = hash('sha256', ($config['admin_username'] ?? '') . '|' . ($config['admin_password'] ?? '') . '|' . DB_PASS . '|' . DB_HOST);
    return hash_hmac('sha256', 'admin|' . $expires, $secret);
}
function admin_set_cookie($config) {
    $expires = time() + ADMIN_COOKIE_DAYS * 86400;
    $secure = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(ADMIN_COOKIE, $expires . '.' . admin_cookie_sig($config, $expires), [
        'expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'
    ]);
}
function admin_cookie_valid($config) {
    $raw = $_COOKIE[ADMIN_COOKIE] ?? '';
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $raw, $m)) return false;
    if ((int)$m[1] < time()) return false;
    return hash_equals(admin_cookie_sig($config, (int)$m[1]), $m[2]);
}

$logged_in = (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) || admin_cookie_valid($config);
if ($logged_in) {
    $_SESSION['admin_logged_in'] = true;
}

// ==========================================
// ส่วนประมวลผลคำขอร้องแบบ AJAX (POST Asynchronous)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($action === 'login') {
        header('Content-Type: application/json');
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';

        if ($username === $config['admin_username'] && $password === $config['admin_password']) {
            $_SESSION['admin_logged_in'] = true;
            admin_set_cookie($config);
            echo json_encode(['success' => true, 'message' => 'เข้าสู่ระบบสำเร็จ']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
        }
        exit;
    }

    // สิทธิ์การเข้าถึงสำหรับ AJAX ทั่วไป
    if (!$logged_in) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
        exit;
    }

    // --- Action: อัปเดตข้อมูลความปลอดภัย/ชื่อร้าน ---
    if ($action === 'update') {
        header('Content-Type: application/json');
        $shop_name = isset($_POST['shop_name']) ? trim($_POST['shop_name']) : '';
        $new_username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $new_password = isset($_POST['password']) ? trim($_POST['password']) : '';

        if (empty($shop_name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อร้าน']);
            exit;
        }

        $config['shop_name'] = $shop_name;
        if (!empty($new_username)) $config['admin_username'] = $new_username;
        if (!empty($new_password)) $config['admin_password'] = $new_password;
        if (isset($_POST['api_key_1'])) $config['api_key_1'] = trim($_POST['api_key_1']);
        if (isset($_POST['api_key_2'])) $config['api_key_2'] = trim($_POST['api_key_2']);

        if (save_config($config)) {
            admin_set_cookie($config); // รหัสใหม่ -> ออกคุกกี้ใหม่ให้ ไม่ต้องล็อกอินซ้ำ
            echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลเรียบร้อยแล้ว']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกข้อมูลได้']);
        }
        exit;
    }

    // --- Action: เพิ่ม/แก้ไข อีเมล IMAP ---
    if ($action === 'add_imap_email') {
        header('Content-Type: application/json');
        $email_addr = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? trim($_POST['password']) : '';
        $provider = isset($_POST['provider']) ? trim($_POST['provider']) : 'gmail';
        $host = isset($_POST['host']) ? trim($_POST['host']) : '';
        $port = isset($_POST['port']) ? intval($_POST['port']) : 993;
        $app_id = isset($_POST['app_id']) ? trim($_POST['app_id']) : 'all';

        if (empty($email_addr)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณาระบุที่อยู่อีเมล']);
            exit;
        }

        // กำหนด host อัตโนมัติหากไม่ได้กรอก
        if (empty($host)) {
            if ($provider === 'gmail' || strpos(strtolower($email_addr), '@gmail') !== false) {
                $host = 'imap.gmail.com';
                $provider = 'gmail';
            } elseif ($provider === 'hotmail' || strpos(strtolower($email_addr), '@hotmail') !== false || strpos(strtolower($email_addr), '@outlook') !== false || strpos(strtolower($email_addr), '@live') !== false) {
                $host = 'outlook.office365.com';
                $provider = 'hotmail';
            } elseif ($provider === 'maily' || strpos(strtolower($email_addr), '@lico.moe') !== false || strpos(strtolower($email_addr), '@rdcw.plus') !== false || strpos(strtolower($email_addr), '@gooddaymail.com') !== false) {
                $host = 'api.maily.space';
                $provider = 'maily';
            } else {
                $host = 'imap.gmail.com';
            }
        }

        if (!isset($config['imap_emails']) || !is_array($config['imap_emails'])) {
            $config['imap_emails'] = [];
        }

        // เช็คว่ามีอยู่แล้วหรือไม่
        $found_index = -1;
        foreach ($config['imap_emails'] as $idx => $item) {
            if (strtolower($item['email']) === strtolower($email_addr)) {
                $found_index = $idx;
                break;
            }
        }

        $existing = $found_index >= 0 ? $config['imap_emails'][$found_index] : [];

        // รหัสผ่าน 4 หลักสำหรับลูกค้า (ว่าง = คงค่าเดิมไว้)
        $access_pin = isset($_POST['access_pin']) ? preg_replace('/\D/', '', $_POST['access_pin']) : '';
        if ($access_pin !== '' && strlen($access_pin) !== 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'รหัสผ่านลูกค้าต้องเป็นตัวเลข 4 หลักค่ะ']);
            exit;
        }

        if ($provider === 'maily') {
            $host = 'api.maily.space';
            $password = '';
        }
        // provider ต้องอยู่ในค่าที่ฐานข้อมูลรองรับ
        if (!in_array($provider, ['gmail', 'hotmail', 'maily', 'microsoft_graph'], true)) {
            $provider = 'gmail';
        }
        // ห้ามเขียนทับบัญชีที่เชื่อม Microsoft ไว้แล้ว (ไม่งั้น refresh_token หาย)
        if (($existing['provider'] ?? '') === 'microsoft_graph') {
            $provider = 'microsoft_graph';
            $host = $existing['host'] ?? $host;
        }

        $new_item = [
            'id' => $existing['id'] ?? ('email_' . time() . '_' . mt_rand(100, 999)),
            'email' => $email_addr,
            'provider' => $provider,
            'host' => $host,
            'port' => $port,
            'password' => !empty($password) ? $password : ($existing['password'] ?? ''),
            'app_id' => $app_id !== '' ? $app_id : ($existing['app_id'] ?? 'all'),
            'access_pin' => $access_pin !== '' ? $access_pin : ($existing['access_pin'] ?? ''),
            'netflix_6digit_allowed' => array_key_exists('netflix_6digit_allowed', $existing) ? !empty($existing['netflix_6digit_allowed']) : true,
            'netflix_otp_4digit' => !empty($existing['netflix_otp_4digit']),
            'netflix_otp_6digit' => !empty($existing['netflix_otp_6digit']),
            'active' => array_key_exists('active', $existing) ? (bool)$existing['active'] : true
        ];
        if (!empty($existing['refresh_token'])) {
            $new_item['refresh_token'] = $existing['refresh_token'];
        }

        if ($found_index >= 0) {
            $config['imap_emails'][$found_index] = $new_item;
        } else {
            $config['imap_emails'][] = $new_item;
        }

        save_config($config);
        echo json_encode(['success' => true, 'message' => 'บันทึกอีเมล IMAP เรียบร้อยแล้ว', 'data' => $config['imap_emails']]);
        exit;
    }

    // --- Action: ลบอีเมล IMAP ---
    if ($action === 'delete_imap_email') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';

        if (!empty($id) && isset($config['imap_emails']) && is_array($config['imap_emails'])) {
            $config['imap_emails'] = array_values(array_filter($config['imap_emails'], function($item) use ($id) {
                return $item['id'] !== $id && $item['email'] !== $id;
            }));
            save_config($config);
        }

        echo json_encode(['success' => true, 'message' => 'ลบอีเมลเรียบร้อยแล้ว', 'data' => $config['imap_emails']]);
        exit;
    }

    // --- Action: สลับสถานะเปิด-ปิด/ล็อกอีเมล IMAP ---
    if ($action === 'toggle_email_active') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $active = isset($_POST['active']) ? filter_var($_POST['active'], FILTER_VALIDATE_BOOLEAN) : false;

        if (!empty($id) && isset($config['imap_emails']) && is_array($config['imap_emails'])) {
            foreach ($config['imap_emails'] as $idx => $item) {
                if (($item['id'] ?? '') === $id || ($item['email'] ?? '') === $id) {
                    $config['imap_emails'][$idx]['active'] = $active;
                    break;
                }
            }
            save_config($config);
        }

        echo json_encode(['success' => true, 'message' => $active ? 'ปลดล็อกเปิดใช้งานอีเมลแล้ว' : 'ล็อกการใช้งานอีเมลเรียบร้อยแล้ว', 'data' => $config['imap_emails']]);
        exit;
    }

    // --- Action: เปิด/ปิดรับรหัส OTP ของ Netflix ความยาว 4 หรือ 6 หลัก ต่ออีเมล (สลับอิสระคนละปุ่ม) ---
    if ($action === 'toggle_netflix_otp_digit') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $digit = isset($_POST['digit']) ? intval($_POST['digit']) : 0;
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;

        if (($digit === 4 || $digit === 6) && !empty($id) && isset($config['imap_emails']) && is_array($config['imap_emails'])) {
            // ปุ่ม 4 = ให้ลองหารหัส 4 หลักก่อน, ปุ่ม 6 = เปิด/ปิด ให้ลูกค้าเห็นรหัส Netflix 6 หลัก
            $field = $digit === 4 ? 'netflix_otp_4digit' : 'netflix_6digit_allowed';
            foreach ($config['imap_emails'] as $idx => $item) {
                if (($item['id'] ?? '') === $id || ($item['email'] ?? '') === $id) {
                    $config['imap_emails'][$idx][$field] = $enabled;
                    break;
                }
            }
            save_config($config);
        }

        echo json_encode(['success' => true, 'message' => "ตั้งค่ารับรหัส OTP Netflix $digit หลัก: " . ($enabled ? 'เปิด' : 'ปิด') . " เรียบร้อยแล้ว", 'data' => $config['imap_emails']]);
        exit;
    }

    // --- Action: ตั้ง/ลบ รหัสผ่าน 4 หลักของลูกค้า สำหรับอีเมลนี้ ---
    if ($action === 'set_email_pin') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $pin = isset($_POST['pin']) ? preg_replace('/\D/', '', $_POST['pin']) : '';
        if ($pin !== '' && strlen($pin) !== 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'รหัสผ่านต้องเป็นตัวเลข 4 หลักค่ะ']);
            exit;
        }
        $ok = false;
        foreach (($config['imap_emails'] ?? []) as $idx => $item) {
            if (($item['id'] ?? '') === $id || ($item['email'] ?? '') === $id) {
                $config['imap_emails'][$idx]['access_pin'] = $pin;
                $ok = true;
                break;
            }
        }
        if ($ok && save_config($config)) {
            echo json_encode(['success' => true, 'message' => $pin === '' ? 'ลบรหัสผ่านแล้ว (อีเมลนี้จะขอรหัสไม่ได้จนกว่าจะตั้งใหม่)' : 'ตั้งรหัสผ่านเรียบร้อยแล้ว']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ไม่พบอีเมลนี้ หรือบันทึกไม่สำเร็จ']);
        }
        exit;
    }

    // --- Action: ตั้งรหัสผ่าน 4 หลักเดียวกันให้หลายอีเมลพร้อมกัน (ติ๊กเลือกในหน้าแอดมิน) ---
    if ($action === 'set_email_pin_bulk') {
        header('Content-Type: application/json');
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('strval', $_POST['ids']) : [];
        $pin = isset($_POST['pin']) ? preg_replace('/\D/', '', $_POST['pin']) : '';
        if (strlen($pin) !== 4 || empty($ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณาเลือกอีเมล และใส่รหัสตัวเลข 4 หลักค่ะ']);
            exit;
        }
        $n = 0;
        foreach (($config['imap_emails'] ?? []) as $idx => $item) {
            if (in_array((string)($item['id'] ?? ''), $ids, true)) {
                $config['imap_emails'][$idx]['access_pin'] = $pin;
                $n++;
            }
        }
        if ($n > 0 && save_config($config)) {
            echo json_encode(['success' => true, 'message' => "ตั้งรหัส $pin ให้ $n อีเมลเรียบร้อยแล้ว"]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'บันทึกไม่สำเร็จ']);
        }
        exit;
    }

    // --- Action: ผูกอีเมลกับแอป (ใช้แยกหมวดอีเมล เช่น ดูเฉพาะอีเมล Netflix) ---
    if ($action === 'set_email_app') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $app_id = isset($_POST['app_id']) ? trim($_POST['app_id']) : 'all';
        foreach (($config['imap_emails'] ?? []) as $idx => $item) {
            if (($item['id'] ?? '') === $id || ($item['email'] ?? '') === $id) {
                $config['imap_emails'][$idx]['app_id'] = $app_id !== '' ? $app_id : 'all';
                break;
            }
        }
        echo json_encode(['success' => (bool)save_config($config), 'message' => 'ย้ายหมวดอีเมลเรียบร้อยแล้ว']);
        exit;
    }

    // --- Action: เปิด/ปิด รหัส Netflix 6 หลัก ทุกอีเมลพร้อมกัน ---
    if ($action === 'bulk_netflix_6digit') {
        header('Content-Type: application/json');
        $enabled = isset($_POST['enabled']) ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN) : false;
        foreach (($config['imap_emails'] ?? []) as $idx => $item) {
            $config['imap_emails'][$idx]['netflix_6digit_allowed'] = $enabled;
        }
        echo json_encode(['success' => (bool)save_config($config), 'message' => 'รหัส Netflix 6 หลัก: ' . ($enabled ? 'เปิด' : 'ปิด') . ' ทุกอีเมลแล้ว']);
        exit;
    }

    // --- Action: เพิ่ม/แก้ไข แอปพลิเคชัน (OTP Apps) ---
    if ($action === 'save_app') {
        header('Content-Type: application/json');
        $app_id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $code = isset($_POST['code']) ? trim($_POST['code']) : '';
        $keywords = isset($_POST['keywords']) ? trim($_POST['keywords']) : '';
        $bg_color = isset($_POST['bg_color']) ? trim($_POST['bg_color']) : '#F0F7FF';
        $border_color = isset($_POST['border_color']) ? trim($_POST['border_color']) : '#D6E9FF';
        $logo_path = isset($_POST['logo_path']) ? trim($_POST['logo_path']) : '';

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณาระบุชื่อแอปพลิเคชัน']);
            exit;
        }

        if (!isset($config['apps']) || !is_array($config['apps'])) {
            $config['apps'] = [];
        }

        // จัดการอัปโหลดโลโก้ถ้ามีส่งมา
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
            if (in_array($ext, $allowed)) {
                $data_uri = uploaded_image_to_data_uri($file['tmp_name'], $ext);
                if ($data_uri === null) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'รูปโลโก้ต้องไม่เกิน 400KB ค่ะ']);
                    exit;
                }
                $logo_path = $data_uri;
            }
        }

        $found_index = -1;
        if (!empty($app_id)) {
            foreach ($config['apps'] as $idx => $item) {
                if ($item['id'] === $app_id) {
                    $found_index = $idx;
                    break;
                }
            }
        }

        $app_data = [
            'id' => $found_index >= 0 ? $config['apps'][$found_index]['id'] : 'app_' . time(),
            'name' => $name,
            'code' => !empty($code) ? strtoupper($code) : 'GPT',
            'logo_path' => !empty($logo_path) ? $logo_path : ($found_index >= 0 ? $config['apps'][$found_index]['logo_path'] : ''),
            'keywords' => strtolower($keywords),
            'bg_color' => $bg_color,
            'border_color' => $border_color,
            'active' => true
        ];

        if ($found_index >= 0) {
            $config['apps'][$found_index] = $app_data;
        } else {
            $config['apps'][] = $app_data;
        }

        save_config($config);
        echo json_encode(['success' => true, 'message' => 'บันทึกแอปพลิเคชันสำเร็จ', 'data' => $config['apps']]);
        exit;
    }

    // --- Action: ลบแอปพลิเคชัน ---
    if ($action === 'delete_app') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';

        if (!empty($id) && isset($config['apps']) && is_array($config['apps'])) {
            $config['apps'] = array_values(array_filter($config['apps'], function($item) use ($id) {
                return $item['id'] !== $id;
            }));
            save_config($config);
        }

        echo json_encode(['success' => true, 'message' => 'ลบแอปพลิเคชันเรียบร้อยแล้ว', 'data' => $config['apps']]);
        exit;
    }

    // --- Action: เพิ่ม/แก้ไข API Key ---
    if ($action === 'save_api_key') {
        header('Content-Type: application/json');
        $api_id = isset($_POST['id']) ? trim($_POST['id']) : '';
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $token = isset($_POST['token']) ? trim($_POST['token']) : '';
        $type = isset($_POST['type']) ? trim($_POST['type']) : 'maily';
        if (!in_array($type, ['maily', 'cloud_run'], true)) {
            $type = 'maily'; // ฐานข้อมูลรองรับแค่ maily / cloud_run
        }

        if (empty($name) || empty($token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อและคีย์ API']);
            exit;
        }

        if (!isset($config['api_providers']) || !is_array($config['api_providers'])) {
            $config['api_providers'] = [];
        }

        $found_index = -1;
        if (!empty($api_id)) {
            foreach ($config['api_providers'] as $idx => $item) {
                if ($item['id'] === $api_id) {
                    $found_index = $idx;
                    break;
                }
            }
        }

        $item_data = [
            'id' => $found_index >= 0 ? $config['api_providers'][$found_index]['id'] : 'api_' . time() . '_' . mt_rand(100, 999),
            'name' => $name,
            'token' => $token,
            'url' => $type === 'cloud_run' ? $token : null,
            'type' => $type,
            'active' => true
        ];

        if ($found_index >= 0) {
            $config['api_providers'][$found_index] = $item_data;
        } else {
            $config['api_providers'][] = $item_data;
        }

        if (!save_config($config)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'บันทึก API Key ไม่สำเร็จ (ฐานข้อมูลมีปัญหา)']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'บันทึก API Key สำเร็จ', 'data' => $config['api_providers']]);
        exit;
    }

    // --- Action: ลบ API Key ---
    if ($action === 'delete_api_key') {
        header('Content-Type: application/json');
        $id = isset($_POST['id']) ? trim($_POST['id']) : '';

        if (!empty($id) && isset($config['api_providers']) && is_array($config['api_providers'])) {
            $config['api_providers'] = array_values(array_filter($config['api_providers'], function($item) use ($id) {
                return $item['id'] !== $id;
            }));
            save_config($config);
        }

        echo json_encode(['success' => true, 'message' => 'ลบ API Key เรียบร้อยแล้ว', 'data' => $config['api_providers']]);
        exit;
    }


    // --- Action: อัปโหลดโลโก้ร้าน ---
    if ($action === 'upload_logo') {
        header('Content-Type: application/json');
        if (!isset($_FILES['logo'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์ภาพ']);
            exit;
        }

        $file = $_FILES['logo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'อัปโหลดล้มเหลว']);
            exit;
        }

        $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ไฟล์ต้องเป็นประเภทรูปภาพเท่านั้น']);
            exit;
        }

        $new_logo_path = uploaded_image_to_data_uri($file['tmp_name'], $ext);
        if ($new_logo_path !== null) {
            $config['logo_path'] = $new_logo_path;
            save_config($config);
            echo json_encode(['success' => true, 'message' => 'อัปโหลดโลโก้สำเร็จ', 'logo_path' => $new_logo_path]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'บันทึกรูปไม่ได้ (รูปต้องไม่เกิน 400KB)']);
        }
        exit;
    }

    // --- Action: นำเข้าข้อมูลจากเว็บเก่า (ไฟล์ .sql ที่ Export จาก phpMyAdmin หรือ config.json) ---
    // ไม่ทับชื่อร้าน/โลโก้/บัญชีแอดมินปัจจุบัน — อีเมล/แอป/API/บัญชีกลาง ที่ซ้ำกันจะถูกอัปเดตเป็นค่าจากไฟล์
    if ($action === 'import_data') {
        header('Content-Type: application/json');
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณาเลือกไฟล์ .sql หรือ .json']);
            exit;
        }
        $raw = (string)file_get_contents($_FILES['file']['tmp_name']);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // ตัด BOM
        $before = $config;
        $count = [];

        try {
            $json = json_decode($raw, true);
            if (is_array($json) && (isset($json['imap_emails']) || isset($json['apps']))) {
                // ---- config.json แบบเก่า: รวมเข้ากับข้อมูลปัจจุบัน ----
                $merge = function ($current, $incoming, $keyFn) {
                    $map = [];
                    foreach ($current as $row) $map[$keyFn($row)] = $row;
                    foreach ($incoming as $row) {
                        $k = $keyFn($row);
                        $map[$k] = array_merge($map[$k] ?? [], $row);
                    }
                    return array_values($map);
                };
                $config['imap_emails'] = $merge($config['imap_emails'] ?? [], $json['imap_emails'] ?? [], function ($r) { return strtolower($r['email'] ?? ''); });
                $config['apps'] = $merge($config['apps'] ?? [], $json['apps'] ?? [], function ($r) { return $r['id'] ?? ''; });
                $config['api_providers'] = $merge($config['api_providers'] ?? [], $json['api_providers'] ?? [], function ($r) { return $r['id'] ?? ''; });
                $config['gmail_central_accounts'] = $merge($config['gmail_central_accounts'] ?? [], $json['gmail_central_accounts'] ?? [], function ($r) { return strtolower($r['user'] ?? ''); });
                $config['maily_central_accounts'] = array_values(array_unique(array_merge($config['maily_central_accounts'] ?? [], $json['maily_central_accounts'] ?? [])));
                if (!empty($json['microsoft_oauth']['client_id'])) $config['microsoft_oauth'] = $json['microsoft_oauth'];
                // api_key_1/2 แบบเก่า -> เพิ่มเป็นรายการ API
                foreach (['api_key_1', 'api_key_2'] as $i => $k) {
                    if (!empty($json[$k])) {
                        $exists = false;
                        foreach ($config['api_providers'] as $ap) if (($ap['token'] ?? '') === $json[$k]) $exists = true;
                        if (!$exists) $config['api_providers'][] = ['id' => 'api_import_' . ($i + 1), 'name' => 'Maily Key ' . ($i + 1) . ' (นำเข้า)', 'type' => 'maily', 'token' => $json[$k], 'active' => true];
                    }
                }
                foreach ($config['imap_emails'] as $i => $e) {
                    if (empty($e['id'])) $config['imap_emails'][$i]['id'] = 'email_import_' . $i . '_' . mt_rand(100, 999);
                }
                if (!save_config($config)) throw new RuntimeException('บันทึกข้อมูลไม่สำเร็จ');
                $count = ['อีเมล' => count($json['imap_emails'] ?? []), 'แอป' => count($json['apps'] ?? []), 'API' => count($json['api_providers'] ?? [])];
            } else {
                // ---- ไฟล์ .sql จาก phpMyAdmin: รันเฉพาะคำสั่ง INSERT ของตารางที่รู้จัก (แปลงเป็น REPLACE) ----
                $allowed = ['settings', 'apps', 'imap_emails', 'api_providers', 'gmail_central_accounts', 'maily_central_accounts'];
                $pdo = db_connect();
                $sql = preg_replace('/^\s*(--|#).*$/m', '', $raw);
                $sql = preg_replace('#/\*.*?\*/;?#s', '', $sql);
                $stmts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
                $ran = 0;
                foreach ($stmts as $stmt) {
                    $stmt = trim($stmt);
                    if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $stmt, $m)) continue;
                    $table = strtolower($m[1]);
                    if (!in_array($table, $allowed, true)) continue;
                    $stmt = preg_replace('/^INSERT\s+(?:IGNORE\s+)?INTO/i', 'REPLACE INTO', $stmt);
                    $pdo->exec($stmt);
                    $ran++;
                    $label = ['settings' => 'ตั้งค่า', 'apps' => 'แอป', 'imap_emails' => 'อีเมล', 'api_providers' => 'API', 'gmail_central_accounts' => 'Gmail กลาง', 'maily_central_accounts' => 'Maily กลาง'][$table];
                    $count[$label] = ($count[$label] ?? 0) + (preg_match_all('/\)\s*,\s*\(/', $stmt) + 1);
                }
                if ($ran === 0) throw new RuntimeException('ไม่พบคำสั่ง INSERT ในไฟล์ (ตอน Export ให้เลือกแบบ SQL และติ๊กข้อมูล "data")');
                // คืนชื่อร้าน/โลโก้/บัญชีแอดมินปัจจุบัน (ไม่ให้ถูกทับด้วยของเว็บเก่า)
                $pdo->prepare("UPDATE settings SET shop_name = ?, logo_path = ?, admin_username = ?, admin_password = ? WHERE id = 1")
                    ->execute([$before['shop_name'], $before['logo_path'], $before['admin_username'], $before['admin_password']]);
            }
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'นำเข้าไม่สำเร็จ: ' . $e->getMessage()]);
            exit;
        }

        $parts = [];
        foreach ($count as $k => $v) $parts[] = "$k $v";
        echo json_encode(['success' => true, 'message' => 'นำเข้าเรียบร้อย: ' . implode(', ', $parts)]);
        exit;
    }

    // --- Action: บันทึก Client ID / Client Secret ของ Microsoft App (สำหรับเชื่อมต่อ Hotmail/Outlook ผ่าน Graph API) ---
    if ($action === 'save_microsoft_oauth') {
        header('Content-Type: application/json');
        $ms_client_id = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
        $ms_client_secret = isset($_POST['client_secret']) ? trim($_POST['client_secret']) : '';

        if (empty($ms_client_id) || empty($ms_client_secret)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'กรุณากรอก Client ID และ Client Secret ให้ครบ']);
            exit;
        }

        if (!isset($config['microsoft_oauth']) || !is_array($config['microsoft_oauth'])) {
            $config['microsoft_oauth'] = [];
        }
        $config['microsoft_oauth']['client_id'] = $ms_client_id;
        $config['microsoft_oauth']['client_secret'] = $ms_client_secret;

        save_config($config);
        echo json_encode(['success' => true, 'message' => 'บันทึกการตั้งค่า Microsoft App สำเร็จ']);
        exit;
    }
}

// ดำเนินการ Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    header('Location: admin.php');
    exit;
}

// เริ่มขั้นตอนเชื่อมต่อบัญชี Hotmail/Outlook ผ่าน Microsoft OAuth (ส่งแอดมินไปหน้า Login ของ Microsoft)
if (isset($_GET['action']) && $_GET['action'] === 'connect_microsoft') {
    if (!$logged_in) {
        header('Location: admin.php');
        exit;
    }

    $ms_client_id = $config['microsoft_oauth']['client_id'] ?? '';
    if (empty($ms_client_id)) {
        header('Location: admin.php?ms_error=missing_config#api');
        exit;
    }

    // สร้าง state แบบเซ็นลายเซ็นด้วย client_secret แทนการเก็บใน Session เพราะ browser ต้องกระโดดออกไปที่
    // login.microsoftonline.com แล้ววกกลับมา ทำให้ Session Cookie อาจไม่ติดกลับมาด้วย (ขึ้นอยู่กับ policy ของแต่ละโฮสติ้ง)
    // การเซ็นด้วย HMAC แบบนี้ตรวจสอบความถูกต้องได้โดยไม่ต้องพึ่ง Session เลย
    $ms_client_secret = $config['microsoft_oauth']['client_secret'] ?? '';
    $ms_nonce = bin2hex(random_bytes(16));
    $ms_state = $ms_nonce . '.' . hash_hmac('sha256', $ms_nonce, $ms_client_secret);

    // ต้องตรงกับ Redirect URI ที่ลงทะเบียนไว้ใน Azure App Registration เป๊ะๆ (รวมทั้ง https:// และ .php ต่อท้าย)
    $ms_redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/ms_callback.php';

    $ms_auth_url = 'https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?' . http_build_query([
        'client_id'     => $ms_client_id,
        'response_type' => 'code',
        'redirect_uri'  => $ms_redirect_uri,
        'response_mode' => 'query',
        'scope'         => 'offline_access Mail.Read openid profile email',
        'state'         => $ms_state,
        'prompt'        => 'select_account' // บังคับให้ Microsoft โชว์หน้าเลือกบัญชีทุกครั้ง ไม่ auto-login ด้วยบัญชีเดิมที่เพิ่งล็อกอินไว้
    ]);

    header('Location: ' . $ms_auth_url);
    exit;
}
// ==========================================
// การ์ดอีเมล 1 ใบ (ใช้ทั้งกล่อง Gmail และกล่อง Hotmail/Outlook)
// ==========================================
function admin_app_options($apps) {
    $opts = ['all' => 'ทุกแอป (ไม่แยกหมวด)'];
    foreach ($apps as $a) {
        if (!empty($a['id'])) $opts[$a['id']] = $a['name'] ?? $a['id'];
    }
    return $opts;
}

function admin_email_type($item) {
    $prov = $item['provider'] ?? '';
    if ($prov === 'maily') return 'maily';
    if ($prov === 'microsoft_graph' || $prov === 'hotmail') return 'hotmail';
    return 'gmail';
}

function render_admin_email_card($item, $app_options, $is_ms) {
    $id      = htmlspecialchars($item['id'] ?? '');
    $email   = htmlspecialchars($item['email'] ?? '');
    $active  = isset($item['active']) ? (bool)$item['active'] : true;
    $nf4     = !empty($item['netflix_otp_4digit']);
    $nf6     = !isset($item['netflix_6digit_allowed']) || !empty($item['netflix_6digit_allowed']);
    $pin     = (string)($item['access_pin'] ?? '');
    $app_id  = $item['app_id'] ?? 'all';
    if (!isset($app_options[$app_id])) $app_id = 'all';
    $type    = admin_email_type($item);
    $icons = [
        'gmail'   => ['fa-brands fa-google', 'bg-red-50 text-red-500'],
        'hotmail' => ['fa-brands fa-microsoft', 'bg-sky-50 text-sky-600'],
        'maily'   => ['fa-solid fa-inbox', 'bg-purple-50 text-purple-600'],
    ];
    [$ic, $ic_cls] = $icons[$type];
    ob_start(); ?>
    <div class="email-card rounded-2xl p-3 space-y-2.5 border transition-all <?php echo $active ? 'bg-white border-gray-200/80' : 'bg-rose-50/50 border-rose-200/70'; ?>"
         data-email="<?php echo htmlspecialchars(strtolower($item['email'] ?? '')); ?>" data-app="<?php echo htmlspecialchars($app_id); ?>" data-type="<?php echo $type; ?>" data-domain="<?php echo htmlspecialchars(strtolower(substr(strrchr($item['email'] ?? '', '@') ?: '', 1))); ?>" data-haspin="<?php echo $pin !== '' ? '1' : '0'; ?>">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
                <input type="checkbox" class="email-select w-4 h-4 accent-purple-600 cursor-pointer flex-shrink-0" value="<?php echo $id; ?>" onchange="updateBulkBar()" title="เลือกเพื่อตั้งรหัสพร้อมกันหลายอีเมล">
                <span class="w-7 h-7 rounded-lg flex items-center justify-center text-xs flex-shrink-0 <?php echo $ic_cls; ?>"><i class="<?php echo $ic; ?>"></i></span>
                <span class="text-xs font-bold text-gray-800 truncate" title="<?php echo $email; ?>"><?php echo $email; ?></span>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <label class="relative inline-flex items-center cursor-pointer" title="<?php echo $active ? 'คลิกเพื่อปิดการใช้งานอีเมลนี้' : 'คลิกเพื่อเปิดการใช้งานอีเมลนี้'; ?>">
                    <input type="checkbox" <?php echo $active ? 'checked' : ''; ?> onchange="toggleEmailActive('<?php echo $id; ?>', this.checked)" class="sr-only peer">
                    <div class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-500"></div>
                </label>
                <button onclick="deleteEmail('<?php echo $id; ?>')" title="ลบอีเมลนี้" class="w-7 h-7 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg flex items-center justify-center transition-all">
                    <i class="fa-regular fa-trash-can text-xs"></i>
                </button>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" onclick="editEmailPin('<?php echo $id; ?>', '<?php echo $email; ?>', '<?php echo htmlspecialchars($pin); ?>')" title="รหัส 4 หลักที่ลูกค้าต้องกรอกก่อนกดขอรหัส"
                    class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-1 rounded-lg border <?php echo $pin !== '' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-500 text-white border-rose-500 hover:bg-rose-600'; ?>">
                <i class="fa-solid fa-key"></i><span><?php echo $pin !== '' ? 'รหัส ' . htmlspecialchars($pin) : 'ตั้งรหัส 4 หลัก'; ?></span>
            </button>
            <select onchange="setEmailApp('<?php echo $id; ?>', this.value)" title="หมวดของอีเมลนี้ (ใช้แยกดูอีเมลตามแอป)"
                    class="text-[10px] font-bold px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-600 outline-none max-w-[140px]">
                <?php foreach ($app_options as $opt_id => $opt_name): ?>
                <option value="<?php echo htmlspecialchars($opt_id); ?>" <?php echo $opt_id === $app_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt_id === 'all' ? 'ทุกแอป' : $opt_name); ?></option>
                <?php endforeach; ?>
            </select>
            <span class="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-lg border border-gray-200 bg-gray-50"
                  title="Netflix: 4 = ลองหารหัส 4 หลักก่อน · 6 = อนุญาตให้ลูกค้าเห็นรหัส 6 หลัก (เขียว=เปิด แดง=ปิด)">
                <span class="text-red-600 font-black text-[9px]">NETFLIX</span>
                <button type="button" onclick="toggleNetflixOtpDigit('<?php echo $id; ?>', 4, this)" data-enabled="<?php echo $nf4 ? '1' : '0'; ?>"
                        class="netflix-digit-btn w-6 h-5 rounded-md text-[10px] font-black text-white <?php echo $nf4 ? 'bg-emerald-500' : 'bg-rose-500'; ?>">4</button>
                <button type="button" onclick="toggleNetflixOtpDigit('<?php echo $id; ?>', 6, this)" data-enabled="<?php echo $nf6 ? '1' : '0'; ?>"
                        class="netflix-digit-btn w-6 h-5 rounded-md text-[10px] font-black text-white <?php echo $nf6 ? 'bg-emerald-500' : 'bg-rose-500'; ?>">6</button>
            </span>
        </div>
    </div>
    <?php return ob_get_clean();
}
$admin_app_options = admin_app_options($config['apps'] ?? []);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($config['shop_name']); ?> - ระบบจัดการหลังบ้าน Admin Control Panel</title>
    <!-- Google Fonts for premium typography: Inter (English) and Mitr (Thai) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Mitr:wght@300;400;500;600&display=swap" rel="stylesheet">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'Mitr', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', 'Mitr', sans-serif; line-height: 1.5; }
        .main-card { background: #fff; box-shadow: 0 4px 25px -4px rgba(0,0,0,.05), 0 2px 10px -2px rgba(0,0,0,.02); }
        .tab-btn { transition: all .25s cubic-bezier(.4,0,.2,1); }
        .tab-btn.active { background-color: #F3E8FF; color: #7E22CE; border-color: #E9D5FF; font-weight: 700; }
        .tab-btn.inactive { background-color: #fff; color: #4B5563; border-color: #E5E7EB; }
        .tab-btn.inactive:hover { background-color: #F9FAFB; color: #111827; }
        .app-chip { display:inline-flex; align-items:center; gap:.35rem; font-size:11px; font-weight:700; padding:.35rem .7rem; border-radius:999px; background:#fff; border:1px solid #E9D5FF; color:#6B21A8; cursor:pointer; transition:all .2s; }
        .app-chip span { font-size:10px; background:#F3E8FF; color:#7E22CE; border-radius:999px; padding:0 .4rem; }
        .app-chip:hover { background:#FAF5FF; }
        .app-chip.active { background:#7E22CE; border-color:transparent; color:#fff; box-shadow:0 4px 12px -4px rgba(126,34,206,.5); }
        .app-chip.active span { background:rgba(255,255,255,.3); color:#fff; }
        .app-chip.warn { border-color:#FECDD3; color:#BE123C; }
        .app-chip.warn span { background:#FFE4E6; color:#BE123C; }
        .app-chip.warn.active { background:#E11D48; color:#fff; }
        .add-tile { background:#fff; border:1px solid #E5E7EB; border-radius:1rem; display:flex; flex-direction:column; align-items:center; text-align:center; gap:.4rem; transition:all .2s; }
    </style>
</head>
<body class="min-h-screen selection:bg-purple-600 selection:text-white">

    <!-- แถบด้านบน: ร้าน + แท็บเมนู + ออกจากระบบ -->
    <header class="sticky top-0 z-40 bg-white/95 border-b border-gray-100 shadow-sm" style="backdrop-filter: blur(6px)">
        <div class="max-w-6xl mx-auto px-3 md:px-6 py-2.5 flex items-center gap-3">
            <div class="flex items-center gap-3 min-w-0 flex-1 md:flex-none">
                <div class="relative flex-shrink-0">
                    <img src="/static/monali_logo.jpg" id="headerLogo" alt="" class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-sm">
                    <div class="absolute -bottom-0.5 -right-0.5 bg-purple-600 text-white w-4 h-4 rounded-full flex items-center justify-center text-[7px]"><i class="fa-solid fa-gear"></i></div>
                </div>
                <div class="min-w-0">
                    <h1 class="text-base font-extrabold text-gray-900 leading-tight truncate" id="headerShopName"><?php echo htmlspecialchars($config['shop_name']); ?></h1>
                    <p class="text-[11px] text-gray-400 font-medium truncate">ระบบหลังบ้าน (Admin Dashboard)</p>
                </div>
            </div>
            <?php if ($logged_in): ?>
            <nav class="hidden md:flex flex-1 justify-center gap-1.5" aria-label="เมนูหลังบ้าน">
                <button type="button" data-tab="imap" onclick="switchTab('imap')" class="tab-btn active border rounded-xl flex items-center justify-center gap-1.5 font-semibold px-3.5 py-2 text-sm"><i class="fa-solid fa-envelope"></i><span>บัญชีอีเมล</span></button>
                <button type="button" data-tab="apps" onclick="switchTab('apps')" class="tab-btn inactive border rounded-xl flex items-center justify-center gap-1.5 font-semibold px-3.5 py-2 text-sm"><i class="fa-solid fa-mobile-screen-button"></i><span>แอปพลิเคชัน</span></button>
                <button type="button" data-tab="api" onclick="switchTab('api')" class="tab-btn inactive border rounded-xl flex items-center justify-center gap-1.5 font-semibold px-3.5 py-2 text-sm"><i class="fa-solid fa-key"></i><span>ตั้งค่า API</span></button>
            </nav>
            <div class="flex items-center gap-2 flex-shrink-0">
                <span class="hidden lg:inline-flex items-center gap-1.5 text-xs text-emerald-600 font-semibold bg-emerald-50 px-3 py-1 rounded-full">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Online
                </span>
                <a href="admin.php?action=logout" title="ออกจากระบบ" class="text-xs font-bold text-rose-500 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 w-9 h-9 rounded-xl transition-all flex items-center justify-center">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($logged_in): ?>
        <nav class="md:hidden grid grid-cols-3 gap-1.5 px-3 pb-2.5" aria-label="เมนูหลังบ้าน">
            <button type="button" data-tab="imap" onclick="switchTab('imap')" class="tab-btn active border rounded-xl flex flex-col items-center justify-center gap-1 font-semibold py-2 px-1 text-[11px]"><i class="fa-solid fa-envelope text-sm"></i><span class="font-bold">อีเมล</span></button>
            <button type="button" data-tab="apps" onclick="switchTab('apps')" class="tab-btn inactive border rounded-xl flex flex-col items-center justify-center gap-1 font-semibold py-2 px-1 text-[11px]"><i class="fa-solid fa-mobile-screen-button text-sm"></i><span class="font-bold">แอปพลิเคชัน</span></button>
            <button type="button" data-tab="api" onclick="switchTab('api')" class="tab-btn inactive border rounded-xl flex flex-col items-center justify-center gap-1 font-semibold py-2 px-1 text-[11px]"><i class="fa-solid fa-key text-sm"></i><span class="font-bold">ตั้งค่า API</span></button>
        </nav>
        <?php endif; ?>
    </header>

    <!-- Container หลัก -->
    <div class="w-full max-w-4xl mx-auto p-3 md:p-6 flex flex-col gap-5">

        <?php if (!$logged_in): ?>
        <!-- ========================================== -->
        <!-- 1. LOGIN CARD (กรณีไม่ได้เข้าสู่ระบบ) -->
        <!-- ========================================== -->
        <div class="main-card w-full max-w-md mx-auto rounded-3xl border border-gray-100 p-6 md:p-8 space-y-6">
            <div class="text-center space-y-1">
                <div class="w-12 h-12 bg-purple-100 text-purple-600 rounded-2xl flex items-center justify-center mx-auto mb-2 text-xl shadow-sm">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h2 class="text-lg font-bold text-gray-800">เข้าสู่ระบบผู้ดูแลระบบ</h2>
                <p class="text-xs text-gray-400">กรอกบัญชีผู้ดูแลเพื่อจัดการระบบหลังบ้าน</p>
            </div>

            <form id="loginForm" onsubmit="handleLogin(event)" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-600 pl-1">Username</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                            <i class="fa-solid fa-user text-xs"></i>
                        </div>
                        <input type="text" id="username" required placeholder="ชื่อผู้ใช้งาน" 
                               class="w-full pl-9 pr-4 py-3 rounded-2xl border border-gray-200 focus:ring-2 focus:ring-purple-600 focus:border-purple-600 outline-none text-sm">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-600 pl-1">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                            <i class="fa-solid fa-key text-xs"></i>
                        </div>
                        <input type="password" id="password" required placeholder="รหัสผ่าน" 
                               class="w-full pl-9 pr-10 py-3 rounded-2xl border border-gray-200 focus:ring-2 focus:ring-purple-600 focus:border-purple-600 outline-none text-sm">
                    </div>
                </div>

                <button type="submit" id="loginBtn"
                        class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3.5 px-6 rounded-2xl transition-all shadow-md active:scale-[0.98] text-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-right-to-bracket"></i>
                    <span>เข้าสู่ระบบ</span>
                </button>
            </form>

            <div class="text-center pt-2">
                <a href="index.php" class="text-xs font-semibold text-gray-400 hover:text-black transition-colors">
                    <i class="fa-solid fa-arrow-left-long mr-1"></i>กลับไปที่หน้าหลักลูกค้า
                </a>
            </div>
        </div>

        <?php else: ?>

        <!-- Notification Banner -->
        <div id="noticeBanner" class="hidden bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-2xl text-xs font-bold flex items-center justify-between shadow-sm">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-500 text-base"></i>
                <span id="noticeText">บันทึกสำเร็จ!</span>
            </div>
            <button onclick="document.getElementById('noticeBanner').classList.add('hidden')" class="text-emerald-500 hover:text-emerald-700">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- ========================================== -->
        <!-- TAB 1 CONTENT: จัดการอีเมล IMAP (รับ OTP อัตโนมัติ) -->
        <!-- ========================================== -->
        <div id="tabContentImap" class="space-y-5">
            <?php
                $imap_emails_all = isset($config['imap_emails']) && is_array($config['imap_emails']) ? $config['imap_emails'] : [];
                $imap_emails = $imap_emails_all;
                $ms_ready = !empty($config['microsoft_oauth']['client_id']) && !empty($config['microsoft_oauth']['client_secret']);
                $has_maily_key = false;
                foreach (($config['api_providers'] ?? []) as $ap) { if (($ap['type'] ?? 'maily') === 'maily' && !empty($ap['token'])) $has_maily_key = true; }
                $type_counts = ['gmail' => 0, 'hotmail' => 0, 'maily' => 0];
                $app_counts = [];
                $domain_counts = [];
                $no_pin_count = 0;
                foreach ($imap_emails_all as $ce) {
                    $type_counts[admin_email_type($ce)]++;
                    $k = $ce['app_id'] ?? 'all';
                    if (!isset($admin_app_options[$k])) $k = 'all';
                    $app_counts[$k] = ($app_counts[$k] ?? 0) + 1;
                    $dm = strtolower(substr(strrchr($ce['email'] ?? '', '@') ?: '', 1));
                    if ($dm !== '') $domain_counts[$dm] = ($domain_counts[$dm] ?? 0) + 1;
                    if (($ce['access_pin'] ?? '') === '') $no_pin_count++;
                }
                arsort($domain_counts);
            ?>
            <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-5">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-4">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2"><span class="text-purple-600 text-lg"><i class="fa-regular fa-envelope"></i></span><h2 class="text-base font-extrabold text-gray-900">อีเมลของร้าน + รหัส 4 หลัก</h2></div>
                        <p class="text-xs text-gray-400">เพิ่มอีเมลของร้านพร้อมตั้งรหัส 4 หลัก แล้วส่งอีเมล + รหัสให้ลูกค้าไปกดขอ OTP ได้เลย</p>
                    </div>
                    <button type="button" onclick="window.location.reload()" title="รีเฟรช" class="w-9 h-9 flex-shrink-0 bg-white hover:bg-gray-50 text-gray-500 border border-gray-200 rounded-xl flex items-center justify-center transition-all"><i class="fa-solid fa-rotate-right text-xs"></i></button>
                </div>

                <!-- ปุ่มเพิ่มอีเมลแต่ละแบบ -->
                <div class="grid grid-cols-3 gap-2 sm:gap-3">
                    <button type="button" onclick="openAddEmailModal('gmail')" class="add-tile hover:bg-red-50/40 hover:border-red-200 px-2 py-3 sm:p-4">
                        <span class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl bg-red-50 text-red-500 flex items-center justify-center text-lg"><i class="fa-brands fa-google"></i></span>
                        <span class="text-xs sm:text-sm font-extrabold text-gray-900 leading-tight">Gmail</span>
                        <span class="hidden sm:block text-[11px] text-gray-400 leading-snug">อีเมล + App Password</span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] sm:text-[11px] font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded-lg"><i class="fa-solid fa-plus"></i>เพิ่ม</span>
                    </button>
                    <?php if ($ms_ready): ?>
                    <a href="admin.php?action=connect_microsoft" class="add-tile hover:bg-sky-50/40 hover:border-sky-200 px-2 py-3 sm:p-4">
                    <?php else: ?>
                    <a href="#" onclick="event.preventDefault(); alert('ใส่ Client ID / Client Secret ของ Microsoft ในแท็บตั้งค่า API ก่อนนะคะ'); switchTab('api'); document.getElementById('msOauthSection').scrollIntoView({behavior:'smooth'});" class="add-tile opacity-60 px-2 py-3 sm:p-4">
                    <?php endif; ?>
                        <span class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center text-lg"><i class="fa-brands fa-microsoft"></i></span>
                        <span class="text-xs sm:text-sm font-extrabold text-gray-900 leading-tight">Hotmail</span>
                        <span class="hidden sm:block text-[11px] text-gray-400 leading-snug"><?php echo $ms_ready ? 'ล็อกอิน Microsoft ได้เลย' : 'ตั้งค่าในหน้า API ก่อน'; ?></span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] sm:text-[11px] font-bold px-2 py-0.5 rounded-lg <?php echo $ms_ready ? 'text-sky-700 bg-sky-50' : 'text-gray-500 bg-gray-100'; ?>">
                            <i class="<?php echo $ms_ready ? 'fa-solid fa-arrow-right-to-bracket' : 'fa-solid fa-lock'; ?>"></i><?php echo $ms_ready ? 'เชื่อมต่อ' : 'ปิดอยู่'; ?>
                        </span>
                    </a>
                    <button type="button" onclick="openAddEmailModal('maily')" class="add-tile hover:bg-purple-50/40 hover:border-purple-200 px-2 py-3 sm:p-4">
                        <span class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg"><i class="fa-solid fa-inbox"></i></span>
                        <span class="text-xs sm:text-sm font-extrabold text-gray-900 leading-tight">Maily</span>
                        <span class="hidden sm:block text-[11px] text-gray-400 leading-snug"><?php echo $has_maily_key ? 'ใช้ Token ในหน้า API' : 'ใส่ Token ในหน้า API ก่อน'; ?></span>
                        <span class="inline-flex items-center gap-1 whitespace-nowrap text-[10px] sm:text-[11px] font-bold text-purple-700 bg-purple-50 px-2 py-0.5 rounded-lg"><i class="fa-solid fa-plus"></i>เพิ่ม</span>
                    </button>
                </div>

                <div class="bg-blue-50/80 border border-blue-200/70 rounded-2xl p-4 text-xs text-blue-900">
                    <div class="flex items-start gap-2.5">
                        <div class="w-5 h-5 rounded-full bg-blue-500 text-white flex items-center justify-center text-[10px] flex-shrink-0 mt-0.5"><i class="fa-solid fa-info"></i></div>
                        <div class="space-y-1.5 leading-relaxed">
                            <p><b>วิธีใช้:</b> เพิ่มอีเมล → ตั้งรหัส 4 หลัก → ส่งอีเมล + รหัสให้ลูกค้า → ลูกค้ากรอกในหน้า OTP แล้วกดขอรหัสได้ทันที</p>
                            <p class="text-[11px] text-blue-800/80"><i class="fa-solid fa-key mr-1"></i>อีเมลที่ยังไม่ตั้งรหัส (ปุ่มสีแดง) ลูกค้าจะขอรหัสไม่ได้ · กดที่ปุ่มเพื่อตั้งหรือเปลี่ยนรหัส · ติ๊กหลายอีเมลเพื่อตั้งรหัสเดียวกันพร้อมกัน</p>
                            <button type="button" onclick="openGuideModal('gmail')" class="inline-flex items-center gap-1.5 bg-white hover:bg-blue-100/70 text-purple-700 border border-purple-200 text-[11px] font-extrabold px-3 py-1.5 rounded-xl transition-all"><i class="fa-brands fa-google text-red-500"></i><span>วิธีหา App Password (Gmail)</span></button>
                        </div>
                    </div>
                </div>

                <!-- ตัวกรอง: ประเภทอีเมล / แอป / ยังไม่ตั้งรหัส -->
                <div class="flex flex-wrap gap-1.5" id="appFilterChips">
                    <button type="button" data-app-filter="__all" onclick="setAppFilter('__all')" class="app-chip active">ทั้งหมด <span><?php echo count($imap_emails_all); ?></span></button>
                    <button type="button" data-app-filter="__gmail" onclick="setAppFilter('__gmail')" class="app-chip">Gmail <span><?php echo $type_counts['gmail']; ?></span></button>
                    <button type="button" data-app-filter="__hotmail" onclick="setAppFilter('__hotmail')" class="app-chip">Hotmail <span><?php echo $type_counts['hotmail']; ?></span></button>
                    <button type="button" data-app-filter="__maily" onclick="setAppFilter('__maily')" class="app-chip">Maily <span><?php echo $type_counts['maily']; ?></span></button>
                    <?php foreach ($admin_app_options as $opt_id => $opt_name): if ($opt_id === 'all') continue; ?>
                    <button type="button" data-app-filter="<?php echo htmlspecialchars($opt_id); ?>" onclick="setAppFilter('<?php echo htmlspecialchars($opt_id); ?>')" class="app-chip"><?php echo htmlspecialchars($opt_name); ?> <span><?php echo $app_counts[$opt_id] ?? 0; ?></span></button>
                    <?php endforeach; ?>
                    <button type="button" data-app-filter="__nopin" onclick="setAppFilter('__nopin')" class="app-chip warn">ยังไม่ตั้งรหัส <span><?php echo $no_pin_count; ?></span></button>
                </div>


                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 bg-rose-50/50 border border-rose-100 rounded-2xl px-4 py-2.5">
                    <p class="text-xs font-bold text-gray-700 flex items-center gap-2"><span class="bg-red-600 text-white text-[9px] font-black px-1.5 py-0.5 rounded">NETFLIX</span> รหัส 6 หลัก (ทุกอีเมล)</p>
                    <div class="flex items-center gap-1.5">
                        <button type="button" onclick="bulkNetflix6(true)" class="text-[11px] font-bold px-3 py-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white">เปิดทั้งหมด</button>
                        <button type="button" onclick="bulkNetflix6(false)" class="text-[11px] font-bold px-3 py-1.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white">ปิดทั้งหมด</button>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 bg-gray-50/70 border border-gray-200/80 rounded-2xl p-2.5 sm:px-4">
                    <div class="flex items-center gap-2.5 flex-1">
                        <i class="fa-solid fa-magnifying-glass text-gray-400 text-sm"></i>
                        <input type="text" id="emailSearchInput" oninput="filterEmailList(this.value)" placeholder="พิมพ์ค้นหาอีเมล เช่น @gmail.com หรือชื่อบัญชี..." class="w-full bg-transparent text-xs sm:text-sm text-gray-800 focus:outline-none placeholder-gray-400 font-medium">
                        <button type="button" id="clearEmailSearchBtn" onclick="clearEmailSearch()" class="hidden text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-xs"></i></button>
                    </div>
                    <span id="emailSearchCount" class="text-[11px] font-bold text-gray-400 whitespace-nowrap bg-white px-2.5 py-1 rounded-xl border border-gray-100"><?php echo count($imap_emails_all); ?> รายการ</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3" id="emailListGrid">
                    <div id="noEmailSearchResult" class="hidden col-span-full text-center py-10 text-gray-400 text-xs font-medium">ไม่พบอีเมลที่ตรงกับเงื่อนไข</div>
                    <?php if (empty($imap_emails_all)): ?>
                    <div class="col-span-full text-center py-10 text-gray-400 text-xs font-medium">ยังไม่มีอีเมลของร้าน กดปุ่มเพิ่มอีเมลด้านบน (Gmail / Hotmail / Maily)</div>
                    <?php else: foreach ($imap_emails_all as $item):
                        echo render_admin_email_card($item, $admin_app_options, ($item['provider'] ?? '') === 'microsoft_graph');
                    endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2 CONTENT: ตั้งค่าแอปพลิเคชัน (OTP Apps) -->
        <!-- ========================================== -->
        <div id="tabContentApps" class="hidden space-y-5">
            <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-6">
                
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 pb-4">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-purple-600 text-lg"><i class="fa-solid fa-mobile-screen-button"></i></span>
                            <h2 class="text-base font-extrabold text-gray-900">จัดการแอปพลิเคชัน (OTP Apps)</h2>
                        </div>
                        <p class="text-xs text-gray-400">เพิ่ม/แก้ไข/ลบแอปพลิเคชันที่จะปรากฏให้ผู้ใช้เลือกในหน้าหลัก</p>
                    </div>
                    
                    <button onclick="openAddAppModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm transition-all flex items-center gap-1.5 active:scale-[0.98] self-start sm:self-auto">
                        <i class="fa-solid fa-plus"></i>
                        <span>เพิ่มแอปพลิเคชัน</span>
                    </button>
                </div>

                <!-- Grid รายการแอป -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4" id="appsGrid">
                    <?php 
                    $apps = isset($config['apps']) ? $config['apps'] : [];
                    if (empty($apps)): 
                    ?>
                    <div class="col-span-full text-center py-10 text-gray-400 text-xs font-medium">
                        ยังไม่มีแอปพลิเคชันในระบบ กดปุ่ม "+ เพิ่มแอปพลิเคชัน" ด้านบนเพื่อเพิ่มแอปใหม่
                    </div>
                    <?php else: foreach ($apps as $app): ?>
                    <div class="border rounded-2xl p-4 flex flex-col justify-between gap-4 transition-all hover:shadow-md" style="background-color: <?php echo htmlspecialchars($app['bg_color'] ?? '#FFFFFF'); ?>; border-color: <?php echo htmlspecialchars($app['border_color'] ?? '#E5E7EB'); ?>;">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-100 flex items-center justify-center overflow-hidden p-1 flex-shrink-0">
                                <?php if (!empty($app['logo_path'])): ?>
                                <img src="<?php echo htmlspecialchars($app['logo_path']); ?>" alt="logo" class="w-full h-full object-contain">
                                <?php else: ?>
                                <i class="fa-solid fa-cube text-gray-400 text-xl"></i>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-extrabold text-gray-900 truncate"><?php echo htmlspecialchars($app['name']); ?></h3>
                                <p class="text-[10px] text-gray-500 font-mono">Code: <?php echo htmlspecialchars($app['code'] ?? 'GPT'); ?></p>
                                <p class="text-[9px] text-gray-400 truncate">Keywords: <?php echo htmlspecialchars($app['keywords'] ?? '-'); ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-between pt-2 border-t border-black/5">
                            <span class="text-[10px] font-bold text-emerald-600 bg-emerald-100/60 px-2 py-0.5 rounded-full">เปิดใช้งาน</span>
                            <div class="flex items-center gap-1">
                                <button onclick="openEditAppModal(<?php echo htmlspecialchars(json_encode($app)); ?>)" class="text-xs text-gray-600 hover:text-purple-700 bg-white hover:bg-gray-50 px-2.5 py-1 rounded-lg border border-gray-200 font-semibold shadow-xs">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i>แก้ไข
                                </button>
                                <button onclick="deleteApp('<?php echo htmlspecialchars($app['id']); ?>')" class="text-xs text-rose-500 hover:text-rose-700 bg-white hover:bg-rose-50 px-2 py-1 rounded-lg border border-gray-200">
                                    <i class="fa-regular fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3 CONTENT: ตั้งค่า API & ความปลอดภัย -->
        <!-- ========================================== -->
        <div id="tabContentApi" class="hidden space-y-5">
            <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-6" id="msOauthSection">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 pb-4">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sky-500 text-lg"><i class="fa-brands fa-microsoft"></i></span>
                            <h2 class="text-base font-extrabold text-gray-900">เชื่อมต่อ Hotmail / Outlook (Microsoft App)</h2>
                        </div>
                        <p class="text-xs text-gray-400">ตั้งครั้งเดียว แล้วกด &quot;เชื่อมต่อ&quot; ที่การ์ด Hotmail ในแท็บบัญชีอีเมล เพื่อเพิ่มบัญชีได้ไม่จำกัด</p>
                    </div>
                </div>

                <?php
                    $ms_config = isset($config['microsoft_oauth']) && is_array($config['microsoft_oauth']) ? $config['microsoft_oauth'] : [];
                    $ms_client_id = $ms_config['client_id'] ?? '';
                    $ms_client_secret = $ms_config['client_secret'] ?? '';
                    $ms_accounts = [];
                    if (isset($config['imap_emails']) && is_array($config['imap_emails'])) {
                        foreach ($config['imap_emails'] as $ms_item) {
                            if (($ms_item['provider'] ?? '') === 'microsoft_graph') {
                                $ms_accounts[] = $ms_item;
                            }
                        }
                    }
                ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">Application (client) ID</label>
                        <input type="text" id="msClientIdInput" value="<?php echo htmlspecialchars($ms_client_id); ?>" placeholder="เช่น 13b065c6-37bc-4922-..." class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-mono focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">Client Secret (Value)</label>
                        <input type="password" id="msClientSecretInput" value="<?php echo htmlspecialchars($ms_client_secret); ?>" placeholder="เช่น WwW8Q~gZ0gGBP..." class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-mono focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                </div>

                <div class="bg-blue-50/80 border border-blue-200/70 rounded-2xl p-3.5 text-[11px] text-blue-900 leading-relaxed">
                    Redirect URI ที่ต้องตั้งค่าใน Azure App Registration (เมนู Authentication) ให้ตรงกันเป๊ะๆ:<br>
                    <code class="font-mono font-bold break-all">https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?>/ms_callback.php</code>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" onclick="handleSaveMicrosoftOAuth()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm transition-all active:scale-[0.98]">
                        <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึกการตั้งค่า
                    </button>
                    <?php if (!empty($ms_client_id)): ?>
                    <a href="admin.php?action=connect_microsoft" class="bg-white hover:bg-gray-50 text-purple-700 border border-purple-200 font-bold text-xs px-4 py-2.5 rounded-xl shadow-xs transition-all inline-flex items-center">
                        <i class="fa-brands fa-microsoft mr-1"></i> เชื่อมต่อบัญชี Hotmail/Outlook เพิ่ม
                    </a>
                    <?php else: ?>
                    <span class="text-[11px] text-gray-400">กรอกและบันทึก Client ID / Secret ก่อน ถึงจะเชื่อมต่อบัญชีได้</span>
                    <?php endif; ?>
                </div>
            </div>

            <form id="settingsForm" onsubmit="handleSettingsUpdate(event)" class="space-y-5">
                
                <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-6">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 pb-4">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="text-purple-600 text-lg"><i class="fa-solid fa-key"></i></span>
                                <h2 class="text-base font-extrabold text-gray-900">ตั้งค่า API Keys (OTP Providers)</h2>
                            </div>
                            <p class="text-xs text-gray-400">เพิ่ม/แก้ไข API Tokens สำหรับดึงรหัส OTP จากผู้ให้บริการ</p>
                        </div>
                        
                        <button type="button" onclick="openAddApiKeyModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm transition-all flex items-center gap-1.5 active:scale-[0.98] self-start sm:self-auto">
                            <i class="fa-solid fa-plus"></i>
                            <span>เพิ่ม API</span>
                        </button>
                    </div>

                    <!-- Grid รายการ API Keys -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="apiKeysGrid">
                        <?php 
                        $api_providers = isset($config['api_providers']) && is_array($config['api_providers']) ? $config['api_providers'] : [];
                        if (empty($api_providers)): 
                        ?>
                        <div class="col-span-full text-center py-8 text-gray-400 text-xs font-medium">
                            ยังไม่มีรายการ API Key ในระบบ กดปุ่ม "+ เพิ่ม API" เพื่อเริ่มต้นใช้งาน
                        </div>
                        <?php else: foreach ($api_providers as $api_item): ?>
                        <div class="bg-purple-50/40 border border-purple-200/60 rounded-2xl p-3.5 sm:p-4 flex flex-col xs:flex-row xs:items-center justify-between gap-3 shadow-xs hover:border-purple-300 transition-all">
                            <div class="flex items-center gap-3 min-w-0 w-full xs:w-auto">
                                <div class="w-10 h-10 sm:w-11 sm:h-11 bg-purple-100 border border-purple-200 text-purple-700 rounded-xl flex items-center justify-center text-base sm:text-lg flex-shrink-0">
                                    <i class="fa-solid fa-key"></i>
                                </div>
                                <div class="min-w-0 flex-1 space-y-0.5">
                                    <h3 class="text-xs sm:text-sm font-extrabold text-gray-900 truncate" title="<?php echo htmlspecialchars($api_item['name']); ?>"><?php echo htmlspecialchars($api_item['name']); ?></h3>
                                    <p class="text-[10px] text-gray-500 font-mono break-all sm:truncate"><?php echo htmlspecialchars($api_item['token'] ?? ''); ?></p>
                                    <div class="pt-0.5">
                                        <span class="inline-flex items-center gap-1 bg-purple-100 text-purple-700 text-[9px] font-bold px-2 py-0.5 rounded-md">
                                            <?php echo htmlspecialchars(strtoupper($api_item['type'] ?? 'MAILY')); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center justify-end gap-1.5 w-full xs:w-auto border-t xs:border-t-0 pt-2 xs:pt-0 border-purple-100 flex-shrink-0">
                                <button type="button" onclick='openEditApiKeyModal(<?php echo htmlspecialchars(json_encode($api_item)); ?>)' title="แก้ไข" class="px-2.5 py-1 text-xs font-semibold text-purple-700 bg-purple-100/60 hover:bg-purple-200/60 rounded-lg flex items-center gap-1 transition-all">
                                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                                    <span>แก้ไข</span>
                                </button>
                                <button type="button" onclick="deleteApiKey('<?php echo htmlspecialchars($api_item['id']); ?>')" title="ลบ API Key นี้" class="w-7 h-7 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg flex items-center justify-center transition-all">
                                    <i class="fa-regular fa-trash-can text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- นำเข้าข้อมูลจากเว็บเก่า -->
                <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-4">
                    <div class="border-b border-gray-100 pb-3 flex items-center gap-2 text-sm font-extrabold text-gray-900">
                        <i class="fa-solid fa-file-import text-purple-600"></i>
                        <span>นำเข้าข้อมูลจากเว็บเก่า (Hostinger)</span>
                    </div>
                    <p class="text-xs text-gray-500 leading-relaxed">
                        phpMyAdmin บน Hostinger &rarr; เลือกฐานข้อมูล &rarr; แท็บ <b>Export</b> &rarr; Format <b>SQL</b> &rarr; กด Export จะได้ไฟล์ <code>.sql</code> แล้วอัปโหลดที่นี่
                        (หรือใช้ไฟล์ <code>config.json</code> แบบเก่าก็ได้)<br>
                        <span class="text-rose-500">อีเมลที่นำเข้ายังไม่มีรหัสผ่าน 4 หลัก ต้องไปตั้งรหัสในแท็บอีเมลก่อน ลูกค้าถึงจะขอรหัสได้ · ชื่อร้าน/โลโก้/รหัสแอดมินปัจจุบันจะไม่ถูกเปลี่ยน</span>
                    </p>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <input type="file" id="importFileInput" accept=".sql,.json,.txt" class="flex-1 text-xs file:mr-3 file:px-3 file:py-2 file:rounded-xl file:border-0 file:bg-purple-50 file:text-purple-700 file:font-bold">
                        <button type="button" onclick="importData()" id="importBtn" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm">
                            <i class="fa-solid fa-upload mr-1"></i> นำเข้าข้อมูล
                        </button>
                    </div>
                </div>

                <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-6">
                    <div class="border-b border-gray-100 pb-3 flex items-center gap-2 text-sm font-extrabold text-gray-900">
                        <i class="fa-solid fa-sliders text-purple-600"></i>
                        <span>ตั้งค่าโลโก้ร้านและชื่อร้านค้า</span>
                    </div>

                    <!-- Shop Logo Upload -->
                    <div class="flex items-center gap-4 bg-gray-50/60 p-4 rounded-2xl border border-gray-100">
                        <img src="<?php echo htmlspecialchars($config['logo_path']); ?>" id="dashboardLogoPreview" alt="logo preview" 
                             class="w-16 h-16 rounded-full object-cover border-2 border-white shadow-sm flex-shrink-0">
                        
                        <div class="flex-1 space-y-1.5">
                            <p class="text-xs font-bold text-gray-800">เปลี่ยนรูปโลโก้ร้านค้า</p>
                            <label for="logoFileInput" class="inline-flex items-center gap-1.5 bg-white hover:bg-gray-100 text-gray-700 font-bold text-xs px-3 py-1.5 rounded-xl border border-gray-200 cursor-pointer shadow-xs">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>เลือกไฟล์รูปภาพ</span>
                            </label>
                            <input type="file" id="logoFileInput" accept="image/*" class="hidden" onchange="uploadLogoFile()">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-600 pl-1">ชื่อร้านค้า (Shop Name)</label>
                        <input type="text" id="shopNameInput" required value="<?php echo htmlspecialchars($config['shop_name']); ?>"
                               placeholder="ชื่อร้านค้า" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-semibold focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                </div>

                <div class="main-card rounded-3xl border border-gray-100 p-5 md:p-7 space-y-6">
                    <div class="border-b border-gray-100 pb-3 flex items-center gap-2 text-sm font-extrabold text-gray-900">
                        <i class="fa-solid fa-shield-halved text-purple-600"></i>
                        <span>เปลี่ยนบัญชีความปลอดภัยผู้ดูแลระบบ</span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-xs font-bold text-gray-600 pl-1">Username ใหม่ (เว้นว่างไว้ถ้าไม่เปลี่ยน)</label>
                            <input type="text" id="newUsernameInput" placeholder="Username ใหม่" 
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-xs font-bold text-gray-600 pl-1">Password ใหม่ (เว้นว่างไว้ถ้าไม่เปลี่ยน)</label>
                            <input type="password" id="newPasswordInput" placeholder="Password ใหม่" 
                                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                        </div>
                    </div>
                </div>

                <button type="submit" id="saveSettingsBtn"
                        class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3.5 px-6 rounded-2xl transition-all shadow-md active:scale-[0.98] text-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>บันทึกการตั้งค่าทั้งหมด</span>
                </button>
            </form>
        </div>

        <?php endif; ?>

    </div>

    <!-- ========================================== -->
    <!-- MODAL 1: เพิ่ม/แก้ไข อีเมล IMAP -->
    <!-- ========================================== -->
    <div id="emailModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-3xl shadow-xl border border-gray-100 p-6 space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h3 class="text-base font-extrabold text-gray-900 flex items-center gap-2">
                    <i class="fa-regular fa-envelope text-purple-600"></i>
                    <span>เพิ่มบัญชีอีเมล IMAP</span>
                </h3>
                <button onclick="closeEmailModal()" class="text-gray-400 hover:text-gray-600 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="emailForm" onsubmit="handleSaveEmail(event)" class="space-y-4">
                <p class="text-xs text-gray-400">ใส่อีเมลลูกค้า + ตั้งรหัสผ่าน 4 หลักให้ลูกค้า</p>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">ประเภทอีเมล</label>
                    <select id="emailProviderInput" onchange="onEmailProviderChange()" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                        <option value="maily">Maily.space (ดึงผ่าน Maily API — ไม่ต้องใส่รหัสอีเมล)</option>
                        <option value="gmail">Gmail (ใส่ App Password ให้ระบบอ่านกล่องเมลเอง)</option>
                        <option value="hotmail">Hotmail / Outlook (เชื่อมผ่าน Microsoft ด้านล่าง หรือ Forward)</option>
                        <option value="other">อื่น ๆ (ดึงผ่านบัญชีกลาง / Forward)</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">ที่อยู่อีเมล (Email Address)</label>
                    <input type="email" id="emailAddrInput" required placeholder="เช่น example@gmail.com"
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">รหัสผ่านลูกค้า (4 หลัก) <span class="text-rose-500">*</span></label>
                        <input type="text" id="emailPinInput" inputmode="numeric" maxlength="4" pattern="\d{4}" placeholder="เช่น 1234"
                               oninput="this.value=this.value.replace(/\D/g,'').slice(0,4)"
                               class="w-full px-4 py-2.5 rounded-xl border border-pink-200 bg-pink-50/50 text-sm font-bold tracking-[0.4em] text-center focus:ring-2 focus:ring-purple-600 outline-none">
                        <button type="button" onclick="document.getElementById('emailPinInput').value=String(Math.floor(Math.random()*10000)).padStart(4,'0')" class="text-[10px] font-bold text-purple-600 underline">สุ่มรหัสให้</button>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">หมวดแอป</label>
                        <select id="emailAppInput" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                            <?php foreach ($admin_app_options as $opt_id => $opt_name): ?>
                            <option value="<?php echo htmlspecialchars($opt_id); ?>"><?php echo htmlspecialchars($opt_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="text-[10px] text-rose-500 -mt-2">* อีเมลที่ไม่มีรหัสผ่าน ลูกค้าจะกดขอรหัส OTP ไม่ได้</p>

                <div class="space-y-1.5" id="passInputContainer">
                    <label class="text-xs font-bold text-gray-700">App Password (รหัสผ่านแอป 16 หลัก) <span class="text-gray-400 font-normal">— ไม่บังคับ</span></label>
                    <input type="password" id="emailPassInput" placeholder="รหัสผ่าน App Password สำหรับ IMAP"
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                    <p class="text-[10px] text-gray-400">* Gmail ต้องสร้าง App Password ในบัญชี Google เพื่อล็อกอิน IMAP</p>
                    <div class="flex flex-wrap items-center gap-3 pt-1">
                        <button type="button" onclick="openGuideModal('gmail')" class="text-[11px] font-bold text-purple-600 hover:text-purple-800 underline flex items-center gap-1 cursor-pointer">
                            <i class="fa-brands fa-google text-red-500"></i> ดูวิธีเอา App Password (Gmail)
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3" id="imapHostPortContainer">
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">IMAP Host</label>
                        <input type="text" id="emailHostInput" value="imap.gmail.com" 
                               class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs font-mono focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">Port (SSL)</label>
                        <input type="number" id="emailPortInput" value="993" 
                               class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs font-mono focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeEmailModal()" class="px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl shadow-sm">บันทึกอีเมล</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 2: เพิ่ม/แก้ไข แอปพลิเคชัน (OTP App) -->
    <!-- ========================================== -->
    <div id="appModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-3xl shadow-xl border border-gray-100 p-6 space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h3 class="text-base font-extrabold text-gray-900 flex items-center gap-2" id="appModalTitle">
                    <i class="fa-solid fa-mobile-screen-button text-purple-600"></i>
                    <span>เพิ่มแอปพลิเคชันใหม่</span>
                </h3>
                <button onclick="closeAppModal()" class="text-gray-400 hover:text-gray-600 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="appForm" onsubmit="handleSaveApp(event)" class="space-y-4">
                <input type="hidden" id="appIdInput" value="">
                <input type="hidden" id="appLogoPathInput" value="">

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">ชื่อแอปพลิเคชัน (App Name)</label>
                    <input type="text" id="appNameInput" required placeholder="เช่น Disney+, Netflix, ChatGPT, Viu" 
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">App Code (ย่อ)</label>
                        <input type="text" id="appCodeInput" placeholder="เช่น DN, TM, NF, GPT" 
                               class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs font-mono uppercase focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">Keyword ค้นหาในเมล</label>
                        <input type="text" id="appKeywordsInput" placeholder="เช่น disney, netflix" 
                               class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">รูปภาพโลโก้แอปพลิเคชัน</label>
                    <div class="flex items-center gap-3">
                        <div id="appLogoPreviewBox" class="w-10 h-10 bg-gray-50 border border-gray-200 rounded-xl flex items-center justify-center overflow-hidden">
                            <i class="fa-solid fa-cube text-gray-400"></i>
                        </div>
                        <input type="file" id="appLogoFileInput" accept="image/*" class="text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 cursor-pointer">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">สีพื้นหลังการ์ด</label>
                        <input type="color" id="appBgColorInput" value="#F0F7FF" class="w-full h-9 p-0.5 rounded-xl border border-gray-200 cursor-pointer">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold text-gray-700">สีเส้นขอบการ์ด</label>
                        <input type="color" id="appBorderColorInput" value="#D6E9FF" class="w-full h-9 p-0.5 rounded-xl border border-gray-200 cursor-pointer">
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeAppModal()" class="px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl shadow-sm">บันทึกแอป</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL 3: เพิ่ม/แก้ไข API Key (OTP Provider) -->
    <!-- ========================================== -->
    <div id="apiKeyModal" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-3xl shadow-xl border border-gray-100 p-6 space-y-5 animate-in fade-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h3 class="text-base font-extrabold text-gray-900 flex items-center gap-2" id="apiKeyModalTitle">
                    <i class="fa-solid fa-key text-purple-600"></i>
                    <span>เพิ่ม API Key ใหม่</span>
                </h3>
                <button type="button" onclick="closeApiKeyModal()" class="text-gray-400 hover:text-gray-600 w-8 h-8 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form id="apiKeyForm" onsubmit="handleSaveApiKey(event)" class="space-y-4">
                <input type="hidden" id="apiKeyIdInput" value="">

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">ชื่ออ้างอิง API Key (Name / Label)</label>
                    <input type="text" id="apiKeyNameInput" required placeholder="เช่น Maily Space Main Token, Key 2, ฯลฯ" 
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">ประเภท API (Provider Type)</label>
                    <select id="apiKeyTypeInput" class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none bg-white">
                        <option value="maily">Maily Space API (Bearer Token)</option>
                        <option value="cloud_run">Cloud Run API (RDCW Co., Ltd.)</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-gray-700">API Key / Token String</label>
                    <input type="text" id="apiKeyValueInput" required placeholder="เช่น sk_v1_... หรือ otp_key_live_..." 
                           class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-mono focus:ring-2 focus:ring-purple-600 outline-none">
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeApiKeyModal()" class="px-4 py-2 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl shadow-sm">บันทึก API Key</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL GUIDE: คู่มือรูปภาพวิธีตั้งค่า -->
    <!-- ========================================== -->
    <div id="guideModal" class="hidden fixed inset-0 z-50 bg-black/70 backdrop-blur-xs flex items-center justify-center p-3 md:p-6 overflow-y-auto">
        <div class="bg-white w-full max-w-3xl rounded-3xl shadow-2xl border border-gray-100 overflow-hidden my-auto max-h-[92vh] flex flex-col animate-in fade-in zoom-in-95 duration-200">
            <div class="flex items-center justify-between p-4 border-b border-gray-100 bg-gray-50/80 flex-shrink-0">
                <h3 class="text-sm md:text-base font-extrabold text-gray-900 flex items-center gap-2" id="guideModalTitle">
                    <i class="fa-solid fa-book-open text-purple-600"></i>
                    <span>คู่มือการใช้งาน</span>
                </h3>
                <button type="button" onclick="closeGuideModal()" class="text-gray-400 hover:text-gray-700 w-8 h-8 rounded-full flex items-center justify-center text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="p-3 md:p-5 overflow-y-auto flex-1 flex flex-col items-center justify-center bg-gray-900/5">
                <img id="guideModalImg" src="" alt="Guide tutorial" class="max-w-full h-auto rounded-2xl shadow-md border border-gray-200 object-contain">
            </div>
            <div class="p-4 border-t border-gray-100 bg-white flex flex-col sm:flex-row items-center justify-between gap-3 flex-shrink-0">
                <div class="text-xs text-gray-600 font-medium" id="guideModalSub">กดดูขั้นตอนอย่างละเอียด</div>
                <button type="button" onclick="closeGuideModal()" class="w-full sm:w-auto px-5 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl shadow-sm">
                    เข้าใจแล้ว / ปิดหน้าต่าง
                </button>
            </div>
        </div>
    </div>

    <!-- แถบตั้งรหัสหลายอีเมลพร้อมกัน (โผล่เมื่อมีการติ๊กเลือก) -->
    <div id="bulkBar" class="hidden fixed bottom-4 left-1/2 -translate-x-1/2 z-40 w-[calc(100%-2rem)] max-w-2xl bg-white border border-pink-200 shadow-xl rounded-2xl p-3 flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold text-pink-700"><i class="fa-solid fa-square-check mr-1"></i>เลือกแล้ว <span id="bulkCount">0</span> อีเมล</span>
        <button type="button" onclick="selectAllVisible()" class="text-[11px] font-bold text-purple-600 underline">เลือกทั้งหมดที่แสดง</button>
        <div class="flex items-center gap-1.5 ml-auto">
            <input type="text" id="bulkPinInput" inputmode="numeric" maxlength="4" placeholder="รหัส 4 หลัก"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,4)"
                   class="w-28 px-3 py-2 rounded-xl border border-pink-200 bg-pink-50/50 text-sm font-bold tracking-[0.3em] text-center text-pink-700 outline-none focus:ring-2 focus:ring-pink-300">
            <button type="button" onclick="document.getElementById('bulkPinInput').value=String(Math.floor(Math.random()*10000)).padStart(4,'0')" title="สุ่มรหัส" class="w-9 h-9 rounded-xl bg-gray-50 border border-gray-200 text-gray-500"><i class="fa-solid fa-shuffle"></i></button>
            <button type="button" onclick="applyBulkPin()" class="px-3 py-2 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl">ตั้งรหัสให้ทั้งหมด</button>
            <button type="button" onclick="clearBulkSelection()" title="ยกเลิกการเลือก" class="w-9 h-9 rounded-xl text-gray-400 hover:bg-gray-100"><i class="fa-solid fa-xmark"></i></button>
        </div>
    </div>

    <!-- MODAL: ตั้งรหัสผ่าน 4 หลักของลูกค้า -->
    <div id="pinModal" class="hidden fixed inset-0 z-50 bg-black/40 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-xs rounded-3xl shadow-xl border border-pink-100 p-6 space-y-4 text-center">
            <div class="w-12 h-12 mx-auto rounded-2xl bg-pink-100 text-pink-500 flex items-center justify-center text-lg"><i class="fa-solid fa-key"></i></div>
            <div>
                <h3 class="text-sm font-extrabold text-gray-900">รหัสผ่านลูกค้า 4 หลัก</h3>
                <p id="pinModalEmail" class="text-[11px] text-gray-400 truncate"></p>
            </div>
            <input type="hidden" id="pinModalId">
            <input type="text" id="pinModalInput" inputmode="numeric" maxlength="4" placeholder="••••"
                   oninput="this.value=this.value.replace(/\D/g,'').slice(0,4)"
                   onkeydown="if(event.key==='Enter') savePinModal()"
                   class="w-full px-4 py-3 rounded-2xl border border-pink-200 bg-pink-50/50 text-2xl font-extrabold tracking-[0.5em] text-center text-pink-700 focus:ring-2 focus:ring-pink-400 outline-none">
            <div class="flex items-center justify-between text-[11px] font-bold">
                <button type="button" onclick="document.getElementById('pinModalInput').value=String(Math.floor(Math.random()*10000)).padStart(4,'0')" class="text-purple-600 underline">สุ่มรหัส</button>
                <button type="button" onclick="savePinModal(true)" class="text-rose-500 underline">ลบรหัส</button>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="closePinModal()" class="flex-1 py-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
                <button type="button" onclick="savePinModal()" class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl">บันทึก</button>
            </div>
        </div>
    </div>

    <!-- JavaScript Logic -->
    <script>

        function switchTab(tabName) {
            ['imap', 'apps', 'api'].forEach(t => {
                const content = document.getElementById('tabContent' + t.charAt(0).toUpperCase() + t.slice(1));
                if (content) content.classList.toggle('hidden', t !== tabName);
            });
            document.querySelectorAll('[data-tab]').forEach(btn => {
                const on = btn.dataset.tab === tabName;
                btn.classList.toggle('active', on);
                btn.classList.toggle('inactive', !on);
            });
            window.scrollTo({ top: 0 });
        }

        function showNotification(text) {
            const banner = document.getElementById('noticeBanner');
            const noticeText = document.getElementById('noticeText');
            noticeText.innerText = text;
            banner.classList.remove('hidden');
            setTimeout(() => { banner.classList.add('hidden'); }, 3500);
        }

        // --- Login Handler ---
        async function handleLogin(event) {
            event.preventDefault();
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();

            const formData = new FormData();
            formData.append('username', username);
            formData.append('password', password);

            try {
                const res = await fetch('admin.php?action=login', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'ไม่สามารถเข้าสู่ระบบได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อเครือข่าย');
            }
        }

        // --- IMAP Email Handlers ---
        function onEmailProviderChange() {
            const isGmail = document.getElementById('emailProviderInput').value === 'gmail';
            document.getElementById('passInputContainer').classList.toggle('hidden', !isGmail);
            document.getElementById('imapHostPortContainer').classList.toggle('hidden', !isGmail);
        }
        function openAddEmailModal(kind) {
            if (kind) document.getElementById('emailProviderInput').value = kind;
            onEmailProviderChange();
            document.getElementById('emailModal').classList.remove('hidden');
        }
        function closeEmailModal() {
            document.getElementById('emailModal').classList.add('hidden');
        }

        async function handleSaveEmail(event) {
            event.preventDefault();
            const email = document.getElementById('emailAddrInput').value.trim();
            const password = document.getElementById('emailPassInput').value.trim();
            const host = document.getElementById('emailHostInput').value.trim();
            const port = document.getElementById('emailPortInput').value.trim();
            const pin = document.getElementById('emailPinInput').value.trim();
            const appId = document.getElementById('emailAppInput').value;
            const providerSel = document.getElementById('emailProviderInput').value;

            if (!/^\d{4}$/.test(pin)) {
                alert('กรุณาตั้งรหัสผ่านลูกค้าเป็นตัวเลข 4 หลักค่ะ');
                return;
            }

            const formData = new FormData();
            formData.append('email', email);
            const useImap = providerSel === 'gmail' && password;
            formData.append('password', providerSel === 'gmail' ? password : '');
            formData.append('provider', providerSel === 'other' ? '' : providerSel);
            formData.append('host', useImap ? host : '');
            formData.append('port', port);
            formData.append('access_pin', pin);
            formData.append('app_id', appId);

            try {
                const res = await fetch('admin.php?action=add_imap_email', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    closeEmailModal();
                    showNotification('บันทึกอีเมล IMAP เรียบร้อยแล้วค่ะ!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    alert(data.message || 'ไม่สามารถบันทึกอีเมลได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการส่งข้อมูล');
            }
        }

        async function deleteEmail(id) {
            if (!confirm('ยืนยันการลบอีเมลนี้จากระบบ?')) return;
            const formData = new FormData();
            formData.append('id', id);

            try {
                const res = await fetch('admin.php?action=delete_imap_email', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification('ลบรายการอีเมลเรียบร้อยแล้วค่ะ');
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการลบรายการ');
            }
        }

        async function toggleEmailActive(id, activeState) {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('active', activeState);

            try {
                const res = await fetch('admin.php?action=toggle_email_active', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification(activeState ? 'ปลดล็อกเปิดใช้งานอีเมลแล้วค่ะ' : 'ล็อกการใช้งานอีเมลเรียบร้อยแล้วค่ะ');
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    alert(data.message || 'ไม่สามารถเปลี่ยนสถานะได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        }

        // เปิด/ปิดรับรหัส OTP ของ Netflix ความยาว 4 หรือ 6 หลัก ต่ออีเมล — สลับอิสระคนละปุ่ม (เขียว=เปิด, แดง=ปิด)
        // อัปเดตปุ่มทันทีโดยไม่ต้องรีโหลดหน้า
        async function toggleNetflixOtpDigit(id, digit, btnEl) {
            const newEnabled = btnEl.dataset.enabled !== '1';
            const formData = new FormData();
            formData.append('id', id);
            formData.append('digit', digit);
            formData.append('enabled', newEnabled);

            try {
                const res = await fetch('admin.php?action=toggle_netflix_otp_digit', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    btnEl.dataset.enabled = newEnabled ? '1' : '0';
                    btnEl.classList.toggle('bg-emerald-500', newEnabled);
                    btnEl.classList.toggle('border-emerald-500', newEnabled);
                    btnEl.classList.toggle('text-white', newEnabled);
                    btnEl.classList.toggle('bg-rose-100', !newEnabled);
                    btnEl.classList.toggle('border-rose-200', !newEnabled);
                    btnEl.classList.toggle('text-rose-500', !newEnabled);
                    showNotification(digit === 6
                        ? `รหัส Netflix 6 หลักของอีเมลนี้: ${newEnabled ? 'เปิดให้ลูกค้าเห็น' : 'ปิด (ซ่อนรหัส)'} แล้วค่ะ`
                        : `ลองหารหัส Netflix 4 หลักก่อน: ${newEnabled ? 'เปิด' : 'ปิด'} แล้วค่ะ`);
                } else {
                    alert(data.message || 'ไม่สามารถตั้งค่าได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        }

        // --- รหัสผ่าน 4 หลักของลูกค้า ---
        function editEmailPin(id, email, currentPin) {
            document.getElementById('pinModalId').value = id;
            document.getElementById('pinModalEmail').innerText = email;
            document.getElementById('pinModalInput').value = currentPin || '';
            document.getElementById('pinModal').classList.remove('hidden');
            setTimeout(() => document.getElementById('pinModalInput').focus(), 50);
        }
        function closePinModal() {
            document.getElementById('pinModal').classList.add('hidden');
        }
        async function savePinModal(clear = false) {
            const id = document.getElementById('pinModalId').value;
            const pin = clear ? '' : document.getElementById('pinModalInput').value.trim();
            if (!clear && !/^\d{4}$/.test(pin)) { alert('รหัสผ่านต้องเป็นตัวเลข 4 หลักค่ะ'); return; }
            if (clear && !confirm('ลบรหัสผ่าน? ลูกค้าจะกดขอรหัส OTP ไม่ได้จนกว่าจะตั้งใหม่')) return;
            const formData = new FormData();
            formData.append('id', id);
            formData.append('pin', pin);
            try {
                const res = await fetch('admin.php?action=set_email_pin', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    closePinModal();
                    showNotification(data.message);
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    alert(data.message || 'บันทึกไม่สำเร็จ');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        }

        // --- ติ๊กเลือกหลายอีเมลแล้วตั้งรหัสเดียวกัน ---
        function selectedEmailIds() {
            return [...document.querySelectorAll('.email-select:checked')].map(c => c.value);
        }
        function updateBulkBar() {
            const n = selectedEmailIds().length;
            document.getElementById('bulkCount').innerText = n;
            document.getElementById('bulkBar').classList.toggle('hidden', n === 0);
        }
        function selectAllVisible() {
            document.querySelectorAll('.email-card, .ms-email-card').forEach(card => {
                if (!card.classList.contains('hidden')) {
                    const cb = card.querySelector('.email-select');
                    if (cb) cb.checked = true;
                }
            });
            updateBulkBar();
        }
        function clearBulkSelection() {
            document.querySelectorAll('.email-select').forEach(c => c.checked = false);
            updateBulkBar();
        }
        async function applyBulkPin() {
            const ids = selectedEmailIds();
            const pin = document.getElementById('bulkPinInput').value.trim();
            if (!/^\d{4}$/.test(pin)) { alert('กรุณาใส่รหัสตัวเลข 4 หลักค่ะ'); return; }
            if (!confirm(`ตั้งรหัส ${pin} ให้ ${ids.length} อีเมลที่เลือก?`)) return;
            const formData = new FormData();
            ids.forEach(id => formData.append('ids[]', id));
            formData.append('pin', pin);
            try {
                const res = await fetch('admin.php?action=set_email_pin_bulk', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification(data.message);
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    alert(data.message || 'บันทึกไม่สำเร็จ');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        }

        // --- แยกอีเมลตามแอป ---
        let currentAppFilter = '__all';
        let currentDomainFilter = '__all';
        function setDomainFilter(dm) {
            currentDomainFilter = dm;
            document.querySelectorAll('[data-domain-filter]').forEach(b => b.classList.toggle('active', b.dataset.domainFilter === dm));
            setAppFilter(currentAppFilter);
        }
        function cardMatchesApp(card) {
            if (currentDomainFilter !== '__all' && (card.dataset.domain || '') !== currentDomainFilter) return false;
            if (currentAppFilter === '__all') return true;
            if (currentAppFilter === '__nopin') return card.dataset.haspin !== '1';
            if (currentAppFilter === '__gmail' || currentAppFilter === '__hotmail' || currentAppFilter === '__maily') return card.dataset.type === currentAppFilter.slice(2);
            return (card.dataset.app || 'all') === currentAppFilter;
        }
        function setAppFilter(appId) {
            currentAppFilter = appId;
            document.querySelectorAll('[data-app-filter]').forEach(b => b.classList.toggle('active', b.dataset.appFilter === appId));
            filterEmailList(document.getElementById('emailSearchInput')?.value || '');
            const msInput = document.getElementById('msEmailSearchInput');
            filterMsEmailList(msInput ? msInput.value : '');
        }
        async function setEmailApp(id, appId) {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('app_id', appId);
            try {
                const res = await fetch('admin.php?action=set_email_app', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    document.querySelectorAll('.email-card, .ms-email-card').forEach(c => {
                        if (c.querySelector(`select[onchange*="'${id}'"]`)) c.dataset.app = appId;
                    });
                    showNotification(data.message);
                    setAppFilter(currentAppFilter);
                } else {
                    alert(data.message || 'บันทึกไม่สำเร็จ');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        }

        async function bulkNetflix6(enabled) {
            if (!confirm(enabled ? 'เปิดให้ลูกค้าเห็นรหัส Netflix 6 หลัก ทุกอีเมล?' : 'ปิดรหัส Netflix 6 หลัก ทุกอีเมล? (ลูกค้าจะไม่เห็นรหัส 6 หลัก)')) return;
            const formData = new FormData();
            formData.append('enabled', enabled);
            try {
                const res = await fetch('admin.php?action=bulk_netflix_6digit', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification(data.message);
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    alert(data.message || 'บันทึกไม่สำเร็จ');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        }

        async function importData() {
            const input = document.getElementById('importFileInput');
            if (!input.files.length) { alert('กรุณาเลือกไฟล์ .sql หรือ .json ก่อนค่ะ'); return; }
            if (!confirm('นำเข้าข้อมูลจากไฟล์นี้? (อีเมล/แอป/API ที่ซ้ำกันจะถูกอัปเดตตามไฟล์)')) return;
            const btn = document.getElementById('importBtn');
            btn.disabled = true; btn.innerText = 'กำลังนำเข้า...';
            const formData = new FormData();
            formData.append('file', input.files[0]);
            try {
                const res = await fetch('admin.php?action=import_data', { method: 'POST', body: formData });
                const data = await res.json();
                alert(data.message || (data.success ? 'สำเร็จ' : 'ไม่สำเร็จ'));
                if (data.success) window.location.reload();
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            } finally {
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-upload mr-1"></i> นำเข้าข้อมูล';
            }
        }

        // --- OTP Apps Handlers ---
        function openAddAppModal() {
            document.getElementById('appIdInput').value = '';
            document.getElementById('appNameInput').value = '';
            document.getElementById('appCodeInput').value = '';
            document.getElementById('appKeywordsInput').value = '';
            document.getElementById('appLogoPathInput').value = '';
            document.getElementById('appModalTitle').innerHTML = '<i class="fa-solid fa-mobile-screen-button text-purple-600"></i><span>เพิ่มแอปพลิเคชันใหม่</span>';
            document.getElementById('appLogoPreviewBox').innerHTML = '<i class="fa-solid fa-cube text-gray-400"></i>';
            document.getElementById('appModal').classList.remove('hidden');
        }

        function openEditAppModal(app) {
            document.getElementById('appIdInput').value = app.id || '';
            document.getElementById('appNameInput').value = app.name || '';
            document.getElementById('appCodeInput').value = app.code || '';
            document.getElementById('appKeywordsInput').value = app.keywords || '';
            document.getElementById('appBgColorInput').value = app.bg_color || '#F0F7FF';
            document.getElementById('appBorderColorInput').value = app.border_color || '#D6E9FF';
            document.getElementById('appLogoPathInput').value = app.logo_path || '';
            document.getElementById('appModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-purple-600"></i><span>แก้ไขแอปพลิเคชัน</span>';
            
            if (app.logo_path) {
                document.getElementById('appLogoPreviewBox').innerHTML = `<img src="${app.logo_path}" class="w-full h-full object-contain">`;
            } else {
                document.getElementById('appLogoPreviewBox').innerHTML = '<i class="fa-solid fa-cube text-gray-400"></i>';
            }
            document.getElementById('appModal').classList.remove('hidden');
        }

        function closeAppModal() {
            document.getElementById('appModal').classList.add('hidden');
        }

        async function handleSaveApp(event) {
            event.preventDefault();
            const id = document.getElementById('appIdInput').value;
            const name = document.getElementById('appNameInput').value.trim();
            const code = document.getElementById('appCodeInput').value.trim();
            const keywords = document.getElementById('appKeywordsInput').value.trim();
            const bg_color = document.getElementById('appBgColorInput').value;
            const border_color = document.getElementById('appBorderColorInput').value;
            const logo_path = document.getElementById('appLogoPathInput').value;
            const logoFile = document.getElementById('appLogoFileInput').files[0];

            const formData = new FormData();
            formData.append('id', id);
            formData.append('name', name);
            formData.append('code', code);
            formData.append('keywords', keywords);
            formData.append('bg_color', bg_color);
            formData.append('border_color', border_color);
            formData.append('logo_path', logo_path);
            if (logoFile) formData.append('logo', logoFile);

            try {
                const res = await fetch('admin.php?action=save_app', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    closeAppModal();
                    showNotification('บันทึกแอปพลิเคชันสำเร็จ!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    alert(data.message || 'ไม่สามารถบันทึกแอปพลิเคชันได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
            }
        }

        async function deleteApp(id) {
            if (!confirm('ยืนยันการลบแอปพลิเคชันนี้?')) return;
            const formData = new FormData();
            formData.append('id', id);

            try {
                const res = await fetch('admin.php?action=delete_app', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification('ลบแอปพลิเคชันเรียบร้อยแล้วค่ะ');
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการลบแอปพลิเคชัน');
            }
        }

        // --- Guide Modal Handlers ---
        function openGuideModal(type) {
            const titleEl = document.getElementById('guideModalTitle');
            const imgEl = document.getElementById('guideModalImg');
            const subEl = document.getElementById('guideModalSub');
            
            if (type === 'gmail') {
                titleEl.innerHTML = '<i class="fa-brands fa-google text-red-500"></i><span>วิธีการเข้าและสร้างรหัสผ่านสำหรับแอป (App Password - Gmail)</span>';
                imgEl.src = '/static/guide_gmail.png';
                subEl.innerHTML = 'ลิงก์ตรงสำหรับสร้าง App Password: <a href="https://myaccount.google.com/apppasswords" target="_blank" class="text-purple-600 font-bold underline">myaccount.google.com/apppasswords</a>';
            } else {
                titleEl.innerHTML = '<i class="fa-brands fa-microsoft text-blue-600"></i><span>วิธีเปิดการส่งต่ออีเมล (Forwarding) ใน Outlook.com</span>';
                imgEl.src = '/static/guide_outlook.jpg';
                subEl.innerText = 'ตั้งค่าส่งต่ออีเมลจาก Outlook/Hotmail เข้าไปยัง Gmail';
            }
            document.getElementById('guideModal').classList.remove('hidden');
        }

        function closeGuideModal() {
            document.getElementById('guideModal').classList.add('hidden');
        }

        // --- API Keys Handlers ---
        function openAddApiKeyModal() {
            document.getElementById('apiKeyIdInput').value = '';
            document.getElementById('apiKeyNameInput').value = '';
            document.getElementById('apiKeyValueInput').value = '';
            document.getElementById('apiKeyTypeInput').value = 'maily';
            document.getElementById('apiKeyModalTitle').innerHTML = '<i class="fa-solid fa-key text-purple-600"></i><span>เพิ่ม API Key ใหม่</span>';
            document.getElementById('apiKeyModal').classList.remove('hidden');
        }

        function openEditApiKeyModal(item) {
            document.getElementById('apiKeyIdInput').value = item.id || '';
            document.getElementById('apiKeyNameInput').value = item.name || '';
            document.getElementById('apiKeyValueInput').value = item.token || '';
            document.getElementById('apiKeyTypeInput').value = item.type || 'maily';
            document.getElementById('apiKeyModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-purple-600"></i><span>แก้ไข API Key</span>';
            document.getElementById('apiKeyModal').classList.remove('hidden');
        }

        function closeApiKeyModal() {
            document.getElementById('apiKeyModal').classList.add('hidden');
        }

        async function handleSaveApiKey(event) {
            event.preventDefault();
            const id = document.getElementById('apiKeyIdInput').value;
            const name = document.getElementById('apiKeyNameInput').value.trim();
            const token = document.getElementById('apiKeyValueInput').value.trim();
            const type = document.getElementById('apiKeyTypeInput').value;

            const formData = new FormData();
            formData.append('id', id);
            formData.append('name', name);
            formData.append('token', token);
            formData.append('type', type);

            try {
                const res = await fetch('admin.php?action=save_api_key', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    closeApiKeyModal();
                    showNotification('บันทึก API Key สำเร็จ!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    alert(data.message || 'ไม่สามารถบันทึก API Key ได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
            }
        }

        async function deleteApiKey(id) {
            if (!confirm('ยืนยันการลบ API Key นี้?')) return;
            const formData = new FormData();
            formData.append('id', id);

            try {
                const res = await fetch('admin.php?action=delete_api_key', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification('ลบ API Key เรียบร้อยแล้วค่ะ');
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการลบรายการ');
            }
        }

        // --- Logo & Settings Update ---
        async function uploadLogoFile() {
            const logoFileInput = document.getElementById('logoFileInput');
            const file = logoFileInput.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('logo', file);

            try {
                const res = await fetch('admin.php?action=upload_logo', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification('อัปโหลดโลโก้สำเร็จ!');
                    document.getElementById('dashboardLogoPreview').src = `${data.logo_path}?t=${Date.now()}`;
                    document.getElementById('headerLogo').src = `${data.logo_path}?t=${Date.now()}`;
                } else {
                    alert(data.message || 'อัปโหลดรูปไม่สำเร็จ');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดเครือข่ายระหว่างอัปโหลด');
            }
        }

        async function handleSettingsUpdate(event) {
            event.preventDefault();
            const shop_name = document.getElementById('shopNameInput').value.trim();
            const username = document.getElementById('newUsernameInput').value.trim();
            const password = document.getElementById('newPasswordInput').value.trim();

            const formData = new FormData();
            formData.append('shop_name', shop_name);
            formData.append('username', username);
            formData.append('password', password);

            try {
                const res = await fetch('admin.php?action=update', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification('บันทึกการตั้งค่าร้านค้าและรหัสผ่านเรียบร้อยแล้วค่ะ');
                    document.getElementById('headerShopName').innerText = shop_name;
                    document.getElementById('newUsernameInput').value = '';
                    document.getElementById('newPasswordInput').value = '';
                } else {
                    alert(data.message || 'ไม่สามารถอัปเดตการตั้งค่าได้');
                }
            } catch (err) {
                alert('เกิดความขัดข้องในการบันทึกข้อมูล');
            }
        }
        async function handleSaveMicrosoftOAuth() {
            const client_id = document.getElementById('msClientIdInput').value.trim();
            const client_secret = document.getElementById('msClientSecretInput').value.trim();

            if (!client_id || !client_secret) {
                alert('กรุณากรอก Client ID และ Client Secret ให้ครบ');
                return;
            }

            const formData = new FormData();
            formData.append('client_id', client_id);
            formData.append('client_secret', client_secret);

            try {
                const res = await fetch('admin.php?action=save_microsoft_oauth', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showNotification('บันทึกการตั้งค่า Microsoft App เรียบร้อยแล้วค่ะ');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    alert(data.message || 'ไม่สามารถบันทึกการตั้งค่าได้');
                }
            } catch (err) {
                alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
            }
        }

        // เช็ค query string หลังเชื่อมต่อ Microsoft OAuth กลับมา แล้วสลับไปแท็บ API + โชว์แจ้งเตือนให้อัตโนมัติ
        (function checkMicrosoftOAuthResult() {
            const params = new URLSearchParams(window.location.search);
            const msConnected = params.get('ms_connected');
            const msError = params.get('ms_error');
            if (!msConnected && !msError) return;

            // ลบ ms_connected/ms_error ออกจาก URL ทันที ไม่งั้นกด refresh ทีไรจะเจอ alert เดิมซ้ำทุกครั้ง
            const cleanUrl = window.location.pathname + window.location.hash;
            window.history.replaceState({}, document.title, cleanUrl);

            document.addEventListener('DOMContentLoaded', () => {
                if (typeof switchTab === 'function') switchTab('imap');
                if (msConnected) {
                    showNotification('เชื่อมต่อบัญชี ' + decodeURIComponent(msConnected) + ' ผ่าน Microsoft สำเร็จแล้วค่ะ');
                } else if (msError) {
                    alert('เชื่อมต่อ Microsoft ไม่สำเร็จ: ' + decodeURIComponent(msError));
                }
            });
        })();

        function filterEmailList(query) {
            const cleanQuery = query.trim().toLowerCase();
            const cards = document.querySelectorAll('.email-card');
            const clearBtn = document.getElementById('clearEmailSearchBtn');
            const countEl = document.getElementById('emailSearchCount');
            const noResultEl = document.getElementById('noEmailSearchResult');
            
            if (clearBtn) {
                if (cleanQuery.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            let visibleCount = 0;
            cards.forEach(card => {
                const email = card.getAttribute('data-email') || '';
                if ((!cleanQuery || email.includes(cleanQuery)) && cardMatchesApp(card)) {
                    card.classList.remove('hidden');
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });

            if (countEl) {
                countEl.innerText = `${visibleCount} รายการ`;
            }

            if (noResultEl) {
                if (visibleCount === 0 && cards.length > 0) {
                    noResultEl.classList.remove('hidden');
                } else {
                    noResultEl.classList.add('hidden');
                }
            }
        }

        function clearEmailSearch() {
            const input = document.getElementById('emailSearchInput');
            if (input) {
                input.value = '';
                filterEmailList('');
            }
        }

        function filterMsEmailList(query) {
            const cleanQuery = query.trim().toLowerCase();
            const cards = document.querySelectorAll('.ms-email-card');
            const clearBtn = document.getElementById('clearMsEmailSearchBtn');
            const countEl = document.getElementById('msEmailSearchCount');
            const noResultEl = document.getElementById('noMsEmailSearchResult');

            if (clearBtn) {
                if (cleanQuery.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            let visibleCount = 0;
            cards.forEach(card => {
                const email = card.getAttribute('data-email') || '';
                if ((!cleanQuery || email.includes(cleanQuery)) && cardMatchesApp(card)) {
                    card.classList.remove('hidden');
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });

            if (countEl) {
                countEl.innerText = `${visibleCount} รายการ`;
            }

            if (noResultEl) {
                if (visibleCount === 0 && cards.length > 0) {
                    noResultEl.classList.remove('hidden');
                } else {
                    noResultEl.classList.add('hidden');
                }
            }
        }

        function clearMsEmailSearch() {
            const input = document.getElementById('msEmailSearchInput');
            if (input) {
                input.value = '';
                filterMsEmailList('');
            }
        }
    </script>
</body>
</html>

