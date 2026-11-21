<?php
// =========================================================================
// line_webhook.php — Webhook ของ LINE OA (ตั้งใน LINE Developers → Messaging API → Webhook URL)
//   https://<โดเมนเว็บ>/line_webhook.php
//
// ระบบตอบเฉพาะเรื่อง "ต่อเมล" เท่านั้น ข้อความอื่น ๆ ไม่ตอบ (แอดมินคุยกับลูกค้าตามปกติได้)
//   - ลูกค้าพิมพ์รหัส R12345        → ผูกแชทกับรายการต่อเมล + ส่งการ์ดให้
//   - พิมพ์ "ต่อเมล" / "เช็ควันหมด"   → ส่งการ์ดรายการของตัวเอง
//   - กดปุ่มแพ็กเกจในการ์ด            → ส่ง QR พร้อมเพย์ยอดพอดี
//   - ส่งรูปสลิป (มีรายการรอจ่าย)      → เก็บรูปไว้ให้แอดมินตรวจในหลังบ้าน + ตอบ "รอแอดมินอนุมัติ"
//   - แอดมินพิมพ์รหัสแอดมิน           → รับแจ้งเตือนของระบบทาง LINE
//
// ส่งต่อ (Forward): LINE OA ตั้ง Webhook ได้ช่องเดียว — ถ้าร้านใช้บริการอื่นใน OA เดียวกันอยู่แล้ว
// (เช่น บอทอื่น) ใส่ลิงก์ Webhook เดิมไว้ในหน้าแอดมิน → เหตุการณ์ที่ระบบนี้ไม่ได้ใช้
// จะถูกส่งต่อไปที่ลิงก์เดิมพร้อมลายเซ็นใหม่ บริการเดิมจึงทำงานต่อได้ตามปกติ
// (สลิปของรายการต่อเมลที่รอจ่ายอยู่ ไม่ส่งต่อ — แอดมินตรวจเองในหลังบ้าน)
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
    // บันทึกไว้ให้แอดมินเห็น: LINE ส่งมาแต่ลายเซ็นไม่ตรง = Channel secret ในหน้าแอดมินผิด/คนละ Channel
    if ($sig !== '') { try { renew_set('last_webhook_bad', (string)time()); } catch (Throwable $e) {} }
    http_response_code(401);
    echo 'bad signature';
    exit;
}
// ตอบ LINE ว่าได้รับแล้ว (ปุ่ม Verify ใน LINE Developers ส่ง events ว่างมา)
http_response_code(200);
header('Content-Type: application/json');

$body = json_decode($raw, true);
try { renew_set('last_webhook_ok', (string)time()); } catch (Throwable $e) {}
$forward = [];
foreach (($body['events'] ?? []) as $ev) {
    $used = false;
    try {
        $used = renew_handle_event($ev);
    } catch (Throwable $e) {
        error_log('[line_webhook] ' . $e->getMessage());
        try { renew_log('⚠️ ระบบต่อเมลรับข้อความ LINE แล้วเกิดข้อผิดพลาด', [mb_substr($e->getMessage(), 0, 300)]); } catch (Throwable $e2) {}
    }
    if (!$used) $forward[] = $ev;
}
echo '{}';
renew_forward_events($body, $forward, $raw);
exit;

// ส่งเหตุการณ์ที่ระบบนี้ไม่ได้ใช้ ต่อไปยัง Webhook เดิม (เช่น Slip2Go)
function renew_forward_events($body, $events, $raw) {
    $url = trim(renew_settings()['forward_webhook']);
    if ($url === '' || !preg_match('#^https://#i', $url)) return;
    $all = count($body['events'] ?? []);
    if ($all > 0 && !$events) return;                 // ระบบนี้ใช้ครบทุกเหตุการณ์แล้ว
    // ไม่ได้ตัดเหตุการณ์ไหนออก → ส่งต้นฉบับเดิมเป๊ะ ๆ · ตัดออกบางอัน → สร้างใหม่แล้วเซ็นด้วย Channel secret เดียวกัน
    $out = ($all === count($events)) ? $raw : json_encode(['destination' => $body['destination'] ?? '', 'events' => array_values($events)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig = base64_encode(hash_hmac('sha256', $out, renew_settings()['line_secret'], true));
    // ปล่อยคำตอบให้ LINE ก่อน (ถ้าเซิร์ฟเวอร์รองรับ) แล้วค่อยส่งต่อ
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $out, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Line-Signature: ' . $sig, 'User-Agent: LineBotWebhook/2.0'],
    ]);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300) {
        error_log('[line_webhook] forward HTTP ' . $http . ': ' . substr((string)$res, 0, 200));
        if (random_int(1, 10) === 1) renew_log('⚠️ ส่งต่อ Webhook เดิมไม่สำเร็จ', ['HTTP ' . $http, $url]);
    }
}

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

// คืน true = ระบบต่อเมลใช้เหตุการณ์นี้แล้ว (ไม่ส่งต่อ) · false = ไม่เกี่ยว ส่งต่อให้ Webhook เดิม
function renew_handle_event($ev) {
    $uid = $ev['source']['userId'] ?? '';
    $reply = $ev['replyToken'] ?? '';
    if ($uid === '' || ($ev['source']['type'] ?? 'user') !== 'user') return false; // ตอบเฉพาะแชทส่วนตัว
    renew_touch_contact($uid);
    $type = $ev['type'] ?? '';

    // ---------- กดปุ่มในการ์ด ----------
    if ($type === 'postback') {
        parse_str((string)($ev['postback']['data'] ?? ''), $d);
        $act = (string)($d['a'] ?? '');
        if ($act === 'nop') return true;

        // การ์ดรูป: กด "กดต่ออายุ" → ส่งช่องทางโอนทันที ตามแพ็กเกจที่แอดมินตั้งให้ลูกค้าคนนี้ (ไม่ให้เลือกแพ็กเกจ)
        if ($act === 'pick') {
            $r = renew_get((string)($d['r'] ?? ''));
            if ($r && $r['line_user_id'] === $uid && $r['status'] !== 'active') {
                line_reply($reply, [line_text("รายการ {$r['customer_email']} แจ้งไม่ต่ออายุไว้แล้วค่ะ\nหากต้องการต่ออายุ กรุณาทักแอดมินนะคะ")]);
                return true;
            }
            if (!$r || $r['line_user_id'] !== $uid) {
                line_reply($reply, [line_text('ไม่พบรายการต่อเมลนี้ กรุณาทักแอดมินค่ะ')]);
                return true;
            }
            if ($busy = renew_busy_reply($r)) {   // กดซ้ำ → ไม่ส่งช่องทางชำระเงินซ้ำ
                line_reply($reply, $busy);
                return true;
            }
            // แพ็กเกจของลูกค้า (renew_choices เรียงแพ็กเดิมไว้ก่อนเสมอ · ถ้าแพ็กเดิมถูกปิด ใช้แพ็กแรกที่เปิดอยู่)
            $choices = renew_choices($r);
            if (!$choices) {
                line_reply($reply, [line_text('ยังไม่มีแพ็กเกจ กรุณาทักแอดมินค่ะ')]);
                return true;
            }
            $pay = renew_create_payment($r, $choices[0]['id'], $uid);
            line_reply($reply, $pay['ok'] ? renew_payment_messages($pay, $r) : [line_text($pay['error'])]);
            return true;
        }

        // การ์ดรูป: กด "ไม่ต้องการต่ออายุแล้ว" → ถามยืนยันก่อน
        if ($act === 'stop' || $act === 'stopok') {
            $r = renew_get((string)($d['r'] ?? ''));
            if (!$r || $r['line_user_id'] !== $uid) {
                line_reply($reply, [line_text('ไม่พบรายการต่อเมลนี้ กรุณาทักแอดมินค่ะ')]);
                return true;
            }
            if ($r['status'] !== 'active') {
                line_reply($reply, [line_text("รับทราบแล้วค่ะ รายการนี้แจ้งไม่ต่ออายุไว้แล้ว\nใช้งานได้ถึง " . renew_date_th($r['expires_on']))]);
                return true;
            }
            if ($act === 'stop') {
                line_reply($reply, [['type' => 'template', 'altText' => 'ยืนยันไม่ต่ออายุ ' . $r['customer_email'],
                    'template' => ['type' => 'confirm', 'text' => mb_substr(renew_msg('msg_stop_confirm', renew_msg_vars($r)), 0, 240), 'actions' => [
                        ['type' => 'postback', 'label' => 'ยืนยันไม่ต่อ', 'data' => 'a=stopok&r=' . $r['id'], 'displayText' => 'ยืนยันไม่ต่ออายุ'],
                        ['type' => 'postback', 'label' => 'ยกเลิก', 'data' => 'a=nop', 'displayText' => 'ยกเลิก'],
                    ]]]]);
                return true;
            }
            $db = renew_db();
            $note = trim($r['note'] . ' · ลูกค้าแจ้งไม่ต่อ ' . date('d/m'), ' ·');
            $db->prepare("UPDATE renewals SET status = 'cancelled', note = ?, updated_at = ? WHERE id = ? AND status = 'active'")->execute([mb_substr($note, 0, 500), time(), $r['id']]);
            $db->prepare("UPDATE renew_payments SET status = 'cancelled' WHERE renewal_id = ? AND status = 'pending'")->execute([$r['id']]);
            renew_notify_admin('🚫 ลูกค้าแจ้งไม่ต่ออายุ', ["เมลลูกค้า: {$r['customer_email']}", 'fam: ' . ($r['shop_email'] !== '' ? $r['shop_email'] : '-'),
                'หมดอายุ ' . renew_date_th($r['expires_on']) . ' — ลบเมลออกจาก fam หลังหมดอายุได้เลย', 'LINE: ' . renew_contact_name($uid)]);
            line_reply($reply, [line_text(renew_msg('msg_stopped', renew_msg_vars($r)))]);
            return true;
        }

        if ($act === 'rn') {
            $r = renew_get((string)($d['r'] ?? ''));
            if (!$r || $r['line_user_id'] !== $uid || $r['status'] !== 'active') {
                line_reply($reply, [line_text('ไม่พบรายการต่อเมลนี้ กรุณาพิมพ์ "ต่อเมล" แล้วเลือกใหม่ หรือทักแอดมินค่ะ')]);
                return true;
            }
            $allowed = array_column(renew_choices($r), 'id');
            if (!in_array((string)($d['p'] ?? ''), $allowed, true)) {
                line_reply($reply, [line_text('แพ็กเกจนี้ใช้ต่อรายการนี้ไม่ได้ กรุณาพิมพ์ "ต่อเมล" แล้วเลือกใหม่ค่ะ')]);
                return true;
            }
            if ($busy = renew_busy_reply($r, (string)$d['p'])) {   // กดแพ็กเกจเดิมซ้ำ → ไม่ส่งซ้ำ (เลือกแพ็กเกจอื่น = ทำรายการใหม่)
                line_reply($reply, $busy);
                return true;
            }
            $pay = renew_create_payment($r, (string)$d['p'], $uid);
            line_reply($reply, $pay['ok'] ? renew_payment_messages($pay, $r) : [line_text($pay['error'])]);
            return true;
        }

        // ลูกค้ากดปุ่ม "ขอรูปชำระเงินอีกครั้ง"
        if ($act === 'repay') {
            $r = renew_get((string)($d['r'] ?? ''));
            if (!$r || $r['line_user_id'] !== $uid || $r['status'] !== 'active') {
                line_reply($reply, [line_text('ไม่พบรายการต่อเมลนี้ กรุณาทักแอดมินค่ะ')]);
                return true;
            }
            $msgs = renew_repay_messages($r);
            line_reply($reply, $msgs ?: (renew_busy_reply($r) ?: [line_text('รายการชำระเงินหมดเวลาแล้วค่ะ กรุณากด "กดต่ออายุ" ในการ์ดอีกครั้งนะคะ')]));
            return true;
        }
        return false;
    }

    if ($type === 'follow') {
        if (trim(renew_settings()['forward_webhook']) !== '') return false;
        line_reply($reply, [line_text(renew_msg('msg_follow'))]);
        return true;
    }
    if ($type !== 'message') return false;
    $msg = $ev['message'] ?? [];

    // ---------- รูปสลิป ----------
    if (($msg['type'] ?? '') === 'image') {
        // รายการรอจ่าย หรือส่งสลิปไปแล้ว (ส่งรูปซ้ำ/ส่งใหม่) → ระบบรับไว้ให้แอดมินตรวจ ไม่ส่งต่อให้บริการตรวจสลิปอื่น
        $pay = renew_pending_payment($uid) ?: renew_slip_payment($uid);
        if (!$pay) return false; // ไม่มีรายการรอจ่าย = รูปทั่วไป/สลิปซื้อของปกติ → ส่งต่อให้บริการเดิม
        renew_mark_slip_received($pay);
        $img = line_content((string)($msg['id'] ?? ''));
        if ($img !== null) renew_save_slip($pay['id'], $img);
        line_reply($reply, [line_text(renew_msg('msg_slip_received', renew_msg_vars(renew_get($pay['renewal_id']) ?: [], ['amount' => renew_baht($pay['amount'])])))]);
        return true;
    }

    if (($msg['type'] ?? '') !== 'text') return false;
    $text = trim((string)($msg['text'] ?? ''));
    $st = renew_settings();

    // ---------- แอดมินลงทะเบียนรับแจ้งเตือน ----------
    if ($st['admin_code'] !== '' && strcasecmp($text, $st['admin_code']) === 0) {
        renew_db()->prepare("UPDATE renew_contacts SET is_admin = 1 WHERE line_user_id = ?")->execute([$uid]);
        renew_log('👤 เพิ่มแอดมินรับแจ้งเตือน', ['LINE: ' . renew_contact_name($uid)]);
        line_reply($reply, [line_text('✅ บัญชีนี้จะได้รับแจ้งเตือนระบบต่อเมลทาง LINE แล้วค่ะ')]);
        return true;
    }

    // ---------- ลูกค้าพิมพ์รหัสผูกแชท ----------
    if (preg_match('/^R\d{5,6}$/i', $text)) {
        $code = strtoupper($text);
        $q = renew_db()->prepare("SELECT * FROM renewals WHERE link_code = ?");
        $q->execute([$code]);
        $r = $q->fetch();
        if (!$r) {
            line_reply($reply, [line_text("ไม่พบรหัส $code กรุณาตรวจสอบรหัสกับแอดมินอีกครั้งค่ะ")]);
            return true;
        }
        if ($r['line_user_id'] && $r['line_user_id'] !== $uid) {
            line_reply($reply, [line_text('รหัสนี้ถูกผูกกับบัญชี LINE อื่นไปแล้ว กรุณาทักแอดมินค่ะ')]);
            return true;
        }
        $name = renew_contact_name($uid);
        if (!$r['line_user_id']) {
            renew_db()->prepare("UPDATE renewals SET line_user_id = ?, line_name = ?, updated_at = ? WHERE id = ? AND line_user_id IS NULL")
                ->execute([$uid, $name, time(), $r['id']]);
            renew_notify_admin('🔗 ลูกค้าผูกแชทต่อเมลแล้ว', ["LINE: $name พิมพ์รหัส $code", "เมลลูกค้า: {$r['customer_email']}",
                'ถ้าชื่อ LINE ไม่ตรงกับเจ้าของเมล → หลังบ้าน → ต่อเมล → ยกเลิกการผูก']);
            $r = renew_get($r['id']);
        }
        // ผูกสำเร็จ → ตอบแค่ข้อความเชื่อมต่อสำเร็จ (ไม่ส่งการ์ดต่ออายุ · การ์ดจะส่งตอนถึงรอบเตือน)
        line_reply($reply, [line_text(renew_msg('msg_linked', renew_msg_vars($r)))]);
        return true;
    }

    // ---------- ขอดูรายการของตัวเอง ----------
    if (preg_match('/^(ต่อเมล|ต่ออายุ|เช็ควันหมด|วันหมดอายุ|ต่อเมล์)$/u', $text)) {
        $rows = renew_user_rows($uid);
        if (!$rows) {
            line_reply($reply, [line_text(renew_msg('msg_no_renewal'))]);
            return true;
        }
        line_reply($reply, [renew_flex_message($rows, '🔁 ต่อเมล')]);
        return true;
    }
    // ข้อความอื่น: ไม่ตอบ (ส่งต่อให้บริการเดิม)
    return false;
}
