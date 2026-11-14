<?php
// =========================================================================
// renew_cron.php — งานเป็นรอบของระบบต่อเมล (เตือนก่อนหมดอายุ / แจ้งแอดมินเมื่อเลยกำหนด / ปิด QR ที่หมดเวลา)
// ตั้งให้ cron-job.org เรียกทุก 10–30 นาที:  https://<โดเมนเว็บ>/renew_cron.php?key=<รหัสจากหน้าแอดมิน>
// =========================================================================
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/renew_lib.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(renew_settings()['cron_key'], $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'bad key']);
        exit;
    }
    echo json_encode(['ok' => true] + renew_sweep());
} catch (Throwable $e) {
    error_log('[renew_cron] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
