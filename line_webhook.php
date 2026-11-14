<?php
// =========================================================================
// line_webhook.php — Webhook ของ LINE OA (ตั้งใน LINE Developers → Messaging API → Webhook URL)
//   https://<โดเมนเว็บ>/line_webhook.php
//
// ระบบตอบเฉพาะเรื่อง "ต่อเมล" เท่านั้น ข้อความอื่น ๆ ไม่ตอบ (แอดมินคุยกับลูกค้าตามปกติได้)
//   - ลูกค้าพิมพ์รหัส R12345        → ผูกแชทกับรายการต่อเมล + ส่งการ์ดให้
//   - พิมพ์ "ต่อเมล" / "เช็ควันหมด"   → ส่งการ์ดรายการของตัวเอง
//   - กดปุ่มแพ็กเกจในการ์ด            → ส่ง QR พร้อมเพย์ยอดพอดี
//   - ส่งรูปสลิป (มีรายการรอจ่าย)      → ตรวจ Slip2Go แล้วต่ออายุให้
//   - แอดมินพิมพ์รหัสแอดมิน           → รับแจ้งเตือนของระบบทาง LINE
// =========================================================================
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/renew_lib.php';

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo 'LINE webhook OK';
    exit;
}
if (!line_signature_ok($raw, $sig)) {
    http_response_code(401);
    echo 'bad signature';
    exit;
}
// ตอบ LINE ว่าได้รับแล้ว (ปุ่ม Verify ใน LINE Developers ส่ง events ว่างมา)
http_response_code(200);
header('Content-Type: application/json');

$body = json_decode($raw, true);
foreach (($body['events'] ?? []) as $ev) {
    try {
        renew_handle_event($ev);
    } catch (Throwable $e) {
        error_log('[line_webhook] ' . $e->getMessage());
    }
}
echo '{}';
exit;

function renew_touch_contact($uid) {
    $db = renew_db();
    $st = $db->prepare("SELECT display_name, last_seen FROM renew_contacts WHERE line_user_id = ?");
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) {
        $db->prepare("INSERT INTO renew_contacts (line_user_id, display_name, is_admin, last_seen) VALUES (?, ?, 0, ?)")
           ->execute([$uid, line_profile_name($uid), time()]);
    } elseif (time() - (int)$row['last_seen'] > 3600 || $row['display_name'] === '') {
        $name = line_profile_name($uid) ?: $row['display_name'];
        $db->prepare("UPDATE renew_contacts SET display_name = ?, last_seen = ? WHERE line_user_id = ?")->execute([$name, time(), $uid]);
    }
}

function renew_contact_name($uid) {
    $st = renew_db()->prepare("SELECT display_name FROM renew_contacts WHERE line_user_id = ?");
    $st->execute([$uid]);
    return (string)($st->fetchColumn() ?: '');
}

function renew_user_rows($uid) {
    $st = renew_db()->prepare("SELECT * FROM renewals WHERE line_user_id = ? AND status = 'active' ORDER BY expires_on LIMIT 10");
    $st->execute([$uid]);
    return $st->fetchAll();
}

function renew_handle_event($ev) {
    $uid = $ev['source']['userId'] ?? '';
    $reply = $ev['replyToken'] ?? '';
    if ($uid === '' || ($ev['source']['type'] ?? 'user') !== 'user') return; // ตอบเฉพาะแชทส่วนตัว
    renew_touch_contact($uid);
    $type = $ev['type'] ?? '';

    // ---------- กดปุ่มในการ์ด ----------
    if ($type === 'postback') {
        parse_str((string)($ev['postback']['data'] ?? ''), $d);
        if (($d['a'] ?? '') === 'rn') {
            $r = renew_get((string)($d['r'] ?? ''));
            if (!$r || $r['line_user_id'] !== $uid || $r['status'] !== 'active') {
                line_reply($reply, [line_text('ไม่พบรายการต่อเมลนี้ กรุณาพิมพ์ "ต่อเมล" แล้วเลือกใหม่ หรือทักแอดมินค่ะ')]);
                return;
            }
            $allowed = array_column(renew_choices($r), 'id');
            if (!in_array((string)($d['p'] ?? ''), $allowed, true)) {
                line_reply($reply, [line_text('แพ็กเกจนี้ใช้ต่อรายการนี้ไม่ได้ กรุณาพิมพ์ "ต่อเมล" แล้วเลือกใหม่ค่ะ')]);
                return;
            }
            $pay = renew_create_payment($r, (string)$d['p'], $uid);
            line_reply($reply, $pay['ok'] ? renew_payment_messages($pay, $r) : [line_text($pay['error'])]);
        }
        return;
    }

    if ($type === 'follow') {
        line_reply($reply, [line_text("ขอบคุณที่เพิ่มเพื่อนนะคะ 💖\nลูกค้าต่อเมล: พิมพ์รหัสที่ได้รับจากแอดมิน (เช่น R12345) เพื่อรับแจ้งเตือนก่อนหมดอายุ และต่อเมลในแชทนี้ได้เลยค่ะ")]);
        return;
    }
    if ($type !== 'message') return;
    $msg = $ev['message'] ?? [];

    // ---------- รูปสลิป ----------
    if (($msg['type'] ?? '') === 'image') {
        $pay = renew_pending_payment($uid);
        if (!$pay) return; // ไม่มีรายการรอจ่าย = รูปทั่วไป ปล่อยให้แอดมินคุยเอง
        $img = line_content((string)$msg['id']);
        if ($img === null) {
            line_reply($reply, [line_text('โหลดรูปสลิปไม่สำเร็จ กรุณาส่งรูปอีกครั้งค่ะ')]);
            return;
        }
        line_reply($reply, renew_handle_slip($pay, $img));
        return;
    }

    if (($msg['type'] ?? '') !== 'text') return;
    $text = trim((string)($msg['text'] ?? ''));
    $st = renew_settings();

    // ---------- แอดมินลงทะเบียนรับแจ้งเตือน ----------
    if ($st['admin_code'] !== '' && strcasecmp($text, $st['admin_code']) === 0) {
        renew_db()->prepare("UPDATE renew_contacts SET is_admin = 1 WHERE line_user_id = ?")->execute([$uid]);
        renew_log('👤 เพิ่มแอดมินรับแจ้งเตือน', ['LINE: ' . renew_contact_name($uid)]);
        line_reply($reply, [line_text('✅ บัญชีนี้จะได้รับแจ้งเตือนระบบต่อเมลทาง LINE แล้วค่ะ')]);
        return;
    }

    // ---------- ลูกค้าพิมพ์รหัสผูกแชท ----------
    if (preg_match('/^R\d{5,6}$/i', $text)) {
        $code = strtoupper($text);
        $q = renew_db()->prepare("SELECT * FROM renewals WHERE link_code = ?");
        $q->execute([$code]);
        $r = $q->fetch();
        if (!$r) {
            line_reply($reply, [line_text("ไม่พบรหัส $code กรุณาตรวจสอบรหัสกับแอดมินอีกครั้งค่ะ")]);
            return;
        }
        if ($r['line_user_id'] && $r['line_user_id'] !== $uid) {
            line_reply($reply, [line_text('รหัสนี้ถูกผูกกับบัญชี LINE อื่นไปแล้ว กรุณาทักแอดมินค่ะ')]);
            return;
        }
        $name = renew_contact_name($uid);
        if (!$r['line_user_id']) {
            renew_db()->prepare("UPDATE renewals SET line_user_id = ?, line_name = ?, updated_at = ? WHERE id = ? AND line_user_id IS NULL")
                ->execute([$uid, $name, time(), $r['id']]);
            renew_notify_admin('🔗 ลูกค้าผูกแชทต่อเมลแล้ว', ["LINE: $name พิมพ์รหัส $code", "เมลลูกค้า: {$r['customer_email']}",
                'ถ้าชื่อ LINE ไม่ตรงกับเจ้าของเมล → หลังบ้าน → ต่อเมล → ยกเลิกการผูก']);
            $r = renew_get($r['id']);
        }
        $flex = renew_flex_message([$r], '🔁 รายการต่อเมลของคุณ');
        line_reply($reply, array_values(array_filter([
            line_text("ผูกบัญชีเรียบร้อยค่ะ ✅\nระบบจะแจ้งเตือนในแชทนี้ก่อนหมดอายุ " . (int)$st['remind_days'] . " วัน และกดต่ออายุจากการ์ดได้เลยค่ะ"),
            $flex,
        ])));
        return;
    }

    // ---------- ขอดูรายการของตัวเอง ----------
    if (preg_match('/^(ต่อเมล|ต่ออายุ|เช็ควันหมด|วันหมดอายุ|ต่อเมล์)$/u', $text)) {
        $rows = renew_user_rows($uid);
        if (!$rows) {
            line_reply($reply, [line_text('ยังไม่มีรายการต่อเมลที่ผูกกับบัญชีนี้ค่ะ กรุณาพิมพ์รหัสที่ได้รับจากแอดมิน (เช่น R12345)')]);
            return;
        }
        line_reply($reply, [renew_flex_message($rows, '🔁 ต่อเมล')]);
    }
    // ข้อความอื่น: ไม่ตอบ
}
