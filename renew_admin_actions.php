<?php
// =========================================================================
// renew_admin_actions.php — คำขอ AJAX ของแท็บ "ต่อเมล" ในหน้าแอดมิน
// ถูก include จาก admin.php หลังตรวจสิทธิ์ล็อกอินแล้วเท่านั้น ($action ขึ้นต้นด้วย renew_)
// =========================================================================
require_once __DIR__ . '/renew_lib.php';
header('Content-Type: application/json; charset=utf-8');

function renew_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function renew_fail($msg, $code = 400) { renew_out(['success' => false, 'message' => $msg], $code); }
function renew_post($k, $default = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default; }
function renew_valid_date($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }

try {
    $db = renew_db();

    // ---------- โหลดข้อมูลทั้งหมดของแท็บ ----------
    if ($action === 'renew_data') {
        $st = renew_settings();
        $packages = renew_packages();
        $pk = [];
        foreach ($packages as $p) $pk[$p['id']] = $p;
        $rows = $db->query("SELECT * FROM renewals ORDER BY status = 'active' DESC, expires_on ASC")->fetchAll();
        $pending = [];
        foreach ($db->query("SELECT id, renewal_id, amount, status, created_at FROM renew_payments WHERE status = 'slip' OR (status = 'pending' AND expires_at > " . (time() - RENEW_SLIP_GRACE) . ") ORDER BY created_at")->fetchAll() as $p) {
            if (isset($pending[$p['renewal_id']]) && $pending[$p['renewal_id']]['status'] === 'slip' && $p['status'] !== 'slip') continue;
            $pending[$p['renewal_id']] = $p;
        }
        $list = [];
        $fams = [];
        foreach ($rows as $r) {
            $fee = renew_fee($r);
            $list[] = [
                'id' => $r['id'], 'customer_email' => $r['customer_email'], 'shop_email' => $r['shop_email'],
                'package_id' => $r['package_id'], 'package' => $pk[$r['package_id']]['name'] ?? '-',
                'line_linked' => (bool)$r['line_user_id'], 'line_name' => $r['line_name'], 'link_code' => $r['link_code'],
                'expires_on' => $r['expires_on'], 'expires_th' => renew_date_th($r['expires_on']), 'left' => renew_days_left($r['expires_on']),
                'deadline_th' => renew_date_th(renew_deadline($r)), 'fee' => $fee['fee'],
                'note' => $r['note'], 'status' => $r['status'], 'removed' => !empty($r['removed_at']),
                'reminded' => $r['reminded_for'] === $r['expires_on'], 'renewed' => (int)$r['renewed_count'],
                'pending' => isset($pending[$r['id']]) ? (float)$pending[$r['id']]['amount'] : null,
                'pay_id' => $pending[$r['id']]['id'] ?? null,
                'slip_sent' => ($pending[$r['id']]['status'] ?? '') === 'slip',
            ];
            if ($r['status'] === 'active' && empty($r['removed_at']) && $r['shop_email'] !== '') {
                $k = strtolower($r['shop_email']);
                $fams[$k] = $fams[$k] ?? ['email' => $r['shop_email'], 'members' => []];
                $fams[$k]['members'][] = $r['customer_email'];
            }
        }
        ksort($fams);
        $site = renew_site_url();
        $log = $db->query("SELECT created_at, title, body FROM renew_log ORDER BY created_at DESC LIMIT 40")->fetchAll();
        foreach ($log as &$l) $l['time'] = (new DateTime('@' . $l['created_at']))->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('d/m H:i');
        unset($l);
        $admins = $db->query("SELECT display_name FROM renew_contacts WHERE is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);
        $last_cron = (int)($db->query("SELECT v FROM renew_kv WHERE k = 'last_cron'")->fetchColumn() ?: 0);
        renew_out([
            'success' => true,
            'today' => renew_today(),
            'renewals' => $list,
            'packages' => array_map(function ($p) { return ['id' => $p['id'], 'name' => $p['name'], 'price' => (float)$p['price'], 'days' => (int)$p['days'], 'active' => (bool)$p['active']]; }, $packages),
            'fams' => array_values($fams),
            'settings' => [
                'line_token_set' => $st['line_token'] !== '', 'line_secret_set' => $st['line_secret'] !== '',
                'slip2go_secret_set' => $st['slip2go_secret'] !== '',
                'card_style' => $st['card_style'], 'pay_before_days' => (int)$st['pay_before_days'],
                'card_url' => ($cc = renew_card_custom()) ? 'renew_card.php?v=' . (int)$cc['updated_at'] : 'static/renew_card.jpg',
                'card_custom' => (bool)$cc,
                'slip_mode' => $st['slip_mode'], 'line_oa_id' => $st['line_oa_id'], 'forward_webhook' => $st['forward_webhook'], 'slip2go_url' => $st['slip2go_url'],
                'promptpay_id' => $st['promptpay_id'], 'pay_name' => $st['pay_name'],
                'receiver_type' => $st['receiver_type'], 'receiver_account' => $st['receiver_account'],
                'remind_days' => (int)$st['remind_days'], 'grace_days' => (int)$st['grace_days'],
                'late_fee' => (float)$st['late_fee'], 'removed_fee' => (float)$st['removed_fee'], 'fam_size' => max(1, (int)$st['fam_size']),
                'admin_code' => $st['admin_code'],
                'webhook_url' => $site . '/line_webhook.php',
                'cron_url' => $site . '/renew_cron.php?key=' . $st['cron_key'],
                'last_cron' => $last_cron ? (new DateTime('@' . $last_cron))->setTimezone(new DateTimeZone('Asia/Bangkok'))->format('d/m H:i') : '',
                'admins' => $admins,
            ],
            'log' => $log,
        ]);
    }

    // ---------- เพิ่ม/แก้ไข ลูกค้าต่อเมล ----------
    if ($action === 'renew_save') {
        $id = renew_post('id');
        $email = strtolower(renew_post('customer_email'));
        $fam = strtolower(renew_post('shop_email'));
        $pkg = renew_post('package_id');
        $exp = renew_post('expires_on');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) renew_fail('กรุณากรอกเมลลูกค้าให้ถูกต้อง');
        if ($fam !== '' && !filter_var($fam, FILTER_VALIDATE_EMAIL)) renew_fail('เมลร้าน (fam) ไม่ถูกต้อง');
        if (!renew_package($pkg)) renew_fail('กรุณาเลือกแพ็กเกจ (เพิ่มแพ็กเกจในส่วน "แพ็กเกจต่อเมล" ก่อน)');
        if (!renew_valid_date($exp)) renew_fail('กรุณาเลือกวันหมดอายุ');
        $note = mb_substr(renew_post('note'), 0, 500);
        if ($id !== '') {
            $r = renew_get($id);
            if (!$r) renew_fail('ไม่พบรายการ', 404);
            // เปลี่ยนวันหมดอายุ → เริ่มนับเตือนรอบใหม่
            // (MySQL ใช้ค่าที่เพิ่งตั้งในคำสั่งเดียวกัน → ต้องเช็ค expires_on เดิมก่อนเปลี่ยน)
            $db->prepare("UPDATE renewals SET reminded_for = CASE WHEN expires_on = ? THEN reminded_for ELSE NULL END,
                          lapsed_for = CASE WHEN expires_on = ? THEN lapsed_for ELSE NULL END,
                          customer_email = ?, shop_email = ?, package_id = ?, expires_on = ?, note = ?, updated_at = ? WHERE id = ?")
               ->execute([$exp, $exp, $email, $fam, $pkg, $exp, $note, time(), $id]);
            renew_out(['success' => true, 'message' => 'บันทึกแล้ว']);
        }
        $code = renew_new_link_code();
        $db->prepare("INSERT INTO renewals (id, customer_email, shop_email, package_id, line_user_id, line_name, link_code, expires_on, note, status, renewed_count, created_at, updated_at)
                      VALUES (?, ?, ?, ?, NULL, '', ?, ?, ?, 'active', 0, ?, ?)")
           ->execute([renew_id('r'), $email, $fam, $pkg, $code, $exp, $note, time(), time()]);
        renew_out(['success' => true, 'message' => 'เพิ่มลูกค้าแล้ว', 'link_code' => $code]);
    }

    // ---------- คำสั่งกับรายการเดียว ----------
    if (in_array($action, ['renew_delete', 'renew_extend', 'renew_removed', 'renew_cancel', 'renew_unlink', 'renew_remind'], true)) {
        $r = renew_get(renew_post('id'));
        if (!$r) renew_fail('ไม่พบรายการ', 404);

        if ($action === 'renew_delete') {
            $db->prepare("DELETE FROM renewals WHERE id = ?")->execute([$r['id']]);
            $db->prepare("UPDATE renew_payments SET status = 'cancelled' WHERE renewal_id = ? AND status = 'pending'")->execute([$r['id']]);
            renew_out(['success' => true, 'message' => 'ลบรายการแล้ว']);
        }
        if ($action === 'renew_extend') {
            // แอดมินต่อเอง (ลูกค้าโอนนอกระบบ / ตรวจสลิปเอง)
            $pkg = renew_package(renew_post('package_id', $r['package_id']));
            if (!$pkg) renew_fail('กรุณาเลือกแพ็กเกจ');
            $was_removed = !empty($r['removed_at']);
            $new = renew_extend($r['id'], $pkg['id'], (int)$pkg['days']);
            $db->prepare("UPDATE renew_payments SET status = 'cancelled' WHERE renewal_id = ? AND status IN ('pending', 'slip')")->execute([$r['id']]);
            renew_log('🔁 แอดมินต่ออายุให้เอง', ["เมลลูกค้า: {$r['customer_email']}", "แพ็กเกจ: {$pkg['name']}", 'ใช้ได้ถึง ' . renew_date_th($new)]);
            if (renew_post('notify') === '1' && $r['line_user_id']) {
                line_push($r['line_user_id'], [line_text("✅ ต่ออายุเรียบร้อยค่ะ\nเมล: {$r['customer_email']}\nใช้ได้ถึง " . renew_date_th($new) . "\n\nขอบคุณที่ใช้บริการนะคะ 💖")]);
            }
            renew_out(['success' => true, 'message' => 'ต่ออายุถึง ' . renew_date_th($new) . ($was_removed ? ' — อย่าลืมเชิญเมลกลับเข้า fam' : '')]);
        }
        if ($action === 'renew_removed') {
            $on = renew_post('on') === '1';
            $db->prepare("UPDATE renewals SET removed_at = ?, updated_at = ? WHERE id = ?")->execute([$on ? time() : null, time(), $r['id']]);
            renew_out(['success' => true, 'message' => $on ? 'ทำเครื่องหมายว่าลบเมลออกจาก fam แล้ว' : 'ยกเลิกเครื่องหมายลบเมลแล้ว']);
        }
        if ($action === 'renew_cancel') {
            $on = renew_post('on') === '1';
            $db->prepare("UPDATE renewals SET status = ?, updated_at = ? WHERE id = ?")->execute([$on ? 'cancelled' : 'active', time(), $r['id']]);
            renew_out(['success' => true, 'message' => $on ? 'หยุดต่อ/ไม่เตือนรายการนี้แล้ว' : 'เปิดรายการนี้อีกครั้งแล้ว']);
        }
        if ($action === 'renew_unlink') {
            $db->prepare("UPDATE renewals SET line_user_id = NULL, line_name = '', link_code = ?, updated_at = ? WHERE id = ?")
               ->execute([renew_new_link_code(), time(), $r['id']]);
            renew_out(['success' => true, 'message' => 'ยกเลิกการผูก LINE แล้ว (ได้รหัสใหม่)']);
        }
        if ($action === 'renew_remind') {
            if (!$r['line_user_id']) renew_fail('ลูกค้ายังไม่ได้ผูกแชท LINE — ส่งรหัส ' . $r['link_code'] . ' ให้ลูกค้าก่อน');
            if (renew_settings()['line_token'] === '') renew_fail('ยังไม่ได้ใส่ Channel access token ของ LINE');
            $msg = renew_flex_message([$r]);
            if (!line_push($r['line_user_id'], [$msg])) renew_fail('ส่ง LINE ไม่สำเร็จ (ลูกค้าอาจบล็อก LINE ร้าน)');
            $db->prepare("UPDATE renewals SET reminded_for = ? WHERE id = ?")->execute([$r['expires_on'], $r['id']]);
            renew_out(['success' => true, 'message' => 'ส่งการ์ดเตือนทาง LINE แล้ว']);
        }
    }

    // ---------- ยืนยัน / ปฏิเสธ สลิปที่ลูกค้าส่ง (โหมดให้ Slip2Go ในแชทตรวจ) ----------
    if ($action === 'renew_confirm_pay') {
        $res = renew_confirm_payment(renew_post('pay_id'));
        if (!$res['ok']) renew_fail($res['error']);
        renew_out(['success' => true, 'message' => 'ต่ออายุถึง ' . renew_date_th($res['until']) . ' และแจ้งลูกค้าแล้ว' . ($res['readd'] ? ' — อย่าลืมเชิญเมลกลับเข้า fam' : '')]);
    }
    if ($action === 'renew_reject_pay') {
        $q = $db->prepare("SELECT * FROM renew_payments WHERE id = ? AND status IN ('slip', 'pending')");
        $q->execute([renew_post('pay_id')]);
        $pay = $q->fetch();
        if (!$pay) renew_fail('ไม่พบรายการ');
        $db->prepare("UPDATE renew_payments SET status = 'rejected' WHERE id = ?")->execute([$pay['id']]);
        if (renew_post('notify') === '1') {
            $r = renew_get($pay['renewal_id']);
            if ($r && $r['line_user_id']) line_push($r['line_user_id'], [line_text('❌ สลิปค่าต่ออายุยังไม่ผ่านการตรวจค่ะ กรุณาทักแอดมินพร้อมสลิป หรือพิมพ์ "ต่อเมล" เพื่อทำรายการใหม่')]);
        }
        renew_out(['success' => true, 'message' => 'ปฏิเสธสลิปแล้ว']);
    }

    // ---------- รูปการ์ดเตือน ----------
    if ($action === 'renew_card_upload') {
        $f = $_FILES['card'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) renew_fail('อัปโหลดไม่สำเร็จ (ไฟล์ต้องไม่เกิน 1.5MB)');
        $info = @getimagesize($f['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) renew_fail('รองรับเฉพาะไฟล์ JPG หรือ PNG');
        if (filesize($f['tmp_name']) > 1536 * 1024) renew_fail('ไฟล์ใหญ่เกิน 1.5MB');
        if (abs($info[0] - $info[1]) > max(4, $info[0] * 0.02)) renew_fail('รูปต้องเป็นสี่เหลี่ยมจัตุรัส (กว้าง = สูง) แบบเดียวกับรูปตั้งต้น เพื่อให้ข้อความลงช่องพอดี');
        $data = base64_encode(file_get_contents($f['tmp_name']));
        $db->prepare("DELETE FROM renew_files WHERE k = 'card'")->execute();
        $db->prepare("INSERT INTO renew_files (k, mime, data, updated_at) VALUES ('card', ?, ?, ?)")->execute([$mime, $data, time()]);
        renew_out(['success' => true, 'message' => 'อัปโหลดรูปการ์ดแล้ว']);
    }
    if ($action === 'renew_card_reset') {
        $db->prepare("DELETE FROM renew_files WHERE k = 'card'")->execute();
        renew_out(['success' => true, 'message' => 'กลับไปใช้รูปการ์ดตั้งต้นแล้ว']);
    }
    if ($action === 'renew_card_test') {
        // ส่งการ์ดตัวอย่างหาแอดมินที่ลงทะเบียนรับแจ้งเตือน (ดูหน้าตาจริงใน LINE)
        $admins = $db->query("SELECT line_user_id FROM renew_contacts WHERE is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);
        if (!$admins) renew_fail('ยังไม่มีแอดมินรับแจ้งเตือน — พิมพ์รหัสแอดมินในแชท LINE ร้านก่อน');
        $sample = ['id' => 'sample', 'customer_email' => 'customer.email@gmail.com', 'expires_on' => renew_today(3), 'shop_email' => 'fam@gmail.com',
                   'package_id' => '', 'removed_at' => null];
        $msg = renew_flex_message([$sample]);
        $sent = 0;
        foreach ($admins as $uid) if (line_push($uid, [line_text('ตัวอย่างการ์ดเตือนต่ออายุ (ปุ่มในการ์ดตัวอย่างกดใช้งานจริงไม่ได้)'), $msg])) $sent++;
        if (!$sent) renew_fail('ส่ง LINE ไม่สำเร็จ — ตรวจ Channel access token แล้วลองใหม่');
        renew_out(['success' => true, 'message' => "ส่งการ์ดตัวอย่างให้แอดมิน $sent คนแล้ว ดูใน LINE ได้เลย"]);
    }

    // ---------- แพ็กเกจ ----------
    if ($action === 'renew_pkg_save') {
        $id = renew_post('id');
        $name = mb_substr(renew_post('name'), 0, 120);
        $price = (float)str_replace(',', '', renew_post('price'));
        $days = (int)renew_post('days');
        if ($name === '') renew_fail('กรุณากรอกชื่อแพ็กเกจ');
        if ($price <= 0) renew_fail('ราคาต้องมากกว่า 0');
        if ($days <= 0 || $days > 3660) renew_fail('จำนวนวันไม่ถูกต้อง');
        $active = renew_post('active', '1') === '1' ? 1 : 0;
        if ($id !== '' && renew_package($id)) {
            $db->prepare("UPDATE renew_packages SET name = ?, price = ?, days = ?, active = ? WHERE id = ?")->execute([$name, $price, $days, $active, $id]);
        } else {
            $sort = (int)$db->query("SELECT COUNT(*) FROM renew_packages")->fetchColumn();
            $db->prepare("INSERT INTO renew_packages (id, name, price, days, active, sort) VALUES (?, ?, ?, ?, ?, ?)")
               ->execute([renew_id('k'), $name, $price, $days, $active, $sort]);
        }
        renew_out(['success' => true, 'message' => 'บันทึกแพ็กเกจแล้ว']);
    }
    if ($action === 'renew_pkg_delete') {
        $id = renew_post('id');
        $used = $db->prepare("SELECT COUNT(*) FROM renewals WHERE package_id = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) renew_fail('มีลูกค้าใช้แพ็กเกจนี้อยู่ — ปิดการใช้งานแทนการลบ');
        $db->prepare("DELETE FROM renew_packages WHERE id = ?")->execute([$id]);
        renew_out(['success' => true, 'message' => 'ลบแพ็กเกจแล้ว']);
    }

    // ---------- ตั้งค่า ----------
    if ($action === 'renew_settings_save') {
        if (isset($_POST['slip_mode'])) $_POST['slip_mode'] = renew_post('slip_mode') === 'api' ? 'api' : 'chat';
        if (isset($_POST['card_style'])) $_POST['card_style'] = renew_post('card_style') === 'basic' ? 'basic' : 'image';
        $plain = ['slip_mode', 'card_style', 'line_oa_id', 'forward_webhook', 'slip2go_url', 'promptpay_id', 'pay_name', 'receiver_type', 'receiver_account'];
        $secret = ['line_token', 'line_secret', 'slip2go_secret']; // เว้นว่าง = ไม่เปลี่ยน
        $nums = ['pay_before_days' => [0, 30], 'remind_days' => [0, 30], 'grace_days' => [0, 60], 'late_fee' => [0, 100000], 'removed_fee' => [0, 100000], 'fam_size' => [1, 100]];
        if (isset($_POST['promptpay_id'])) {
            $pp = preg_replace('/\D/', '', renew_post('promptpay_id'));
            if ($pp !== '' && !in_array(strlen($pp), [10, 13, 15], true)) renew_fail('พร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก / เลข 13 หลัก / e-Wallet 15 หลัก');
            $_POST['promptpay_id'] = $pp;
        }
        if (isset($_POST['forward_webhook']) && renew_post('forward_webhook') !== '') {
            $fw = renew_post('forward_webhook');
            if (!preg_match('#^https://#i', $fw)) renew_fail('ลิงก์ Webhook เดิมต้องขึ้นต้นด้วย https://');
            if (stripos($fw, '/line_webhook.php') !== false && stripos($fw, (string)($_SERVER['HTTP_HOST'] ?? '#')) !== false) renew_fail('ห้ามใส่ Webhook ของเว็บนี้เอง (จะวนส่งไม่จบ) — ใส่ลิงก์ของ Slip2Go หรือบริการเดิม');
        }
        if (isset($_POST['slip2go_url']) && renew_post('slip2go_url') !== '' && !preg_match('#^https://#i', renew_post('slip2go_url'))) {
            renew_fail('Slip2Go API URL ต้องขึ้นต้นด้วย https://');
        }
        foreach ($plain as $k) if (isset($_POST[$k])) renew_set($k, mb_substr(renew_post($k), 0, 300));
        foreach ($secret as $k) if (renew_post($k) !== '') renew_set($k, renew_post($k));
        foreach ($nums as $k => [$lo, $hi]) {
            if (!isset($_POST[$k])) continue;
            $v = (float)renew_post($k);
            renew_set($k, (string)max($lo, min($hi, $v)));
        }
        if (renew_post('new_cron_key') === '1') renew_set('cron_key', bin2hex(random_bytes(12)));
        renew_out(['success' => true, 'message' => 'บันทึกการตั้งค่าแล้ว']);
    }

    // ---------- ทดสอบ LINE: ส่งข้อความหาแอดมินที่ลงทะเบียนไว้ ----------
    if ($action === 'renew_test_line') {
        renew_settings(true);
        [$http, $res] = line_api('GET', 'info');
        if ($http !== 200) renew_fail('เชื่อมต่อ LINE ไม่ได้ (HTTP ' . $http . ') — ตรวจ Channel access token อีกครั้ง');
        $bot = json_decode($res, true);
        $admins = $db->query("SELECT line_user_id FROM renew_contacts WHERE is_admin = 1")->fetchAll(PDO::FETCH_COLUMN);
        $sent = 0;
        foreach ($admins as $uid) if (line_push($uid, [line_text('✅ ทดสอบแจ้งเตือนระบบต่อเมลจากหน้าแอดมิน')])) $sent++;
        renew_out(['success' => true, 'message' => 'เชื่อมต่อ LINE OA "' . ($bot['displayName'] ?? '') . '" สำเร็จ'
            . ($admins ? " · ส่งข้อความทดสอบถึงแอดมิน $sent/" . count($admins) . ' คน' : ' · ยังไม่มีแอดมินลงทะเบียนรับแจ้งเตือน')]);
    }

    renew_fail('ไม่รู้จักคำสั่ง', 404);
} catch (Throwable $e) {
    error_log('[renew_admin] ' . $e->getMessage());
    renew_fail('เกิดข้อผิดพลาด: ' . $e->getMessage(), 500);
}
