<?php
// =========================================================================
// renew_qr.php — รูป QR พร้อมเพย์ของรายการรอจ่าย (LINE ดึงรูปจาก URL นี้)
// ข้อความ QR สร้างในเว็บเอง (ยอดพอดีตามรายการ) แล้วให้บริการวาดรูป QR ภายนอกแปลงเป็น PNG
// =========================================================================
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/renew_lib.php';

$id = (string)($_GET['id'] ?? '');
$t  = (string)($_GET['t'] ?? '');
if (!preg_match('/^p[0-9a-f]{16}$/', $id) || !hash_equals(renew_qr_sig($id), $t)) {
    http_response_code(404);
    exit;
}
$st = renew_db()->prepare("SELECT amount FROM renew_payments WHERE id = ?");
$st->execute([$id]);
$amount = $st->fetchColumn();
$payload = $amount !== false ? promptpay_payload(renew_settings()['promptpay_id'], (float)$amount) : null;
if (!$payload) {
    http_response_code(404);
    exit;
}

// เก็บรูปไว้ในเครื่องชั่วคราว (LINE ขอรูปหลายครั้ง: ตัวอย่าง + ภาพเต็ม)
$cache = sys_get_temp_dir() . '/renew_qr_' . md5($payload) . '.png';
$png = is_file($cache) ? file_get_contents($cache) : false;
if (!$png) {
    $sources = [
        'https://api.qrserver.com/v1/create-qr-code/?size=720x720&margin=24&ecc=M&format=png&data=' . rawurlencode($payload),
        'https://quickchart.io/qr?size=720&margin=3&ecLevel=M&format=png&text=' . rawurlencode($payload),
    ];
    foreach ($sources as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true]);
        $res = curl_exec($ch);
        $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && is_string($res) && substr($res, 0, 4) === "\x89PNG";
        curl_close($ch);
        if ($ok) { $png = $res; @file_put_contents($cache, $png); break; }
    }
}
if (!$png) {
    http_response_code(502);
    exit;
}
header('Content-Type: image/png');
header('Cache-Control: public, max-age=900');
echo $png;
