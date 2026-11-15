<?php
// =========================================================================
// renew_lib.php — ระบบ "ต่อเมล" (ต่อเมลลูกค้าเข้า fam) + แจ้งเตือน/จ่ายเงินผ่าน LINE OA + ตรวจสลิป Slip2Go
//
// ภาพรวม:
//   แอดมินเพิ่มลูกค้าในหลังบ้าน (เมลลูกค้า · เมลร้าน fam · แพ็กเกจ · วันหมดอายุ) → ได้รหัสผูกแชท เช่น R48213
//   ลูกค้าพิมพ์รหัสในแชท LINE ร้าน → ผูกบัญชี LINE กับรายการ
//   ก่อนหมดอายุ N วัน (renew_cron.php) ระบบส่งการ์ดเตือนทาง LINE พร้อมปุ่มแพ็กเกจ
//   ลูกค้ากดแพ็กเกจ → ได้ QR พร้อมเพย์ยอดพอดี → ส่งรูปสลิปในแชท → Slip2Go ตรวจ → ระบบเลื่อนวันหมดอายุให้เอง
//   เลยกำหนดแล้วไม่ต่อ → แจ้งแอดมินให้ลบเมลออกจาก fam
//
// ข้อมูลเก็บในฐานข้อมูลเดียวกับระบบ OTP (MySQL/TiDB) · โหมดทดลองในเครื่อง (OTP_LOCAL=1) ใช้ SQLite แทน
// =========================================================================

require_once __DIR__ . '/config_store.php';

const RENEW_QR_TTL      = 15 * 60;   // QR ใช้ได้ 15 นาที
const RENEW_SLIP_GRACE  = 15 * 60;   // ส่งสลิปได้ถึง 15 นาทีหลัง QR หมดอายุ (โอนทันแต่ส่งช้า)
const RENEW_MAX_ATTEMPT = 5;         // ส่งสลิปผิดได้กี่ครั้งต่อ 1 QR

// ---------------------------------------------------------------- ฐานข้อมูล
function renew_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (OTP_LOCAL_MODE) {
        $pdo = new PDO('sqlite:' . __DIR__ . '/local_test/renew.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $pdo = db_connect();
    }
    renew_schema($pdo);
    return $pdo;
}

function renew_is_mysql($pdo) {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function renew_schema($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $tail = renew_is_mysql($pdo) ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $tables = [
        "CREATE TABLE IF NOT EXISTS renew_kv (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NULL
        )",
        "CREATE TABLE IF NOT EXISTS renew_packages (
            id     VARCHAR(32) NOT NULL PRIMARY KEY,
            name   VARCHAR(191) NOT NULL,
            price  DECIMAL(10,2) NOT NULL DEFAULT 0,
            days   INT NOT NULL DEFAULT 30,
            active INT NOT NULL DEFAULT 1,
            sort   INT NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS renewals (
            id             VARCHAR(32) NOT NULL PRIMARY KEY,
            customer_email VARCHAR(191) NOT NULL,
            shop_email     VARCHAR(191) NOT NULL DEFAULT '',
            package_id     VARCHAR(32) NOT NULL DEFAULT '',
            line_user_id   VARCHAR(64) NULL,
            line_name      VARCHAR(191) NOT NULL DEFAULT '',
            link_code      VARCHAR(10) NOT NULL,
            expires_on     VARCHAR(10) NOT NULL,
            note           VARCHAR(500) NOT NULL DEFAULT '',
            status         VARCHAR(16) NOT NULL DEFAULT 'active',
            reminded_for   VARCHAR(10) NULL,
            lapsed_for     VARCHAR(10) NULL,
            removed_at     INT NULL,
            renewed_count  INT NOT NULL DEFAULT 0,
            created_at     INT NOT NULL DEFAULT 0,
            updated_at     INT NOT NULL DEFAULT 0,
            UNIQUE (link_code)
        )",
        "CREATE TABLE IF NOT EXISTS renew_payments (
            id           VARCHAR(32) NOT NULL PRIMARY KEY,
            renewal_id   VARCHAR(32) NOT NULL,
            package_id   VARCHAR(32) NOT NULL,
            line_user_id VARCHAR(64) NOT NULL,
            amount       DECIMAL(10,2) NOT NULL,
            fee          DECIMAL(10,2) NOT NULL DEFAULT 0,
            readd        INT NOT NULL DEFAULT 0,
            status       VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts     INT NOT NULL DEFAULT 0,
            created_at   INT NOT NULL,
            expires_at   INT NOT NULL,
            paid_at      INT NULL,
            trans_ref    VARCHAR(64) NULL,
            last_error   VARCHAR(500) NULL,
            UNIQUE (trans_ref)
        )",
        "CREATE TABLE IF NOT EXISTS renew_contacts (
            line_user_id VARCHAR(64) NOT NULL PRIMARY KEY,
            display_name VARCHAR(191) NOT NULL DEFAULT '',
            is_admin     INT NOT NULL DEFAULT 0,
            last_seen    INT NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS renew_fams (
            email      VARCHAR(191) NOT NULL PRIMARY KEY,
            created_at INT NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS renew_files (
            k          VARCHAR(64) NOT NULL PRIMARY KEY,
            mime       VARCHAR(40) NOT NULL,
            data       MEDIUMTEXT NOT NULL,
            updated_at INT NOT NULL DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS renew_log (
            id         VARCHAR(32) NOT NULL PRIMARY KEY,
            created_at INT NOT NULL,
            title      VARCHAR(191) NOT NULL,
            body       TEXT NULL
        )",
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql . $tail);
    }
}

function renew_id($prefix = '') {
    return $prefix . bin2hex(random_bytes(8));
}

// ---------------------------------------------------------------- ตั้งค่า (key-value)
function renew_setting_defaults() {
    return [
        'line_token'       => '',
        'line_secret'      => '',
        'line_oa_id'       => '',      // เช่น @monalishop (ใช้ทำลิงก์แอดเพื่อน)
        'forward_webhook'  => '',      // Webhook เดิมของ OA (เช่น Slip2Go) — ส่งต่อเหตุการณ์ที่ระบบนี้ไม่ได้ใช้
        'slip_mode'        => 'chat',  // chat = ให้ Slip2Go ในแชทของร้านตรวจ แล้วแอดมินกดยืนยัน · api = ระบบตรวจผ่าน Slip2Go API แล้วต่อให้เอง
        'slip2go_url'      => '',      // Base URL จากหน้า Slip2Go (API Connect)
        'slip2go_secret'   => '',
        'promptpay_id'     => '',      // เบอร์ 10 หลัก / เลข 13 หลัก / e-Wallet 15 หลัก
        'pay_name'         => '',      // ชื่อบัญชีผู้รับ (แสดงให้ลูกค้าเห็น)
        'receiver_type'    => '',      // Slip2Go checkReceiver (ไม่บังคับ) เช่น 02001 = พร้อมเพย์เบอร์มือถือ
        'receiver_account' => '',      // หลายบัญชีได้: "01004:1848641592, 04000:0813154812" (ประเภท:เลข) — ตรงบัญชีใดบัญชีหนึ่ง = ผ่าน
        'amount_extra'     => '5',     // รับยอดในสลิปเกินได้ไม่เกินกี่บาท (เช่น โอนผ่าน Wallet +5)
        'card_style'       => 'image', // image = การ์ดรูปของร้าน (เติมเมล/วันที่ลงในรูป) · basic = การ์ดข้อความ + ปุ่มแพ็กเกจ
        'pay_before_days'  => '2',
        'pay_text'         => '1',     // 1 = ส่งข้อความบอกยอดตามหลังรูปวิธีชำระเงิน · 0 = ส่งแค่รูป
        'pay_style'        => 'static', // static = ส่งรูปวิธีชำระเงินของร้านตามเดิม (QR/บัญชีในรูป) · image = รูปของร้าน + QR ยอดพอดีวางทับ · qr = รูป QR ยอดพอดีอย่างเดียว
        'remind_days'      => '3',
        'grace_days'       => '3',
        'late_fee'         => '10',
        'removed_fee'      => '20',
        'fam_size'         => '5',
        'cron_key'         => '',
        'admin_code'       => '',
    ];
}

function renew_settings($fresh = false) {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $s = renew_setting_defaults();
    foreach (renew_db()->query("SELECT k, v FROM renew_kv")->fetchAll() as $row) {
        if (array_key_exists($row['k'], $s)) $s[$row['k']] = (string)$row['v'];
    }
    // สร้างรหัสลับครั้งแรกให้อัตโนมัติ
    if ($s['cron_key'] === '')   { $s['cron_key'] = bin2hex(random_bytes(12)); renew_set('cron_key', $s['cron_key']); }
    if ($s['admin_code'] === '') { $s['admin_code'] = 'ADMIN-' . strtoupper(bin2hex(random_bytes(3))); renew_set('admin_code', $s['admin_code']); }
    $cache = $s;
    return $s;
}

function renew_set($k, $v) {
    $db = renew_db();
    $exists = $db->prepare("SELECT 1 FROM renew_kv WHERE k = ?");
    $exists->execute([$k]);
    if ($exists->fetch()) {
        $db->prepare("UPDATE renew_kv SET v = ? WHERE k = ?")->execute([(string)$v, $k]);
    } else {
        $db->prepare("INSERT INTO renew_kv (k, v) VALUES (?, ?)")->execute([$k, (string)$v]);
    }
}

function renew_unset($k) {
    renew_db()->prepare("DELETE FROM renew_kv WHERE k = ?")->execute([$k]);
}

// ---------------------------------------------------------------- ข้อความที่ส่งให้ลูกค้า (แอดมินแก้เองได้ในหน้าแอดมิน)
// {ตัวแปร} ในข้อความจะถูกแทนด้วยข้อมูลจริงตอนส่ง · ว่าง = ใช้ข้อความตั้งต้น
function renew_msg_defs() {
    return [
        'msg_code' => ['label' => 'ข้อความส่งรหัสผูกแชท', 'when' => 'แอดมินคัดลอกไปส่งให้ลูกค้า (ตอนเพิ่มลูกค้า / กด "ส่งรหัส")',
            'vars' => ['code', 'email', 'add_friend'],
            'default' => "พิมพ์รหัส {code} ในแชท LINE ร้าน เพื่อรับแจ้งเตือนและต่อเมล {email} อัตโนมัติก่อนหมดอายุค่ะ{add_friend}"],
        'msg_linked' => ['label' => 'ผูกบัญชีสำเร็จ', 'when' => 'ลูกค้าพิมพ์รหัสในแชทแล้ว (ส่งคู่กับการ์ด)',
            'vars' => ['email', 'expiry', 'remind_days'],
            'default' => "ผูกบัญชีเรียบร้อยค่ะ ✅\nระบบจะแจ้งเตือนในแชทนี้ก่อนหมดอายุ {remind_days} วัน และกดต่ออายุจากการ์ดได้เลยค่ะ"],
        'msg_pay' => ['label' => 'ข้อความบอกยอดโอน', 'when' => 'ลูกค้ากด "กดต่ออายุ" (ส่งต่อจากรูปวิธีชำระเงิน)',
            'vars' => ['email', 'package', 'days', 'amount', 'fee_line', 'expiry', 'pay_hint', 'after_slip'],
            'default' => "💳 ต่ออายุ {email}\nแพ็กเกจ: {package} ({days} วัน)\n{fee_line}ยอดโอน: {amount}\n\n{pay_hint} แล้ว \"ส่งรูปสลิป\" ในแชทนี้ได้เลยค่ะ {after_slip}"],
        'msg_renewed' => ['label' => 'ต่ออายุสำเร็จ', 'when' => 'ตรวจสลิปผ่าน / แอดมินกดยืนยันรับเงิน / แอดมินกดต่อเอง (ติ๊กแจ้งลูกค้า)',
            'vars' => ['email', 'expiry', 'package', 'readd_line'],
            'default' => "✅ ต่ออายุเรียบร้อยค่ะ\nเมล: {email}\nใช้ได้ถึง {expiry}{readd_line}\n\nขอบคุณที่ใช้บริการนะคะ 💖"],
        'msg_slip_received' => ['label' => 'ได้รับสลิป — รอแอดมินอนุมัติ', 'when' => 'ลูกค้าส่งสลิปค่าต่อเมล (แบบแอดมินกดยืนยัน) — Slip2Go ในแชทยังตรวจและตอบตามปกติ',
            'vars' => ['email', 'amount'],
            'default' => "ได้รับสลิปแล้วค่ะ 🙏\nรอแอดมินอนุมัติการต่ออายุสักครู่นะคะ เมื่อต่อเรียบร้อยระบบจะแจ้งในแชทนี้ค่ะ"],
        'msg_slip_failed' => ['label' => 'สลิปไม่ผ่าน (ตรวจอัตโนมัติ)', 'when' => 'ระบบตรวจสลิปผ่าน Slip2Go API แล้วไม่ผ่าน',
            'vars' => ['reason', 'email', 'amount'],
            'default' => "❌ {reason}\n\nหากโอนแล้วจริง กรุณาทักแอดมินพร้อมสลิปค่ะ"],
        'msg_slip_manual' => ['label' => 'ระบบตรวจสลิปขัดข้อง', 'when' => 'Slip2Go API ใช้ไม่ได้ (key ผิด / token หมด) — แอดมินจะตรวจเอง',
            'vars' => ['email', 'amount'],
            'default' => "ได้รับสลิปแล้วค่ะ ระบบตรวจอัตโนมัติขัดข้อง แอดมินจะตรวจและต่ออายุให้เร็วที่สุดนะคะ 🙏"],
        'msg_slip_rejected' => ['label' => 'แอดมินกด "สลิปไม่ผ่าน"', 'when' => 'แอดมินกดสลิปไม่ผ่านในหลังบ้าน',
            'vars' => ['email', 'amount'],
            'default' => "❌ สลิปค่าต่ออายุยังไม่ผ่านการตรวจค่ะ กรุณาทักแอดมินพร้อมสลิป หรือพิมพ์ \"ต่อเมล\" เพื่อทำรายการใหม่"],
        'msg_stop_confirm' => ['label' => 'ถามยืนยันไม่ต่ออายุ (ไม่เกิน 240 ตัวอักษร)', 'when' => 'ลูกค้ากด "ไม่ต้องการต่ออายุแล้ว" (มีปุ่ม ยืนยันไม่ต่อ / ยกเลิก)',
            'vars' => ['email', 'expiry'],
            'default' => "ยืนยันไม่ต่ออายุ\n{email} ใช่ไหมคะ?"],
        'msg_stopped' => ['label' => 'รับทราบไม่ต่ออายุ', 'when' => 'ลูกค้ากด "ยืนยันไม่ต่อ"',
            'vars' => ['email', 'expiry'],
            'default' => "รับทราบค่ะ ระบบจะไม่แจ้งเตือนต่ออายุ {email} อีก\nใช้งานได้ถึง {expiry}\n\nขอบคุณที่ใช้บริการนะคะ 💖"],
        'msg_follow' => ['label' => 'ทักทายตอนแอดเพื่อน', 'when' => 'ลูกค้าแอด LINE ร้าน (เฉพาะตอนไม่ได้ส่งต่อ Webhook ให้บริการเดิม)',
            'vars' => [],
            'default' => "ขอบคุณที่เพิ่มเพื่อนนะคะ 💖\nลูกค้าต่อเมล: พิมพ์รหัสที่ได้รับจากแอดมิน (เช่น R12345) เพื่อรับแจ้งเตือนก่อนหมดอายุ และต่อเมลในแชทนี้ได้เลยค่ะ"],
        'msg_no_renewal' => ['label' => 'พิมพ์ "ต่อเมล" แต่ยังไม่ผูกบัญชี', 'when' => 'ลูกค้าพิมพ์ ต่อเมล / เช็ควันหมด แต่ยังไม่มีรายการ',
            'vars' => [],
            'default' => "ยังไม่มีรายการต่อเมลที่ผูกกับบัญชีนี้ค่ะ กรุณาพิมพ์รหัสที่ได้รับจากแอดมิน (เช่น R12345)"],
    ];
}

function renew_msg_custom() {
    static $custom = null;
    if ($custom === null) {
        $custom = [];
        foreach (renew_db()->query("SELECT k, v FROM renew_kv WHERE k LIKE 'msg_%'")->fetchAll() as $row) $custom[$row['k']] = (string)$row['v'];
    }
    return $custom;
}

function renew_msg($key, $vars = []) {
    $defs = renew_msg_defs();
    $t = trim(renew_msg_custom()[$key] ?? '');
    if ($t === '') $t = $defs[$key]['default'] ?? '';
    $map = [];
    foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string)$v;
    return strtr($t, $map);
}

// ตัวแปรของข้อความเกี่ยวกับรายการต่อเมล
function renew_msg_vars($r, $extra = []) {
    return array_merge([
        'email' => $r['customer_email'] ?? '', 'expiry' => isset($r['expires_on']) ? renew_date_th($r['expires_on']) : '',
        'remind_days' => (int)renew_num('remind_days'), 'readd_line' => '',
    ], $extra);
}

function renew_readd_line($readd) {
    return $readd ? "\n\nแอดมินจะเพิ่มเมลกลับเข้า fam ให้เร็ว ๆ นี้นะคะ" : '';
}

function renew_num($key) {
    $v = (float)(renew_settings()[$key] ?? 0);
    return $v < 0 ? 0 : $v;
}

// ---------------------------------------------------------------- วันที่ (เวลาไทย)
function renew_today($offset_days = 0) {
    $d = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    if ($offset_days) $d->modify(($offset_days > 0 ? '+' : '') . (int)$offset_days . ' days');
    return $d->format('Y-m-d');
}

function renew_add_days($ymd, $days) {
    $d = new DateTime($ymd, new DateTimeZone('Asia/Bangkok'));
    $d->modify(($days >= 0 ? '+' : '') . (int)$days . ' days');
    return $d->format('Y-m-d');
}

function renew_days_left($ymd) {
    $a = new DateTime(renew_today(), new DateTimeZone('UTC'));
    $b = new DateTime($ymd, new DateTimeZone('UTC'));
    return (int)round(($b->getTimestamp() - $a->getTimestamp()) / 86400);
}

function renew_date_th($ymd, $with_year = true) {
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    [$y, $mo, $d] = array_map('intval', explode('-', $ymd) + [0, 0, 0]);
    if (!$y) return $ymd;
    return $d . ' ' . $m[$mo] . ($with_year ? ' ' . ($y + 543) : '');
}

function renew_baht($n) {
    $n = (float)$n;
    return '฿' . (floor($n) == $n ? number_format($n) : number_format($n, 2));
}

// ---------------------------------------------------------------- แพ็กเกจ / รายการ
function renew_packages($only_active = false) {
    $sql = "SELECT * FROM renew_packages" . ($only_active ? " WHERE active = 1" : "") . " ORDER BY sort, name";
    return renew_db()->query($sql)->fetchAll();
}

function renew_package($id) {
    $st = renew_db()->prepare("SELECT * FROM renew_packages WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function renew_get($id) {
    $st = renew_db()->prepare("SELECT * FROM renewals WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function renew_new_link_code() {
    $db = renew_db();
    for ($i = 0; $i < 20; $i++) {
        $code = 'R' . random_int(10000, 99999);
        $st = $db->prepare("SELECT 1 FROM renewals WHERE link_code = ?");
        $st->execute([$code]);
        if (!$st->fetch()) return $code;
    }
    return 'R' . random_int(100000, 999999);
}

// วันสุดท้ายที่ต่อราคาปกติ = วันหมดอายุ + grace
function renew_deadline($r) {
    return renew_add_days($r['expires_on'], (int)renew_num('grace_days'));
}

// ค่าธรรมเนียมตามสถานะตอนนี้
function renew_fee($r) {
    if (!empty($r['removed_at'])) return ['fee' => renew_num('removed_fee'), 'why' => 'ต่อหลังลบเมล', 'readd' => true];
    if (renew_today() > renew_deadline($r)) return ['fee' => renew_num('late_fee'), 'why' => 'ต่อช้า', 'readd' => false];
    return ['fee' => 0, 'why' => '', 'readd' => false];
}

// แพ็กเกจที่ลูกค้าเลือกต่อได้: แพ็กเดิมก่อน + แพ็กอื่นที่เปิดอยู่ (สูงสุด 4 ปุ่ม)
function renew_choices($r) {
    $all = renew_packages(true);
    $cur = [];
    $others = [];
    foreach ($all as $p) {
        if ($p['id'] === $r['package_id']) $cur[] = $p + ['current' => true];
        else $others[] = $p + ['current' => false];
    }
    return array_slice(array_merge($cur, $others), 0, 4);
}

// เลื่อนวันหมดอายุ (ใช้ทั้งตอนจ่ายผ่าน LINE และแอดมินกด "ต่อเอง")
function renew_extend($renewal_id, $package_id, $days) {
    $db = renew_db();
    $r = renew_get($renewal_id);
    if (!$r) return null;
    $base = max($r['expires_on'], renew_today());
    $new_exp = renew_add_days($base, (int)$days);
    $db->prepare("UPDATE renewals SET expires_on = ?, package_id = ?, renewed_count = renewed_count + 1,
                  reminded_for = NULL, lapsed_for = NULL, removed_at = NULL, status = 'active', updated_at = ? WHERE id = ?")
       ->execute([$new_exp, $package_id ?: $r['package_id'], time(), $renewal_id]);
    return $new_exp;
}

// ---------------------------------------------------------------- บันทึก + แจ้งแอดมิน
function renew_log($title, $lines = []) {
    try {
        renew_db()->prepare("INSERT INTO renew_log (id, created_at, title, body) VALUES (?, ?, ?, ?)")
            ->execute([renew_id(), time(), mb_substr($title, 0, 190), implode("\n", $lines)]);
        // เก็บแค่ 300 รายการล่าสุด
        if (random_int(1, 20) === 1) {
            $cut = renew_db()->query("SELECT created_at FROM renew_log ORDER BY created_at DESC LIMIT 1 OFFSET 300")->fetchColumn();
            if ($cut) renew_db()->prepare("DELETE FROM renew_log WHERE created_at < ?")->execute([$cut]);
        }
    } catch (Throwable $e) {
        error_log('[renew] log failed: ' . $e->getMessage());
    }
}

// แจ้งแอดมิน: บันทึกในหลังบ้าน + ส่ง LINE หาแอดมินทุกคนที่พิมพ์รหัสแอดมินในแชทไว้
function renew_notify_admin($title, $lines = []) {
    renew_log($title, $lines);
    $admins = renew_db()->query("SELECT line_user_id FROM renew_contacts WHERE is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $uid) {
        line_push($uid, [['type' => 'text', 'text' => mb_substr("🔔 $title\n" . implode("\n", $lines), 0, 4900)]]);
    }
}

// ---------------------------------------------------------------- LINE Messaging API
function line_api($method, $path, $body = null, $host = 'api.line.me') {
    $token = renew_settings()['line_token'];
    if ($token === '') return [0, null];
    $ch = curl_init("https://$host/v2/bot/$path");
    $headers = ['Authorization: Bearer ' . $token];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300) error_log("[line] $method $path HTTP $http: " . substr((string)$res, 0, 300));
    return [$http, $res];
}

function line_reply($reply_token, $messages) {
    if (!$messages) return false;
    [$http] = line_api('POST', 'message/reply', ['replyToken' => $reply_token, 'messages' => array_slice($messages, 0, 5)]);
    return $http === 200;
}

function line_push($to, $messages) {
    if (!$to || !$messages) return false;
    [$http] = line_api('POST', 'message/push', ['to' => $to, 'messages' => array_slice($messages, 0, 5)]);
    return $http === 200;
}

function line_profile_name($uid) {
    [$http, $res] = line_api('GET', 'profile/' . rawurlencode($uid));
    return $http === 200 ? (string)(json_decode($res, true)['displayName'] ?? '') : '';
}

// ดาวน์โหลดรูปที่ลูกค้าส่งมาในแชท (สลิป)
function line_content($message_id) {
    [$http, $res] = line_api('GET', 'message/' . rawurlencode($message_id) . '/content', null, 'api-data.line.me');
    return ($http === 200 && $res !== false && strlen($res) > 0 && strlen($res) <= 10 * 1024 * 1024) ? $res : null;
}

function line_signature_ok($raw, $sig) {
    $secret = renew_settings()['line_secret'];
    if ($secret === '' || !$sig) return false;
    return hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $sig);
}

function line_text($t) {
    return ['type' => 'text', 'text' => mb_substr($t, 0, 4900)];
}

// ---------------------------------------------------------------- การ์ดเตือนต่ออายุ (Flex Message)
function renew_flex($r, $title = null) {
    $st = renew_settings();
    $left = renew_days_left($r['expires_on']);
    $when = $left > 0 ? 'หมดอายุ ' . renew_date_th($r['expires_on']) . " (อีก $left วัน)"
        : ($left === 0 ? 'หมดอายุวันนี้ (' . renew_date_th($r['expires_on']) . ')' : 'หมดอายุแล้วเมื่อ ' . renew_date_th($r['expires_on']));
    $f = renew_fee($r);
    $pkg = renew_package($r['package_id']);
    $product = $pkg['name'] ?? 'บริการ';
    $line = function ($s, $o = []) { return ['type' => 'text', 'text' => $s, 'size' => 'sm', 'wrap' => true] + $o; };
    $deadline = renew_date_th(renew_deadline($r), false);
    $late = renew_num('late_fee');
    $removed = renew_num('removed_fee');

    if (!empty($r['removed_at'])) {
        $terms = [$line("เมลถูกลบออกจาก fam แล้ว ต่อตอนนี้ +" . renew_baht($removed) . " (ร้านเพิ่มเมลกลับให้)", ['color' => '#d14343', 'weight' => 'bold'])];
    } elseif ($f['fee'] > 0) {
        $terms = [$line("เลยกำหนดต่อ ($deadline) แล้ว ต่อตอนนี้ +" . renew_baht($late), ['color' => '#d14343', 'weight' => 'bold'])];
        if ($removed > 0) $terms[] = $line("หากแจ้งหลังจากลบเมลแล้ว +" . renew_baht($removed), ['size' => 'xs', 'color' => '#8a8a8a']);
    } else {
        $terms = [$line("แจ้งต่อภายใน $deadline นี้นะคะ", ['weight' => 'bold', 'color' => '#ec4899'])];
        if ($late > 0)    $terms[] = $line("หากแจ้งต่อหลังจากนี้ +" . renew_baht($late), ['size' => 'xs', 'color' => '#8a8a8a']);
        if ($removed > 0) $terms[] = $line("แจ้งหลังจากลบเมลแล้ว +" . renew_baht($removed), ['size' => 'xs', 'color' => '#8a8a8a']);
    }

    $buttons = [];
    foreach (renew_choices($r) as $c) {
        $total = (float)$c['price'] + $f['fee'];
        $label = ($c['current'] ? 'ต่อ ' : 'เปลี่ยนเป็น ') . $c['name'] . ' ' . renew_baht($total);
        $buttons[] = [
            'type' => 'button', 'height' => 'sm', 'style' => $c['current'] ? 'primary' : 'secondary',
            'color' => $c['current'] ? '#ec4899' : null,
            'action' => [
                'type' => 'postback', 'label' => mb_substr($label, 0, 40),
                'data' => 'a=rn&r=' . $r['id'] . '&p=' . $c['id'],
                'displayText' => 'ต่ออายุ ' . $c['name'] . ' ' . renew_baht($total) . ($f['fee'] ? " (รวม{$f['why']} " . renew_baht($f['fee']) . ')' : ''),
            ],
        ];
    }
    $buttons = array_map(function ($b) { if ($b['color'] === null) unset($b['color']); return $b; }, $buttons);
    if (!$buttons) $buttons[] = ['type' => 'text', 'text' => 'ยังไม่มีแพ็กเกจให้เลือก กรุณาทักแอดมินค่ะ', 'size' => 'sm', 'wrap' => true, 'color' => '#8a8a8a'];

    $body = [
        ['type' => 'text', 'text' => $title ?: ($left < 0 ? "⌛ $product หมดอายุแล้ว" : "⏰ $product ใกล้หมดอายุ"), 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
    ];
    if ($r['shop_email'] !== '') $body[] = $line('fam: ' . $r['shop_email']);
    $body[] = $line('เมล: ' . $r['customer_email']);
    $body[] = $line($when, ['color' => $left <= (int)$st['remind_days'] ? '#d14343' : '#8a8a8a']);
    $body[] = ['type' => 'separator', 'margin' => 'md'];
    $body[] = ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'xs', 'margin' => 'md', 'contents' => $terms];

    return [
        'type' => 'bubble', 'size' => 'mega',
        'body' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => $body],
        'footer' => ['type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm', 'contents' => $buttons],
    ];
}

// ---------------------------------------------------------------- การ์ดรูปของร้าน (รูปพื้นหลัง + เติมข้อความลงช่อง)
// รูปที่แอดมินอัปโหลดเอง (เก็บในฐานข้อมูล) หรือรูปตั้งต้น static/renew_card.jpg
// รูปที่แอดมินอัปโหลดเอง: k = card (การ์ดเตือน) / pay (หน้าวิธีชำระเงิน)
const RENEW_IMAGE_DEFAULTS = ['card' => 'static/renew_card.jpg', 'pay' => 'static/renew_pay.jpg'];

function renew_file($k) {
    $st = renew_db()->prepare("SELECT mime, data, updated_at FROM renew_files WHERE k = ?");
    $st->execute([$k]);
    return $st->fetch() ?: null;
}

function renew_card_custom() {
    return renew_file('card');
}

// path สัมพัทธ์ (ใช้ในหน้าแอดมิน) ของรูปที่ใช้อยู่ตอนนี้
function renew_image_path($k) {
    $c = renew_file($k);
    return $c ? 'renew_card.php?k=' . $k . '&v=' . (int)$c['updated_at'] : RENEW_IMAGE_DEFAULTS[$k];
}

function renew_card_image_url() {
    return renew_site_url() . '/' . renew_image_path('card');
}

// วันครบกำหนดชำระ = วันหมดอายุ - N วัน
function renew_pay_due($r) {
    return renew_add_days($r['expires_on'], -(int)renew_num('pay_before_days'));
}

// ตำแหน่งช่องในรูป (เปอร์เซ็นต์ของความกว้าง/สูงรูป 1:1) — ตรงกับรูปตั้งต้นของร้าน
function renew_card_layout() {
    return [
        'email'  => ['top' => '27.3%', 'start' => '23.2%', 'width' => '56%',   'height' => '5.6%'],
        'due'    => ['top' => '37.3%', 'start' => '39.6%', 'width' => '36.8%', 'height' => '5.6%'],
        'expiry' => ['top' => '45.3%', 'start' => '30.9%', 'width' => '40%',   'height' => '5.6%'],
        'stop'   => ['top' => '76%',   'start' => '4%',    'width' => '40%',   'height' => '21%'],
        'renew'  => ['top' => '76%',   'start' => '51%',   'width' => '47%',   'height' => '21%'],
    ];
}

function renew_image_card($r) {
    $L = renew_card_layout();
    $field = function ($pos, $text) {
        return ['type' => 'box', 'layout' => 'vertical', 'position' => 'absolute', 'justifyContent' => 'center',
                'offsetTop' => $pos['top'], 'offsetStart' => $pos['start'], 'width' => $pos['width'], 'height' => $pos['height'],
                'contents' => [['type' => 'text', 'text' => $text, 'align' => 'center', 'weight' => 'bold', 'color' => '#5a2a2a',
                                'size' => 'sm', 'adjustMode' => 'shrink-to-fit']]];
    };
    $tap = function ($pos, $data, $label) {
        return ['type' => 'box', 'layout' => 'vertical', 'position' => 'absolute',
                'offsetTop' => $pos['top'], 'offsetStart' => $pos['start'], 'width' => $pos['width'], 'height' => $pos['height'],
                'contents' => [], 'action' => ['type' => 'postback', 'label' => $label, 'data' => $data, 'displayText' => $label]];
    };
    return [
        'type' => 'bubble', 'size' => 'giga',
        'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '0px', 'contents' => [
            ['type' => 'image', 'url' => renew_card_image_url(), 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'cover'],
            $field($L['email'], $r['customer_email']),
            $field($L['due'], renew_date_th(renew_pay_due($r))),
            $field($L['expiry'], renew_date_th($r['expires_on'])),
            $tap($L['stop'], 'a=stop&r=' . $r['id'], 'ไม่ต้องการต่ออายุแล้ว'),
            $tap($L['renew'], 'a=pick&r=' . $r['id'], 'ต่ออายุ ' . $r['customer_email']),
        ]],
    ];
}

function renew_flex_message($rows, $title = null, $style = null) {
    $style = $style ?: renew_settings()['card_style'];
    $bubbles = array_map(function ($r) use ($title, $style) { return $style === 'image' ? renew_image_card($r) : renew_flex($r, $title); }, array_slice($rows, 0, 10));
    if (!$bubbles) return null;
    $alt = count($rows) === 1
        ? ($style === 'image' ? 'แจ้งเตือนวันครบกำหนดชำระ ' . $rows[0]['customer_email'] . ' ชำระภายใน ' . renew_date_th(renew_pay_due($rows[0])) : 'ต่ออายุ ' . $rows[0]['customer_email'] . ' หมดอายุ ' . renew_date_th($rows[0]['expires_on']))
        : 'รายการต่อเมลของคุณ';
    return ['type' => 'flex', 'altText' => $alt, 'contents' => count($bubbles) === 1 ? $bubbles[0] : ['type' => 'carousel', 'contents' => $bubbles]];
}

// ---------------------------------------------------------------- QR พร้อมเพย์ (EMVCo)
function promptpay_payload($id, $amount) {
    $target = preg_replace('/\D/', '', (string)$id);
    $f = function ($tag, $v) { return $tag . str_pad((string)strlen($v), 2, '0', STR_PAD_LEFT) . $v; };
    if (strlen($target) === 10)      $acc = $f('01', str_pad('0066' . substr($target, 1), 13, '0', STR_PAD_LEFT));
    elseif (strlen($target) === 13)  $acc = $f('02', $target);
    elseif (strlen($target) === 15)  $acc = $f('03', $target);
    else return null;
    $body = $f('00', '01') . $f('01', '12') . $f('29', $f('00', 'A000000677010111') . $acc)
          . $f('58', 'TH') . $f('53', '764') . $f('54', number_format((float)$amount, 2, '.', '')) . '6304';
    $crc = 0xFFFF;
    for ($i = 0, $n = strlen($body); $i < $n; $i++) {
        $crc ^= ord($body[$i]) << 8;
        for ($b = 0; $b < 8; $b++) $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
    }
    return $body . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function renew_site_url() {
    $proto = (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $url = $proto . '://' . $host;
        if (($GLOBALS['__renew_site_saved'] ?? '') !== $url && !OTP_LOCAL_MODE) {
            $GLOBALS['__renew_site_saved'] = $url;
            try { if ((renew_db()->query("SELECT v FROM renew_kv WHERE k = 'site_url'")->fetchColumn() ?: '') !== $url) renew_set('site_url', $url); } catch (Throwable $e) {}
        }
        return $url;
    }
    return (string)(renew_db()->query("SELECT v FROM renew_kv WHERE k = 'site_url'")->fetchColumn() ?: '');
}

function renew_qr_sig($payment_id) {
    return substr(hash_hmac('sha256', 'qr:' . $payment_id, renew_settings()['cron_key'] . '|' . DB_PASS), 0, 24);
}

// ---------------------------------------------------------------- การจ่ายเงิน
// ลูกค้ากดปุ่มแพ็กเกจ → สร้างรายการรอจ่าย (ยกเลิกรายการรอจ่ายเดิมของรายการนี้)
function renew_create_payment($r, $package_id, $line_user_id) {
    $pkg = renew_package($package_id);
    if (!$pkg || !(int)$pkg['active']) return ['ok' => false, 'error' => 'แพ็กเกจนี้ปิดไปแล้ว กรุณาเลือกแพ็กเกจอื่น หรือติดต่อแอดมิน'];
    $static = renew_pay_mode() === 'static';
    $f = renew_fee($r);
    $amount = round((float)$pkg['price'] + $f['fee'], 2);
    $db = renew_db();
    $db->prepare("UPDATE renew_payments SET status = 'cancelled' WHERE renewal_id = ? AND status = 'pending'")->execute([$r['id']]);
    $id = renew_id('p');
    $now = time();
    $db->prepare("INSERT INTO renew_payments (id, renewal_id, package_id, line_user_id, amount, fee, readd, status, attempts, created_at, expires_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?)")
       ->execute([$id, $r['id'], $pkg['id'], $line_user_id, $amount, $f['fee'], $f['readd'] ? 1 : 0, $now, $now + ($static ? 86400 : RENEW_QR_TTL)]); // รูปร้าน (ไม่มี QR หมดเวลา) รอสลิปได้ 24 ชม.
    return ['ok' => true, 'id' => $id, 'amount' => $amount, 'package' => $pkg, 'fee' => $f];
}

// วิธีส่งหน้าชำระเงินที่ใช้จริง: static (รูปร้านตามเดิม ไม่ต้องตั้งพร้อมเพย์) / overlay (รูปร้าน + QR ยอดพอดี) / qr
// ถ้าเลือกแบบที่ต้องใช้ QR แต่ยังไม่ได้ใส่พร้อมเพย์ → ส่งรูปร้านตามเดิมแทน
function renew_pay_mode() {
    $st = renew_settings();
    $has_pp = preg_replace('/\D/', '', $st['promptpay_id']) !== '';
    if ($st['pay_style'] === 'qr') return $has_pp ? 'qr' : 'static';
    if ($st['pay_style'] === 'image') return $has_pp ? 'overlay' : 'static';
    return 'static';
}

// หน้าวิธีชำระเงินแบบรูปของร้าน: รูปพื้นหลัง + QR ยอดพอดีวางทับช่อง QR (แตะ QR = เปิดรูปใหญ่ไว้บันทึก/สแกน)
function renew_pay_flex($qr_url, $amount) {
    return ['type' => 'flex', 'altText' => 'ชำระค่าต่ออายุ ' . renew_baht($amount) . ' — สแกน QR เพื่อโอน', 'contents' => [
        'type' => 'bubble', 'size' => 'giga',
        'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => '0px', 'contents' => [
            ['type' => 'image', 'url' => renew_site_url() . '/' . renew_image_path('pay'), 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'cover'],
            ['type' => 'box', 'layout' => 'vertical', 'position' => 'absolute', 'backgroundColor' => '#FFFFFF', 'cornerRadius' => '6px',
             'offsetTop' => '36.3%', 'offsetStart' => '62.3%', 'width' => '31.6%', 'height' => '31.6%',
             'action' => ['type' => 'uri', 'label' => 'เปิด QR', 'uri' => $qr_url],
             'contents' => [['type' => 'image', 'url' => $qr_url, 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'fit']]],
        ]],
    ]];
}

function renew_pay_text($pay, $r, $pay_hint) {
    return renew_msg('msg_pay', renew_msg_vars($r, [
        'package' => $pay['package']['name'], 'days' => (int)$pay['package']['days'], 'amount' => renew_baht($pay['amount']),
        'fee_line' => $pay['fee']['fee'] > 0 ? "ค่า{$pay['fee']['why']}: " . renew_baht($pay['fee']['fee']) . "\n" : '',
        'pay_hint' => $pay_hint,
        'after_slip' => renew_settings()['slip_mode'] === 'api' ? 'ระบบจะต่ออายุให้อัตโนมัติ' : 'ตรวจสลิปแล้วแอดมินจะยืนยันการต่ออายุให้ค่ะ',
    ]));
}

function renew_payment_messages($pay, $r) {
    $st = renew_settings();
    $mode = renew_pay_mode();
    if ($mode === 'static') {
        // ส่งรูปวิธีชำระเงินของร้านตามเดิม + บอกยอด (ลูกค้าโอนตามช่องทางในรูป)
        $img = renew_site_url() . '/' . renew_image_path('pay');
        $txt = renew_pay_text($pay, $r, 'โอนยอดนี้ตามช่องทางในรูปด้านบน');
        $img_msg = ['type' => 'image', 'originalContentUrl' => $img, 'previewImageUrl' => $img];
        return $st['pay_text'] === '0' ? [$img_msg] : [$img_msg, line_text($txt)];
    }
    $qr_url = renew_site_url() . '/renew_qr.php?id=' . $pay['id'] . '&t=' . renew_qr_sig($pay['id']);
    $txt = renew_pay_text($pay, $r, 'สแกน QR ด้านบนจ่ายยอดนี้พอดี' . ($st['pay_name'] !== '' ? " (ชื่อบัญชี {$st['pay_name']})" : '')
        . ($mode === 'overlay' ? ' แตะที่ QR เพื่อเปิดรูปใหญ่' : '') . ' · QR ใช้ได้ ' . (RENEW_QR_TTL / 60) . ' นาที');
    return [
        $mode === 'overlay' ? renew_pay_flex($qr_url, $pay['amount'])
            : ['type' => 'image', 'originalContentUrl' => $qr_url, 'previewImageUrl' => $qr_url],
        line_text($txt),
    ];
}

// รายการรอจ่ายล่าสุดของลูกค้า (ยังส่งสลิปได้)
function renew_pending_payment($line_user_id) {
    $st = renew_db()->prepare("SELECT * FROM renew_payments WHERE line_user_id = ? AND status = 'pending' AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
    $st->execute([$line_user_id, time() - RENEW_SLIP_GRACE]);
    return $st->fetch() ?: null;
}

// โหมด chat: ลูกค้าส่งสลิปค่าต่อเมล → ให้ Slip2Go ในแชทของร้านตรวจ (ไม่ตรวจซ้ำ) แล้วรอแอดมินกดยืนยัน
function renew_mark_slip_received($pay) {
    $st = renew_db()->prepare("UPDATE renew_payments SET status = 'slip', last_error = NULL WHERE id = ? AND status = 'pending'");
    $st->execute([$pay['id']]);
    if ($st->rowCount() !== 1) return false;
    $r = renew_get($pay['renewal_id']);
    $pkg = renew_package($pay['package_id']);
    renew_notify_admin('📩 ลูกค้าส่งสลิปค่าต่อเมล — ดูผลตรวจในแชทแล้วกดยืนยัน', [
        'เมลลูกค้า: ' . ($r['customer_email'] ?? '-'), 'fam: ' . (($r['shop_email'] ?? '') !== '' ? $r['shop_email'] : '-'),
        'แพ็กเกจ: ' . ($pkg['name'] ?? '-') . ' · ยอด ' . renew_baht($pay['amount']),
        'ถ้าสลิปผ่าน → หลังบ้าน → ต่อเมล → กด "ยืนยันรับเงิน" ระบบจะต่อวันและแจ้งลูกค้าให้เอง',
    ]);
    return true;
}

// แอดมินยืนยันว่าสลิปผ่าน (โหมด chat) → ปิดรายการ + ต่อวัน + แจ้งลูกค้า
function renew_confirm_payment($payment_id) {
    $db = renew_db();
    $q = $db->prepare("SELECT * FROM renew_payments WHERE id = ?");
    $q->execute([$payment_id]);
    $pay = $q->fetch();
    if (!$pay || !in_array($pay['status'], ['slip', 'pending'], true)) return ['ok' => false, 'error' => 'รายการนี้ยืนยันไปแล้ว หรือถูกยกเลิกแล้ว'];
    $st = $db->prepare("UPDATE renew_payments SET status = 'paid', paid_at = ? WHERE id = ? AND status IN ('slip', 'pending')");
    $st->execute([time(), $pay['id']]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'รายการนี้ยืนยันไปแล้ว'];
    $pkg = renew_package($pay['package_id']);
    $new_exp = renew_extend($pay['renewal_id'], $pay['package_id'], (int)($pkg['days'] ?? 30));
    $r = renew_get($pay['renewal_id']);
    renew_log('🔁 ยืนยันรับเงินค่าต่อเมลแล้ว', ["เมลลูกค้า: {$r['customer_email']}", 'แพ็กเกจ: ' . ($pkg['name'] ?? '-') . ' · ยอด ' . renew_baht($pay['amount']), 'ใช้ได้ถึง ' . renew_date_th($new_exp)]);
    if ($r['line_user_id']) {
        line_push($r['line_user_id'], [line_text(renew_msg('msg_renewed', renew_msg_vars($r, ['package' => $pkg['name'] ?? '', 'readd_line' => renew_readd_line((int)$pay['readd'])])))]);
    }
    return ['ok' => true, 'until' => $new_exp, 'readd' => (bool)(int)$pay['readd']];
}

// ---------------------------------------------------------------- Slip2Go
function slip2go_check($image_bytes, $amount) {
    $st = renew_settings();
    $base = rtrim($st['slip2go_url'], '/');
    if ($base === '' || $st['slip2go_secret'] === '') return ['ok' => false, 'config' => true, 'message' => 'ยังไม่ได้ตั้งค่า Slip2Go'];
    $tmp = tempnam(sys_get_temp_dir(), 'slip');
    file_put_contents($tmp, $image_bytes);
    $mime = (substr($image_bytes, 0, 4) === "\x89PNG") ? 'image/png' : 'image/jpeg';
    $payload = ['checkDuplicate' => true];
    if ($recv = renew_receivers()) $payload['checkReceiver'] = $recv;
    $ch = curl_init($base . '/api/verify-slip/qr-image/info');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $st['slip2go_secret']],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($tmp, $mime, $mime === 'image/png' ? 'slip.png' : 'slip.jpg'), 'payload' => json_encode($payload)],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $j = json_decode((string)$res, true);
    $code = (string)($j['code'] ?? '');
    if (!is_array($j)) {
        error_log('[slip2go] HTTP ' . $http . ' ' . substr((string)$res, 0, 300));
        return ['ok' => false, 'retry' => true, 'message' => 'ระบบตรวจสลิปขัดข้องชั่วคราว กรุณาส่งสลิปอีกครั้งในอีกสักครู่'];
    }
    if ($code === '200000' || $code === '200200') {
        return ['ok' => true, 'data' => $j['data'] ?? []];
    }
    $map = [
        '200401' => ['บัญชีผู้รับในสลิปไม่ใช่บัญชีของร้าน', false],
        '200402' => ['ยอดเงินในสลิปไม่ตรง', false],
        '200403' => ['วันที่โอนในสลิปไม่ตรง', false],
        '200404' => ['ยังไม่พบสลิปนี้ในระบบธนาคาร (สลิปเพิ่งโอนอาจต้องรอ 1-2 นาที) กรุณาส่งสลิปอีกครั้ง', true],
        '200500' => ['สลิปไม่ถูกต้อง/ถูกแก้ไข', false],
        '200501' => ['สลิปนี้ถูกใช้ไปแล้ว', false],
        '200502' => ['ธนาคารขัดข้องชั่วคราว กรุณาส่งสลิปอีกครั้งในอีกสักครู่', true],
        '400002' => ['อ่านรูปสลิปไม่ได้ กรุณาส่งรูปสลิปที่เห็น QR ชัด ๆ', false],
        '429000' => ['ระบบตรวจสลิปไม่ว่าง กรุณาส่งสลิปอีกครั้งในอีกสักครู่', true],
        '500500' => ['ระบบตรวจสลิปขัดข้องชั่วคราว กรุณาส่งสลิปอีกครั้งในอีกสักครู่', true],
        '500503' => ['ระบบตรวจสลิปปิดปรับปรุงชั่วคราว กรุณาส่งสลิปอีกครั้งภายหลัง', true],
    ];
    if (isset($map[$code])) return ['ok' => false, 'retry' => $map[$code][1], 'code' => $code, 'message' => $map[$code][0]];
    if (strpos($code, '401') === 0) {
        return ['ok' => false, 'config' => true, 'code' => $code, 'message' => 'Slip2Go: ' . ($j['message'] ?? $code)];
    }
    return ['ok' => false, 'retry' => false, 'code' => $code, 'message' => 'ตรวจสลิปไม่ผ่าน (' . ($j['message'] ?? $code) . ')'];
}

// บัญชีผู้รับที่ยอมรับ (Slip2Go checkReceiver) จาก "ประเภท:เลข, ประเภท:เลข" หรือเลขอย่างเดียว (ใช้ประเภทจากช่อง Account type)
function renew_receivers() {
    $st = renew_settings();
    $out = [];
    foreach (preg_split('/[\s,]+/', trim($st['receiver_account'])) as $item) {
        if ($item === '') continue;
        [$type, $acc] = strpos($item, ':') !== false ? explode(':', $item, 2) : [$st['receiver_type'], $item];
        $type = preg_replace('/\D/', '', $type);
        $acc = preg_replace('/[^0-9A-Za-z]/', '', $acc);
        if ($type !== '' && $acc !== '') $out[] = ['accountType' => $type, 'accountNumber' => $acc];
    }
    return $out;
}

// ลูกค้าส่งรูปสลิป → ตรวจ → ต่ออายุ · คืนข้อความตอบกลับ
function renew_handle_slip($pay, $image_bytes) {
    $db = renew_db();
    $fail = function ($msg, $count = true) use ($db, $pay) {
        $db->prepare("UPDATE renew_payments SET last_error = ?" . ($count ? ", attempts = attempts + 1" : "") . " WHERE id = ?")
           ->execute([mb_substr($msg, 0, 490), $pay['id']]);
        $r = renew_get($pay['renewal_id']) ?: [];
        return [line_text(renew_msg('msg_slip_failed', renew_msg_vars($r, ['reason' => $msg, 'amount' => renew_baht($pay['amount'])])))];
    };
    if ((int)$pay['attempts'] >= RENEW_MAX_ATTEMPT) {
        return [line_text('ส่งสลิปเกินจำนวนครั้งที่กำหนด กรุณาทักแอดมินพร้อมสลิปค่ะ')];
    }
    $amount = (float)$pay['amount'];
    $res = slip2go_check($image_bytes, $amount);
    if (!$res['ok']) {
        if (!empty($res['config'])) {
            renew_notify_admin('🚨 ตรวจสลิปไม่ได้ — ตั้งค่า Slip2Go', [$res['message'], 'ลูกค้ารอต่ออายุอยู่ ยอด ' . renew_baht($amount) . ' — ตรวจสลิปเอง แล้วกด "ต่อเอง" ในหลังบ้าน']);
            return [line_text(renew_msg('msg_slip_manual', renew_msg_vars(renew_get($pay['renewal_id']) ?: [], ['amount' => renew_baht($amount)])))];
        }
        return $fail($res['message'], empty($res['retry']));
    }
    $d = $res['data'];
    $slip_amount = (float)($d['amount'] ?? 0);
    $extra = renew_num('amount_extra'); // เช่น โอนผ่าน Wallet +5 บาท
    if ($slip_amount + 0.005 < $amount || $slip_amount - $amount > $extra + 0.005) {
        return $fail('ยอดในสลิป ' . renew_baht($slip_amount) . ' ไม่ตรงกับยอดที่ต้องโอน ' . renew_baht($amount) . ($extra > 0 ? ' (เกินได้ไม่เกิน ' . renew_baht($extra) . ')' : ''));
    }
    $paid_ts = strtotime((string)($d['dateTime'] ?? '')) ?: 0;
    if (!$paid_ts) return $fail('อ่านเวลาโอนในสลิปไม่ได้');
    if ($paid_ts < (int)$pay['created_at'] - 300) return $fail('สลิปนี้โอนก่อนกดเลือกแพ็กเกจ ใช้กับรายการนี้ไม่ได้');
    $trans_ref = mb_substr((string)($d['transRef'] ?? ''), 0, 64);
    if ($trans_ref === '') return $fail('อ่านเลขอ้างอิงในสลิปไม่ได้');

    // ปิดรายการ (กันต่อซ้ำ: เปลี่ยนสถานะได้ครั้งเดียว · trans_ref ซ้ำ = สลิปเคยใช้แล้ว)
    try {
        $st = $db->prepare("UPDATE renew_payments SET status = 'paid', paid_at = ?, trans_ref = ? WHERE id = ? AND status = 'pending'");
        $st->execute([time(), $trans_ref, $pay['id']]);
        if ($st->rowCount() !== 1) return [line_text('รายการนี้ชำระเรียบร้อยแล้วค่ะ')];
    } catch (Throwable $e) {
        return $fail('สลิปนี้ถูกใช้ไปแล้ว');
    }
    $pkg = renew_package($pay['package_id']);
    $days = (int)($pkg['days'] ?? 30);
    $new_exp = renew_extend($pay['renewal_id'], $pay['package_id'], $days);
    $r = renew_get($pay['renewal_id']);
    $info = ["เมลลูกค้า: {$r['customer_email']}", ($r['shop_email'] !== '' ? "fam: {$r['shop_email']}" : 'fam: -'),
             'แพ็กเกจ: ' . ($pkg['name'] ?? '-') . ' · ยอด ' . renew_baht($amount), 'ใช้ได้ถึง ' . renew_date_th($new_exp)];
    if ((int)$pay['readd']) {
        renew_notify_admin('➕ ลูกค้าต่อหลังลบเมล — เชิญเมลกลับเข้า fam', array_merge($info, ['⚠️ ระบบต่อวันให้แล้ว แต่ต้องเชิญเมลลูกค้ากลับเข้า fam เอง']));
    } else {
        renew_notify_admin('🔁 ลูกค้าต่อเมลแล้ว (จ่ายผ่าน LINE)', $info);
    }
    return [line_text(renew_msg('msg_renewed', renew_msg_vars($r, ['package' => $pkg['name'] ?? '', 'readd_line' => renew_readd_line((int)$pay['readd'])])))];
}

// ---------------------------------------------------------------- งานเป็นรอบ (renew_cron.php)
function renew_sweep() {
    $db = renew_db();
    $out = ['reminded' => 0, 'unlinked' => 0, 'lapsed' => 0, 'expired_qr' => 0];
    $hour = (int)(new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('G');
    $remind_days = (int)renew_num('remind_days');

    // 1) ใกล้หมด → เตือนครั้งเดียวต่อรอบ (ส่ง 09:00–20:59 ไม่ปลุกลูกค้ากลางคืน)
    if ($hour >= 9 && $hour < 21) {
        $st = $db->prepare("SELECT * FROM renewals WHERE status = 'active' AND expires_on <= ? AND expires_on >= ? LIMIT 40");
        $st->execute([renew_today($remind_days), renew_today(-1)]);
        foreach ($st->fetchAll() as $r) {
            if ($r['reminded_for'] === $r['expires_on'] || !empty($r['removed_at'])) continue;
            $claim = $db->prepare("UPDATE renewals SET reminded_for = ? WHERE id = ? AND (reminded_for IS NULL OR reminded_for <> ?)");
            $claim->execute([$r['expires_on'], $r['id'], $r['expires_on']]);
            if ($claim->rowCount() !== 1) continue;
            $msg = renew_flex_message([$r]);
            if ($r['line_user_id'] && $msg && line_push($r['line_user_id'], [$msg])) { $out['reminded']++; continue; }
            $out['unlinked']++;
            renew_notify_admin('📭 เตือนต่อเมลทาง LINE ไม่ได้', [
                "เมลลูกค้า: {$r['customer_email']}", ($r['shop_email'] !== '' ? "fam: {$r['shop_email']}" : 'fam: -'), 'หมดอายุ ' . renew_date_th($r['expires_on']),
                $r['line_user_id'] ? 'ลูกค้าบล็อก/ไม่ได้แอด LINE ร้าน — ทักไปบอกเอง' : "ยังไม่ผูกแชท — ส่งรหัส {$r['link_code']} ให้ลูกค้าพิมพ์ในแชท LINE ร้าน",
            ]);
        }
    }

    // 2) เลยกำหนดต่อแล้ว ยังไม่ต่อ → บอกแอดมินให้ลบเมลออกจาก fam (ครั้งเดียวต่อรอบ)
    $grace = (int)renew_num('grace_days');
    $st = $db->prepare("SELECT * FROM renewals WHERE status = 'active' AND removed_at IS NULL AND expires_on < ? LIMIT 40");
    $st->execute([renew_today(-$grace)]);
    foreach ($st->fetchAll() as $r) {
        if ($r['lapsed_for'] === $r['expires_on']) continue;
        $claim = $db->prepare("UPDATE renewals SET lapsed_for = ? WHERE id = ? AND (lapsed_for IS NULL OR lapsed_for <> ?)");
        $claim->execute([$r['expires_on'], $r['id'], $r['expires_on']]);
        if ($claim->rowCount() !== 1) continue;
        $out['lapsed']++;
        renew_notify_admin('⌛ เลยกำหนดต่อแล้ว ลูกค้ายังไม่ต่อ — ลบเมลออกได้', [
            "เมลลูกค้า: {$r['customer_email']}", ($r['shop_email'] !== '' ? "fam: {$r['shop_email']}" : 'fam: -'),
            'หมดเมื่อ ' . renew_date_th($r['expires_on']) . ' · กำหนดต่อ ' . renew_date_th(renew_deadline($r)),
            'ลบเมลลูกค้าออกจาก fam แล้วกด "ลบเมลแล้ว" ในหลังบ้าน (ถ้ามาต่อทีหลังคิด +' . renew_baht(renew_num('removed_fee')) . ')',
        ]);
    }

    // 3) QR ที่หมดเวลาแล้ว
    $st = $db->prepare("UPDATE renew_payments SET status = 'expired' WHERE status = 'pending' AND expires_at < ?");
    $st->execute([time() - RENEW_SLIP_GRACE]);
    $out['expired_qr'] = $st->rowCount();
    renew_set('last_cron', (string)time());
    return $out;
}
