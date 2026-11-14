<?php
// =========================================================================
// renew_card.php — รูปการ์ดเตือนที่แอดมินอัปโหลดเอง (เก็บในฐานข้อมูล เพราะ Render ฟรีลบไฟล์อัปโหลดทุกครั้งที่รีสตาร์ต)
// LINE ดึงรูปจาก URL นี้ · ไม่มีรูปที่อัปโหลด → ใช้ static/renew_card.jpg แทน
// =========================================================================
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/renew_lib.php';

$k = (($_GET['k'] ?? 'card') === 'pay') ? 'pay' : 'card';
$c = renew_file($k);
if (!$c) {
    header('Location: /' . RENEW_IMAGE_DEFAULTS[$k], true, 302);
    exit;
}
header('Content-Type: ' . $c['mime']);
header('Cache-Control: public, max-age=86400'); // URL มี ?v=<เวลาอัปโหลด> อยู่แล้ว เปลี่ยนรูป = URL ใหม่
echo base64_decode($c['data']);
