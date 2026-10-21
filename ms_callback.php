<?php
// =========================================================================
// Microsoft OAuth Callback — รับ authorization code กลับมาจาก Microsoft แล้วแลกเป็น
// access token + refresh token เพื่อเก็บไว้อ่านกล่องเมล Hotmail/Outlook ผ่าน Graph API
//
// หมายเหตุ: ไฟล์นี้ย้ายมาไว้ที่ root (จากเดิม api/auth/microsoft/callback.php) เพราะพบว่า
// path /api/... บนโฮสติ้งนี้มีบางอย่าง (WAF/redirect rule) ตัด query string ทิ้งก่อนถึง PHP
// ย้ายมาเป็นไฟล์แบบเรียบที่ root แบบเดียวกับไฟล์อื่นๆ ในเว็บ (admin.php, get_otp.php) แก้ปัญหานี้ได้
// =========================================================================
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/config_store.php';

function redirect_with_error($message) {
    header('Location: /admin.php?ms_error=' . urlencode($message));
    exit;
}

try {
    $config_data = load_config();
} catch (Throwable $e) {
    redirect_with_error('เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบไฟล์ db_credentials.php บนโฮสต์');
}

// ผู้ใช้กดยกเลิก หรือ Microsoft ปฏิเสธคำขอ
if (isset($_GET['error'])) {
    $desc = $_GET['error_description'] ?? $_GET['error'];
    redirect_with_error($desc);
}

$client_id = $config_data['microsoft_oauth']['client_id'] ?? '';
$client_secret = $config_data['microsoft_oauth']['client_secret'] ?? '';
if (empty($client_id) || empty($client_secret)) {
    redirect_with_error('ยังไม่ได้ตั้งค่า Client ID / Client Secret ในหน้า Admin');
}

// ตรวจสอบ state เพื่อป้องกัน CSRF โดยเช็คลายเซ็น HMAC แทนการเทียบกับค่าใน Session
// (ไม่ใช้ Session เพราะ browser ต้องกระโดดออกไปที่ login.microsoftonline.com แล้ววกกลับมา
// ซึ่ง Session Cookie อาจไม่ติดกลับมาด้วย ขึ้นอยู่กับ policy ของแต่ละโฮสติ้ง)
$state = $_GET['state'] ?? '';
$state_parts = explode('.', $state, 2);
if (count($state_parts) !== 2) {
    redirect_with_error('state ไม่ถูกต้อง กรุณาลองเชื่อมต่อใหม่อีกครั้ง');
}
list($ms_nonce, $ms_sig) = $state_parts;
$expected_sig = hash_hmac('sha256', $ms_nonce, $client_secret);
if (!hash_equals($expected_sig, $ms_sig)) {
    redirect_with_error('state ไม่ตรงกัน กรุณาลองเชื่อมต่อใหม่อีกครั้ง');
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    redirect_with_error('ไม่พบ authorization code จาก Microsoft');
}

// ต้องเหมือนกับ Redirect URI ที่ใช้ตอนเริ่มขั้นตอน (ใน admin.php) และที่ลงทะเบียนไว้ใน Azure เป๊ะๆ
$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . '/ms_callback.php';

// -------------------------------------------------------------------------
// แลก authorization code เป็น access token + refresh token
// -------------------------------------------------------------------------
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id'     => $client_id,
    'client_secret' => $client_secret,
    'code'          => $code,
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code',
    'scope'         => 'offline_access Mail.Read openid profile email'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$token_res = curl_exec($ch);
$token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($token_http_code !== 200 || !$token_res) {
    redirect_with_error('แลก token ไม่สำเร็จ (HTTP ' . $token_http_code . ')');
}

$token_data = json_decode($token_res, true);
$access_token = $token_data['access_token'] ?? '';
$refresh_token = $token_data['refresh_token'] ?? '';

if (empty($access_token) || empty($refresh_token)) {
    redirect_with_error('ไม่ได้รับ access token หรือ refresh token กลับมา');
}

// -------------------------------------------------------------------------
// หาว่า token นี้เป็นของบัญชีอีเมลไหน โดยถอดรหัส id_token (JWT) ที่ Microsoft แถมมาให้เลย
// (เชื่อถือได้กว่าเรียก Graph API /me ซึ่งบางครั้งคืน 401 UnknownError กับบัญชีส่วนบุคคลบางกรณี)
// -------------------------------------------------------------------------
function decode_jwt_payload($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    $payload_b64 = strtr($parts[1], '-_', '+/');
    $payload_b64 .= str_repeat('=', (4 - strlen($payload_b64) % 4) % 4);
    $payload_json = base64_decode($payload_b64);
    return $payload_json !== false ? json_decode($payload_json, true) : null;
}

$account_email = '';
$id_token = $token_data['id_token'] ?? '';
if (!empty($id_token)) {
    $claims = decode_jwt_payload($id_token);
    $account_email = $claims['email'] ?? ($claims['preferred_username'] ?? '');
}

// สำรอง: ถ้า id_token ไม่มี email claim ให้ลองเรียก Graph API /me เป็นทางเลือกสุดท้าย
if (empty($account_email)) {
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName');
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
    $me_res = curl_exec($ch2);
    $me_http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($me_http_code === 200 && $me_res) {
        $me_data = json_decode($me_res, true);
        $account_email = $me_data['mail'] ?? ($me_data['userPrincipalName'] ?? '');
    }
}

if (empty($account_email)) {
    redirect_with_error('ไม่พบที่อยู่อีเมลของบัญชีนี้');
}

// -------------------------------------------------------------------------
// บันทึกบัญชีนี้ลงใน imap_emails (เพิ่มใหม่ หรืออัปเดตถ้ามีอยู่แล้ว)
// -------------------------------------------------------------------------
if (!isset($config_data['imap_emails']) || !is_array($config_data['imap_emails'])) {
    $config_data['imap_emails'] = [];
}

$found_idx = -1;
foreach ($config_data['imap_emails'] as $idx => $item) {
    if (strtolower($item['email'] ?? '') === strtolower($account_email)) {
        $found_idx = $idx;
        break;
    }
}

$new_item = [
    'id'            => $found_idx >= 0 ? $config_data['imap_emails'][$found_idx]['id'] : 'msgraph_' . time(),
    'email'         => $account_email,
    'provider'      => 'microsoft_graph',
    'refresh_token' => $refresh_token,
    'app_id'        => 'all',
    'active'        => true
];

if ($found_idx >= 0) {
    $config_data['imap_emails'][$found_idx] = $new_item;
} else {
    $config_data['imap_emails'][] = $new_item;
}

save_config($config_data);

header('Location: /admin.php?ms_connected=' . urlencode($account_email));
exit;
