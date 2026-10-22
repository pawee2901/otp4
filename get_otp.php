<?php
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

// =========================================================================
// 1. รับค่าอีเมลและชื่อบริการจากหน้าบ้าน (POST Method)
// =========================================================================
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$app_name = isset($_POST['app_name']) ? trim($_POST['app_name']) : '';

if (empty($email)) {
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาระบุอีเมลเพื่อค้นหารหัส OTP'
    ]);
    exit;
}

if (empty($app_name)) {
    echo json_encode([
        'success' => false,
        'message' => 'กรุณาระบุชื่อบริการที่เลือกดึงรหัส'
    ]);
    exit;
}

// ตรวจสอบรูปแบบอีเมลเบื้องต้น
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'message' => 'รูปแบบอีเมลไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง'
    ]);
    exit;
}

// =========================================================================
// 2. การตั้งค่าการเชื่อมต่อ API จริง (Maily Space & Cloud Run)
// =========================================================================
require_once __DIR__ . '/config_store.php';
$config_path = __DIR__ . '/config.json'; // เก็บชื่อตัวแปรไว้เผื่อโค้ดจุดอื่นอ้างอิง (ไม่ได้ใช้อ่าน/เขียนไฟล์แล้ว)

try {
    $config_data = load_config();
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบไฟล์ db_credentials.php บนโฮสต์'
    ]);
    exit;
}

// งบเวลาสำหรับไล่สแกนบัญชี IMAP ในคำขอนี้ (วินาที) — ถ้าเกินนี้แล้วยังไม่เจอ ให้หยุดค้นแล้วตอบกลับ "ยังไม่พบ" ทันที
// ไม่ต้องรอไล่จนครบทุกบัญชี เพราะหน้าเว็บมี auto-poll ซ้ำทุก 5 วินาทีอยู่แล้ว (ดู startOTPPolling ใน index.php)
// คำขอรอบถัดไปจะสลับไปเริ่มไล่จากบัญชีอื่นต่อ (ดู run_gmail_imap_fallback) ให้ท้ายที่สุดตรวจครบทุกบัญชีภายในไม่กี่รอบ
// แทนที่จะรอจนครบในคำขอเดียวซึ่งอาจโดน proxy/PHP timeout ตัดกลางคันจนหน้าเว็บเจอ "Load failed"
define('SEARCH_DEADLINE', microtime(true) + 4);

$maily_api_url = "https://api.maily.space/mail/public/mails";
$maily_tokens = [];
if (isset($config_data['api_providers']) && is_array($config_data['api_providers'])) {
    foreach ($config_data['api_providers'] as $p) {
        if (!empty($p['token']) && ($p['type'] ?? 'maily') === 'maily') {
            $maily_tokens[] = trim($p['token']);
        }
    }
}
if (empty($maily_tokens)) {
    $token_1 = $config_data['api_key_1'] ?? "";
    $token_2 = $config_data['api_key_2'] ?? "";
    $maily_tokens = array_values(array_filter([$token_1, $token_2]));
}
$cloud_run_url = "https://getemails-wfudlrftlq-uc.a.run.app/getEmails";

// ฟังก์ชันแปลงชื่อบริการเป็นรหัสย่อของระบบ RDCW Cloud Run
function get_app_code($name) {
    $lower = strtolower($name);
    if (strpos($lower, 'netflix') !== false) {
        return 'NF';
    } elseif (strpos($lower, 'disney') !== false) {
        return 'DN';
    } elseif (strpos($lower, 'true') !== false) {
        return 'TM';
    } elseif (strpos($lower, 'chat') !== false || strpos($lower, 'openai') !== false || strpos($lower, 'gpt') !== false) {
        return 'GPT';
    } elseif (strpos($lower, 'prime') !== false || strpos($lower, 'amazon') !== false) {
        return 'PR';
    }
    return 'GPT';
}

// ฟังก์ชันกรองหัวข้อผู้ส่งให้ตรงตามบริการหลัก
function matches_app($from, $subject, $name, $body = '') {
    $lower_from = strtolower($from);
    $lower_sub = strtolower($subject);
    $lower_app = strtolower($name);
    $lower_body = strtolower($body);
    
    if (strpos($lower_app, 'netflix') !== false) {
        return (strpos($lower_from, 'netflix') !== false || strpos($lower_sub, 'netflix') !== false || strpos($lower_body, 'netflix') !== false);
    } elseif (strpos($lower_app, 'disney') !== false) {
        return (strpos($lower_from, 'disney') !== false || strpos($lower_sub, 'disney') !== false || strpos($lower_body, 'disney') !== false);
    } elseif (strpos($lower_app, 'true') !== false) {
        return (strpos($lower_from, 'true') !== false || strpos($lower_sub, 'true') !== false || strpos($lower_body, 'true') !== false);
    } elseif (strpos($lower_app, 'chat') !== false || strpos($lower_app, 'openai') !== false || strpos($lower_app, 'gpt') !== false) {
        return (strpos($lower_from, 'openai') !== false || strpos($lower_sub, 'openai') !== false || strpos($lower_from, 'chatgpt') !== false || strpos($lower_sub, 'chatgpt') !== false || strpos($lower_body, 'openai') !== false || strpos($lower_body, 'chatgpt') !== false);
    } elseif (strpos($lower_app, 'prime') !== false || strpos($lower_app, 'amazon') !== false) {
        return (strpos($lower_from, 'prime') !== false || strpos($lower_sub, 'prime') !== false || strpos($lower_from, 'amazon') !== false || strpos($lower_sub, 'amazon') !== false || strpos($lower_body, 'prime') !== false || strpos($lower_body, 'amazon') !== false);
    }
    return (strpos($lower_from, $lower_app) !== false || strpos($lower_sub, $lower_app) !== false || strpos($lower_body, $lower_app) !== false);
}

// ฟังก์ชันแปลงรูปแบบเวลา UTC จาก Maily Space เป็นเวลาไทยท้องถิ่น (GMT+07:00)
function parse_utc_timestamp_to_thai($ts_str) {
    try {
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $ts_str, $matches)) {
            $year = intval($matches[1]);
            $month = intval($matches[2]);
            $day = intval($matches[3]);
            $hour = intval($matches[4]);
            $minute = intval($matches[5]);
            $second = intval($matches[6]);
            
            $dt = new DateTime("$year-$month-$day $hour:$minute:$second", new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
            
            $thai_months = [
                1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
                5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
                9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
            ];
            
            $day_num = intval($dt->format('d'));
            $month_name = $thai_months[intval($dt->format('m'))];
            $time_str = $dt->format('H:i');
            
            return "$day_num $month_name เวลา $time_str น. (ตามเวลาประเทศไทย)";
        }
    } catch (Exception $e) {}
    return date('d/m/Y H:i น.');
}

// ฟังก์ชันแปลงวันเวลาของ Cloud Run (e.g. "02/06/2026 06:17") เป็นเวลาไทย
function parse_cloud_run_date_to_thai($date_str) {
    try {
        $dt = DateTime::createFromFormat('d/m/Y H:i', $date_str, new DateTimeZone('UTC'));
        if ($dt) {
            $dt->setTimezone(new DateTimeZone('Asia/Bangkok'));
            $thai_months = [
                1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
                5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
                9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
            ];
            
            $day_num = intval($dt->format('d'));
            $month_name = $thai_months[intval($dt->format('m'))];
            $time_str = $dt->format('H:i');
            
            return "$day_num $month_name เวลา $time_str น. (ตามเวลาประเทศไทย)";
        }
    } catch (Exception $e) {}
    return date('d/m/Y H:i น.');
}

// ฟังก์ชันแกะตัวเลข OTP อย่างแม่นยำจาก HTML Body
// $allowed_digit_lengths: รายการความยาวรหัสที่แอดมินเปิดไว้สำหรับอีเมลนี้ (เช่น [4], [6], [4,6]) จากปุ่มเปิด/ปิด
// อิสระ 4 หลัก/6 หลักในหน้า Admin — ใช้เฉพาะตอนขอรหัส Netflix ส่ง [] (ว่าง) เมื่อยังไม่เปิดปุ่มไหนเลย/ไม่ใช่ Netflix
// เพื่อให้พฤติกรรมเดิม (ตรวจจับอัตโนมัติ ไม่จำกัดความยาว) ไม่เปลี่ยน
function extract_otp_code($html_body, $allowed_digit_lengths = []) {
    if (empty($html_body)) return null;

    // ลบเนื้อหาภายในแท็ก style และ script ออกทั้งหมดก่อนเพื่อป้องกันการดึงรหัสสี/สไตล์ (เช่น #121212)
    $clean_html = preg_replace('/<(style|script)\b[^>]*>(.*?)<\/\1>/is', '', $html_body);
    // แทนที่แท็กทุกตัวด้วยช่องว่าง แทนการใช้ strip_tags() ตรงๆ เพราะ strip_tags() ลบแท็กออกโดยไม่เว้นช่องว่าง
    // ทำให้ข้อความจากคนละ element ถูกเชื่อมติดกัน เช่น "<div>786017</div>If you didn't..." จะกลายเป็น
    // "786017If you didn't..." ซึ่งทำให้ regex ที่อาศัย \b (word boundary) ท้ายตัวเลขไม่ match เพราะ "7" กับ "I"
    // ถือเป็นอักขระประเภทเดียวกัน (ไม่ใช่ขอบเขตคำ) ผลคือดึงรหัส OTP จริงไม่เจอ แล้ว fallback ไปดึงเลขอื่นในอีเมล
    // (เช่น รหัสไปรษณีย์ในที่อยู่ท้ายอีเมล) มาผิดแทน
    $plain_text = preg_replace('/<[^>]+>/', ' ', $clean_html);
    // แปลง HTML entity ที่เหลือค้าง (เช่น &nbsp; ที่อีเมลบางเจ้าใช้คั่นระหว่างตัวเลข OTP แต่ละหลัก)
    // ให้กลายเป็นอักขระจริง — strip_tags() ไม่แปลงให้อัตโนมัติ ปล่อยเป็นข้อความ "&nbsp;" ค้างไว้
    $plain_text = html_entity_decode($plain_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 0. ถ้าแอดมินเปิดปุ่มบังคับความยาวรหัส OTP ของ Netflix ไว้ (4 และ/หรือ 6 หลัก) สำหรับอีเมลนี้ ให้ลองหารหัส
    //    ความยาวที่เปิดไว้ก่อนเป็นอันดับแรกสุดเสมอ (ทั้งแบบมีคีย์เวิร์ดนำหน้าและแบบลอยๆ ในเนื้อหา) กันปัญหาอีเมล Netflix
    //    บางฉบับมีตัวเลขหลายชุดปนกัน (เช่น รหัสยืนยัน 4 หลัก กับเลขอ้างอิง/รหัสอุปกรณ์ที่บังเอิญยาว 6 หลักอยู่ใกล้ๆ)
    //    แล้วตรรกะทั่วไปด้านล่างดึงเลขผิดชุดมาแสดง — ถ้าเปิดไว้ทั้งสองความยาว ลอง 6 หลักก่อน (สอดคล้องกับลำดับความสำคัญ
    //    เดิมของขั้นตอนที่ 2 ด้านล่าง) แล้วค่อยลอง 4 หลัก — ถ้าหาความยาวที่เปิดไว้ไม่เจอเลย ค่อย fallback ไปตรรกะเดิมต่อ
    foreach ([6, 4] as $len) {
        if (!in_array($len, $allowed_digit_lengths, true)) continue;
        if (preg_match('/(?:รหัสยืนยัน|รหัสผ่านชั่วคราว|OTP|code|รหัส|โค้ด)(?:\s*(?:ของคุณ|ของท่าน))?\s*(?:คือ|:|\s)\s*(\d{' . $len . '})\b/ui', $plain_text, $matches)) {
            return $matches[1];
        }
        if (preg_match('/\b\d{' . $len . '}\b/', $plain_text, $matches)) {
            return $matches[0];
        }
    }

    // 1. ค้นหารูปแบบข้อความที่มีคีย์เวิร์ดนำหน้าภาษาไทย/อังกฤษก่อนเสมอ โดยรับความยาวรหัสกว้าง 4-8 หลัก
    //    (ไม่จำกัดที่ 6 หลักตายตัว เพราะแต่ละแอปความยาวรหัสไม่เท่ากัน เช่น Disney+ ใช้ 4 หลัก ส่วนแอปอื่นอาจ 6-8 หลัก
    //    — \d{4,8} เป็น greedy อยู่แล้วจึงยังจับรหัส 6 หลักได้ครบเหมือนเดิมเมื่อมีจริง — ให้ความสำคัญกับคีย์เวิร์ดก่อนเสมอ
    //    เพื่อกันดึงเลขอื่นที่ไม่ใช่ OTP จริงมาผิด เช่น ปี/รหัสอ้างอิง/เลขติดตามพัสดุที่อยู่ห่างจากคีย์เวิร์ด)
    if (preg_match('/(?:รหัสยืนยัน|รหัสผ่านชั่วคราว|OTP|code|รหัส|โค้ด)(?:\s*(?:ของคุณ|ของท่าน))?\s*(?:คือ|:|\s)\s*(\d{4,8})\b/ui', $plain_text, $matches)) {
        return $matches[1];
    }

    // 2. ถ้าไม่เจอรูปแบบมีคีย์เวิร์ดนำหน้าเลย (เช่น คีย์เวิร์ดกับตัวเลขอยู่ห่างกันเกินไปในประโยค) ค่อย fallback
    //    ไปหารหัส 6 หลักติดกัน (ความยาวที่พบบ่อยที่สุดของ OTP) ที่ไหนก็ได้ในเนื้อหา
    if (preg_match('/\b\d{6}\b/', $plain_text, $matches)) {
        return $matches[0];
    }

    // 2.5 บางอีเมล (เช่น Tidal) ดีไซน์รหัส OTP เป็นกล่องแยกทีละหลัก ทำให้หลังแกะ HTML ออก
    //     ตัวเลขแต่ละหลักมีช่องว่าง/ขึ้นบรรทัดใหม่ (จำนวนไม่แน่นอน) คั่นกลาง เช่น "3 9 1 6 5 0" จับเป็นก้อนเดียวไม่ได้
    //     รวมช่องว่างทุกแบบ (space/tab/newline ติดกันกี่ตัวก็ได้) ให้เหลือช่องว่างเดียวก่อน แล้วค่อยหาก้อน 6 หลักที่มีช่องว่างคั่นได้สูงสุดหลักละ 1 ช่อง
    //     เช็คแพทเทิร์นนี้ก่อน fallback ไปหาเลขอื่น เพื่อกันดึงเลขที่ไม่ใช่ OTP (เช่น ที่อยู่/รหัสไปรษณีย์ท้ายอีเมล) มาผิด
    $normalized_text = preg_replace('/\s+/u', ' ', $plain_text);
    if (preg_match('/\b\d(?:[ ]?\d){5}\b/', $normalized_text, $matches)) {
        $spaced_code = preg_replace('/\D/', '', $matches[0]);
        if (strlen($spaced_code) === 6) {
            return $spaced_code;
        }
    }

    // 3. สุดท้ายจริงๆ ถ้ายังไม่เจออะไรเลย ค่อยตรวจสอบรหัส 4-8 หลักอื่นๆ ที่ไหนก็ได้ในเนื้อหา
    if (preg_match('/\b\d{4,8}\b/', $plain_text, $matches)) {
        return $matches[0];
    }

    return null;
}

// ฟังก์ชันดึงรหัสอ้างอิง (Reference Code) จาก HTML Body
function extract_ref_code($html_body) {
    if (empty($html_body)) return '';

    // ลบเนื้อหาภายในแท็ก style และ script ออกทั้งหมดก่อน
    $clean_html = preg_replace('/<(style|script)\b[^>]*>(.*?)<\/\1>/is', '', $html_body);
    // แทนที่แท็กทุกตัวด้วยช่องว่าง (เหตุผลเดียวกับใน extract_otp_code) กันข้อความจากคนละ element เชื่อมติดกัน
    $plain_text = preg_replace('/<[^>]+>/', ' ', $clean_html);

    if (preg_match('/(?:รหัสอ้างอิง|อ้างอิง|Ref|Reference)\s*(?:คือ|:|\s)\s*([A-Za-z0-9]{4,10})/ui', $plain_text, $matches)) {
        return $matches[1];
    }
    return '';
}

// ฟังก์ชันถอดรหัส Content-Transfer-Encoding ของแต่ละส่วน MIME (3=BASE64, 4=QUOTED-PRINTABLE)
function decode_mime_part_data($data, $encoding) {
    switch ((int)$encoding) {
        case 3:
            return base64_decode($data);
        case 4:
            return quoted_printable_decode($data);
        default:
            return $data;
    }
}

// ฟังก์ชันดึงค่า charset จากพารามิเตอร์ของโครงสร้าง MIME part
function get_mime_part_charset($struct_part) {
    if (!empty($struct_part->parameters)) {
        foreach ($struct_part->parameters as $param) {
            if (strtolower($param->attribute) === 'charset') {
                return $param->value;
            }
        }
    }
    return 'UTF-8';
}

// ฟังก์ชันแกะโครงสร้าง MIME จริงของอีเมล (รองรับ multipart) เพื่อดึงเนื้อหา HTML/Plain ที่ถอดรหัสแล้ว
// (แก้ปัญหาเดิมที่ imap_body() คืนค่า MIME source ดิบๆ ที่ยังไม่ได้ decode มาแสดงตรงๆ)
function imap_extract_body($imap_conn, $msgno) {
    $structure = @imap_fetchstructure($imap_conn, $msgno);
    $result = ['html' => '', 'plain' => ''];
    if (!$structure) return $result;

    $collect = function($part, $part_num) use (&$collect, $imap_conn, $msgno, &$result) {
        if (!empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $idx => $sub_part) {
                $sub_num = ($part_num === '') ? (string)($idx + 1) : $part_num . '.' . ($idx + 1);
                $collect($sub_part, $sub_num);
            }
            return;
        }

        // สนใจเฉพาะส่วนที่เป็น TEXT (type 0 ตามมาตรฐาน IMAP)
        if ($part->type != 0) return;

        $raw = ($part_num === '') ? @imap_body($imap_conn, $msgno) : @imap_fetchbody($imap_conn, $msgno, $part_num);
        if ($raw === false || $raw === null || $raw === '') return;

        $raw = decode_mime_part_data($raw, $part->encoding ?? 0);

        $charset = get_mime_part_charset($part);
        if ($charset && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $raw);
            if ($converted !== false) $raw = $converted;
        }

        $subtype = strtolower($part->subtype ?? '');
        if ($subtype === 'html') {
            $result['html'] .= $raw;
        } elseif ($subtype === 'plain') {
            $result['plain'] .= $raw;
        }
    };

    $collect($structure, '');
    return $result;
}

// ฟังก์ชันดึง OTP จากบัญชี IMAP บัญชีเดียว (ใช้ทั้งช่องทางลัด Direct Account และ Fallback บัญชีกลาง)
// $is_direct_account = true หมายถึงกล่องเมลของลูกค้าเอง (ไม่ใช่บัญชีกลางที่มีหลายคนใช้ปนกัน)
function scan_imap_account($creds, $is_direct_account, $app_name, $lower_email, $allowed_otp_digits = []) {
    $imap_host = $creds['host'] ?? 'imap.gmail.com';
    $imap_port = intval($creds['port'] ?? 993);
    $imap_user = $creds['email'];
    $imap_pass = $creds['password'];

    // จำกัดเวลาเชื่อมต่อ/อ่านข้อมูลต่อบัญชี ไม่ให้บัญชีที่เชื่อมต่อช้าหรือใช้งานไม่ได้ทำให้การค้นหาทั้งหมดค้างนาน
    // (ตั้งให้สั้นกว่า SEARCH_DEADLINE เล็กน้อย เพื่อไม่ให้บัญชีแรกที่เจอปัญหาบัญชีเดียวกินงบเวลาของทั้งคำขอไปเกือบหมด)
    @imap_timeout(IMAP_OPENTIMEOUT, 3);
    @imap_timeout(IMAP_READTIMEOUT, 3);

    $mailbox_str = "{" . $imap_host . ":" . $imap_port . "/imap/ssl/novalidate-cert}INBOX";
    $imap_conn = @imap_open($mailbox_str, $imap_user, $imap_pass, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if (!$imap_conn) {
        return [];
    }

    $found = [];

    // กล่องเมลของลูกค้าเอง (Direct) ไม่ใช่บัญชีกลางที่มีเมลหลายคนปนกัน รหัส OTP ที่เพิ่งขอมาจะอยู่บนสุดของกล่องเสมอ
    // จึงสแกนแค่ 5 ฉบับล่าสุดพอ (เร็วกว่าสแกน 30 ฉบับแบบบัญชีกลางมาก) ส่วนบัญชีกลางยังคงสแกนลึก 30 ฉบับเหมือนเดิมเพื่อความถูกต้อง
    $scan_depth = $is_direct_account ? 5 : 30;

    $num_msg = @imap_num_msg($imap_conn);
    if ($num_msg > 0) {
        $start_msg = max(1, $num_msg - ($scan_depth - 1));

        // ดึง header ของทุกฉบับในช่วงที่สนใจมาในคำสั่งเดียว (เร็วกว่าการวนขอทีละฉบับแบบเดิมมาก
        // เพราะ imap_headerinfo() แบบเดิมต้องยิงคำสั่งแยกไปเซิร์ฟเวอร์ทีละฉบับ)
        $overviews = @imap_fetch_overview($imap_conn, "$start_msg:$num_msg");
        if ($overviews) {
            usort($overviews, function($a, $b) { return ($b->msgno ?? 0) - ($a->msgno ?? 0); }); // ใหม่สุดก่อน

            foreach ($overviews as $ov) {
                // เกินงบเวลารวมของคำขอนี้แล้ว (ดู SEARCH_DEADLINE) หยุดอ่านฉบับที่เหลือในบัญชีนี้ทันทีเพื่อให้ตอบกลับไว
                if (microtime(true) > SEARCH_DEADLINE) break;

                $msgno = isset($ov->msgno) ? (int)$ov->msgno : 0;
                if (!$msgno) continue;

                $from_email = $ov->from ?? '';
                $to_email = $ov->to ?? '';
                $subject = isset($ov->subject) ? @imap_utf8($ov->subject) : '';

                // ตรวจสอบอายุจดหมาย - ถ้าส่งมาเกิน 2 ชั่วโมง ให้ข้ามทันที
                $udate = isset($ov->udate) ? intval($ov->udate) : (isset($ov->date) ? (strtotime($ov->date) ?: time()) : time());
                if ((time() - $udate) > 7200) {
                    continue;
                }

                $body = null;
                $full_text = '';

                if ($is_direct_account) {
                    // กล่องเมลของลูกค้าเอง (ไม่ใช่บัญชีกลาง Forwarding) — ทุกฉบับในนี้เป็นของลูกค้าอยู่แล้ว
                    // เช็คจาก header (from/subject) ก่อนว่าตรงกับแอปที่เลือกไหม เพื่อข้ามการดึง body
                    // ของฉบับที่ไม่เกี่ยวข้องไปเลย (เร็วขึ้นมากเมื่อเทียบกับการดึง body ทุกฉบับแบบเดิม)
                    if (!matches_app($from_email, $subject, $app_name, '')) {
                        continue;
                    }

                    $mail_content = imap_extract_body($imap_conn, $msgno);
                    $body = $mail_content['html'] !== '' ? $mail_content['html'] : $mail_content['plain'];
                    $full_text = strtolower($subject . ' ' . $from_email . ' ' . $to_email . ' ' . $body);
                } else {
                    // บัญชีกลาง (Gmail Forwarding Central) — อีเมลเป้าหมายของลูกค้าอาจฝังอยู่ในเนื้อหาที่ถูกส่งต่อมา
                    // ไม่ใช่ใน header To: ตรงๆ จึงต้องดึง body มาค้นหาเสมอ (พฤติกรรมเดิม คงไว้เพื่อความถูกต้อง — เคยลองใส่
                    // header-prefilter แบบเดียวกับกล่องเมลลูกค้าเองเพื่อลดเวลาสแกน แต่ทำให้พลาดอีเมลจริงที่ subject/from
                    // ไม่ได้เขียนชื่อแอปเป็นภาษาอังกฤษตรงๆ เช่น เทมเพลต True บางแบบที่ subject เป็นภาษาไทยล้วน จึงเอาออก)
                    $mail_content = imap_extract_body($imap_conn, $msgno);
                    $body = $mail_content['html'] !== '' ? $mail_content['html'] : $mail_content['plain'];
                    $full_text = strtolower($subject . ' ' . $from_email . ' ' . $to_email . ' ' . $body);

                    if (strpos($full_text, $lower_email) === false) {
                        continue;
                    }
                    if (!matches_app($from_email, $subject, $app_name, $full_text)) {
                        continue;
                    }
                }

                // แปลงเป็นเวลาไทย (Asia/Bangkok) อย่างชัดเจน แทนการพึ่ง timezone เริ่มต้นของเซิร์ฟเวอร์ (มักตั้งเป็น UTC ทำให้เวลาที่แสดงคลาดเคลื่อน 7 ชั่วโมง)
                $dt_thai = new DateTime('@' . $udate);
                $dt_thai->setTimezone(new DateTimeZone('Asia/Bangkok'));
                $thai_time = $dt_thai->format('d/m/') . ((int)$dt_thai->format('Y') + 543) . ' ' . $dt_thai->format('H:i') . ' น.';

                $otp_code = extract_otp_code($body, $allowed_otp_digits) ?? '';
                $ref_code  = extract_ref_code($body) ?? '';

                $found[] = [
                    'subject'   => $subject ?: 'ไม่มีหัวข้อ',
                    'from'      => $from_email,
                    'time'      => $thai_time,
                    'timestamp' => $udate,
                    'otp'       => $otp_code,
                    'ref'       => $ref_code,
                    'html_body' => $body
                ];

                // หากพบรหัส OTP แล้ว ให้หยุดอ่านฉบับถัดไปทันที
                if (!empty($otp_code)) {
                    break;
                }
            }
        }
    }
    @imap_close($imap_conn);
    return $found;
}

// ฟังก์ชันรวม: ไล่ดึง OTP จากบัญชี Gmail ที่เกี่ยวข้องทั้งหมด (บัญชีตรงของลูกค้าเอง ถ้ามี ตามด้วยบัญชีกลางสำรอง)
// ใช้ทั้งช่องทางลัดสำหรับอีเมล @gmail.com และช่องทาง Fallback บัญชีกลางสำหรับ Hotmail/อื่นๆ
function run_gmail_imap_fallback($config_data, $direct_imap_account, $app_name, $lower_email, $allowed_otp_digits = []) {
    $imap_accounts_to_check = [];

    if ($direct_imap_account) {
        $direct_imap_account['__is_direct'] = true; // กล่องเมลของลูกค้าเอง (ไม่ใช่บัญชีกลาง) ใช้เส้นทางไวได้
        $imap_accounts_to_check[] = $direct_imap_account;
    }

    // เพิ่ม Gmail Central Accounts ต่อท้ายเสมอ เพื่อเป็น Fallback สำรองหาก Direct Match ล้มเหลวหรือไม่มี OTP
    $gmail_central = [];
    if (isset($config_data['imap_emails']) && is_array($config_data['imap_emails'])) {
        foreach ($config_data['imap_emails'] as $imap_item) {
            if (strpos(strtolower($imap_item['host'] ?? ''), 'gmail.com') !== false && !empty($imap_item['password'])) {
                if (empty($imap_accounts_to_check) || strtolower($imap_accounts_to_check[0]['email'] ?? '') !== strtolower($imap_item['email'] ?? '')) {
                    $gmail_central[] = $imap_item;
                }
            }
        }
    }
    if (empty($gmail_central)) {
        $gmail_central = [];
    }

    // สลับบัญชีกลางที่เริ่มไล่ก่อนในแต่ละคำขอ (หมุนตามช่วงเวลาทุก 5 วินาที ให้พอดีจังหวะ auto-poll ของหน้าเว็บ)
    // เพราะแต่ละคำขอมีงบเวลาจำกัด (SEARCH_DEADLINE) อาจไล่ไม่ครบทุกบัญชี — ถ้าเริ่มบัญชีแรกซ้ำทุกรอบ บัญชีท้ายๆ
    // จะไม่มีทางถูกเช็คเลยถ้ามีจำนวนบัญชีเยอะ การหมุนจุดเริ่มแบบนี้ทำให้ท้ายที่สุดไล่ครบทุกบัญชีภายในไม่กี่รอบ poll
    if (count($gmail_central) > 1) {
        $rotate = intdiv((int) time(), 5) % count($gmail_central);
        $gmail_central = array_merge(array_slice($gmail_central, $rotate), array_slice($gmail_central, 0, $rotate));
    }

    foreach ($gmail_central as $gc) {
        $imap_accounts_to_check[] = $gc;
    }

    if (empty($imap_accounts_to_check)) {
        return [];
    }

    $all_found = [];
    foreach ($imap_accounts_to_check as $creds) {
        // เกินงบเวลารวมของคำขอนี้แล้ว หยุดไล่บัญชีที่เหลือทันทีเพื่อให้ตอบกลับไว รอบ poll ถัดไปจะสลับไปเริ่มบัญชีอื่นต่อ
        if (microtime(true) > SEARCH_DEADLINE) {
            break;
        }
        $is_direct_account = !empty($creds['__is_direct']);
        $account_found = scan_imap_account($creds, $is_direct_account, $app_name, $lower_email, $allowed_otp_digits);
        if (!empty($account_found)) {
            $all_found = array_merge($all_found, $account_found);
        }
        if (!empty($all_found) && !empty($all_found[0]['otp'])) {
            break; // พบรหัส OTP ในบัญชีนี้เรียบร้อยแล้ว ไม่จำเป็นต้องค้นหาบัญชีถัดไป
        }
    }

    usort($all_found, function($a, $b) {
        return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
    });

    return $all_found;
}

// URL สำหรับแลก authorization code / refresh token เป็น access token ของ Microsoft (บัญชีส่วนบุคคลเท่านั้น)
define('MS_TOKEN_URL', 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token');
define('MS_OAUTH_SCOPE', 'offline_access Mail.Read');

// ฟังก์ชันดึง OTP จากบัญชี Hotmail/Outlook ที่เชื่อมต่อผ่าน Microsoft Graph API (OAuth)
// เร็วและแม่นยำกว่าการ Forward เข้า Gmail/Maily กลางมาก เพราะอ่านจากกล่องเมลจริงของบัญชีนั้นโดยตรง
function scan_ms_graph_account(&$config_data, $config_path, $creds, $app_name, $allowed_otp_digits = []) {
    $refresh_token = $creds['refresh_token'] ?? '';
    $oauth = $config_data['microsoft_oauth'] ?? [];
    $client_id = $oauth['client_id'] ?? '';
    $client_secret = $oauth['client_secret'] ?? '';
    if (empty($refresh_token) || empty($client_id) || empty($client_secret)) {
        return [];
    }

    // ขอ Access Token ใหม่ด้วย Refresh Token (Microsoft จะออก Refresh Token ใหม่ให้ทุกครั้งที่ใช้งาน ต้องบันทึกทับของเก่าเสมอ)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, MS_TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
        'grant_type'    => 'refresh_token',
        'scope'         => MS_OAUTH_SCOPE
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $token_res = curl_exec($ch);
    $token_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($token_http_code !== 200 || !$token_res) {
        error_log('[OTP] Microsoft token failed for ' . ($creds['email'] ?? '') . ' HTTP ' . $token_http_code . ': ' . substr((string)$token_res, 0, 300));
        return [];
    }

    $token_data = json_decode($token_res, true);
    $access_token = $token_data['access_token'] ?? '';
    if (empty($access_token)) {
        return [];
    }

    // บันทึก Refresh Token ใหม่กลับเข้าฐานข้อมูลทันที (ถ้า Microsoft ออกตัวใหม่มาให้)
    if (!empty($token_data['refresh_token']) && $token_data['refresh_token'] !== $refresh_token) {
        if (isset($config_data['imap_emails']) && is_array($config_data['imap_emails'])) {
            foreach ($config_data['imap_emails'] as $idx => $item) {
                if (strtolower($item['email'] ?? '') === strtolower($creds['email'] ?? '') && ($item['provider'] ?? '') === 'microsoft_graph') {
                    $config_data['imap_emails'][$idx]['refresh_token'] = $token_data['refresh_token'];
                    @save_config($config_data);
                    break;
                }
            }
        }
    }

    // ดึงอีเมล 5 ฉบับล่าสุดจากกล่องขาเข้าโดยตรงผ่าน Graph API
    $ch2 = curl_init();
    $query = http_build_query([
        '$top'     => 5,
        '$orderby' => 'receivedDateTime desc',
        '$select'  => 'subject,from,receivedDateTime,body'
    ]);
    curl_setopt($ch2, CURLOPT_URL, 'https://graph.microsoft.com/v1.0/me/messages?' . $query);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
    $mail_res = curl_exec($ch2);
    $mail_http_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($mail_http_code !== 200 || !$mail_res) {
        error_log('[OTP] Microsoft Graph mail list failed for ' . ($creds['email'] ?? '') . ' HTTP ' . $mail_http_code . ': ' . substr((string)$mail_res, 0, 300));
        return [];
    }

    $mail_data = json_decode($mail_res, true);
    $messages = $mail_data['value'] ?? [];

    $found = [];
    foreach ($messages as $msg) {
        $from_addr = $msg['from']['emailAddress']['address'] ?? '';
        $from_name = $msg['from']['emailAddress']['name'] ?? '';
        $from_full = $from_name !== '' ? ($from_name . ' <' . $from_addr . '>') : $from_addr;
        $subject = $msg['subject'] ?? '';
        $body_content = $msg['body']['content'] ?? '';
        $received = $msg['receivedDateTime'] ?? ''; // ISO 8601 UTC เช่น 2026-07-28T10:15:00Z

        $ts = $received !== '' ? strtotime($received) : false;
        if ($ts === false) {
            continue;
        }
        // ตรวจสอบอายุจดหมาย - ถ้าส่งมาเกิน 2 ชั่วโมง ให้ข้ามทันที
        if ((time() - $ts) > 7200) {
            continue;
        }

        if (!matches_app($from_full, $subject, $app_name, $body_content)) {
            continue;
        }

        $dt_thai = new DateTime('@' . $ts);
        $dt_thai->setTimezone(new DateTimeZone('Asia/Bangkok'));
        $thai_time = $dt_thai->format('d/m/') . ((int)$dt_thai->format('Y') + 543) . ' ' . $dt_thai->format('H:i') . ' น.';

        $otp_code = extract_otp_code($body_content, $allowed_otp_digits) ?? '';
        $ref_code = extract_ref_code($body_content) ?? '';

        $found[] = [
            'subject'   => $subject ?: 'ไม่มีหัวข้อ',
            'from'      => $from_full,
            'time'      => $thai_time,
            'timestamp' => $ts,
            'otp'       => $otp_code,
            'ref'       => $ref_code,
            'html_body' => $body_content
        ];

        if (!empty($otp_code)) {
            break;
        }
    }

    return $found;
}

// =========================================================================
// 2.1 ตรวจรหัสผ่าน 4 หลักของลูกค้า (แอดมินเป็นคนตั้งให้แต่ละอีเมลในหน้า Admin)
//     - อีเมลที่ยังไม่ได้ลงทะเบียน/ยังไม่มีรหัสผ่าน => ขอรหัส OTP ไม่ได้
//     - กรอกผิดเกิน PIN_MAX_FAILS ครั้ง => ล็อกอีเมลนั้น (เฉพาะ IP นั้น) PIN_LOCK_SECONDS วินาที กันการสุ่มเดารหัส
// =========================================================================
define('PIN_MAX_FAILS', 5);
define('PIN_LOCK_SECONDS', 15 * 60);

function pin_fail_response($message, $extra = []) {
    echo json_encode(array_merge([
        'success'   => false,
        'pin_error' => true,
        'message'   => $message
    ], $extra));
    exit;
}

$customer_pin = preg_replace('/\D/', '', isset($_POST['pin']) ? (string)$_POST['pin'] : '');
$registered_email = null;
if (isset($config_data['imap_emails']) && is_array($config_data['imap_emails'])) {
    foreach ($config_data['imap_emails'] as $item) {
        if (strtolower($item['email'] ?? '') === strtolower($email)) {
            $registered_email = $item;
            break;
        }
    }
}

if (!$registered_email || ($registered_email['access_pin'] ?? '') === '') {
    pin_fail_response('อีเมลนี้ยังไม่ได้รับรหัสผ่านจากแอดมิน จึงยังกดขอรหัสไม่ได้ (กรุณาติดต่อแอดมินร้านค่ะ)');
}

if (isset($registered_email['active']) && $registered_email['active'] === false) {
    echo json_encode([
        'success' => false,
        'message' => 'อีเมลนี้ถูกปิดล็อกการใช้งานชั่วคราวจากผู้ดูแลระบบ (กรุณาติดต่อแอดมิน)'
    ]);
    exit;
}

if (strlen($customer_pin) !== 4) {
    pin_fail_response('กรุณากรอกรหัสผ่าน 4 หลักให้ครบก่อนกดขอรหัสค่ะ');
}

$client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$attempt_key = hash('sha256', strtolower($email) . '|' . $client_ip);
$attempt_row = null;
try {
    $pdo_pin = db_connect();
    $st = $pdo_pin->prepare("SELECT fails, locked_until FROM pin_attempts WHERE attempt_key = ?");
    $st->execute([$attempt_key]);
    $attempt_row = $st->fetch() ?: null;
} catch (Throwable $e) {
    $pdo_pin = null; // ตารางนับครั้งใช้ไม่ได้ ก็ยังตรวจรหัสต่อได้ตามปกติ
}

if ($attempt_row && (int)$attempt_row['locked_until'] > time()) {
    $wait_min = (int)ceil(((int)$attempt_row['locked_until'] - time()) / 60);
    pin_fail_response("กรอกรหัสผ่านผิดหลายครั้งเกินไป กรุณารอประมาณ $wait_min นาทีแล้วลองใหม่ค่ะ", ['locked' => true]);
}

if (!hash_equals((string)$registered_email['access_pin'], $customer_pin)) {
    $fails = ($attempt_row ? (int)$attempt_row['fails'] : 0) + 1;
    $locked_until = 0;
    if ($fails >= PIN_MAX_FAILS) {
        $locked_until = time() + PIN_LOCK_SECONDS;
        $fails = 0;
    }
    if ($pdo_pin) {
        try {
            $pdo_pin->prepare("INSERT INTO pin_attempts (attempt_key, fails, locked_until) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE fails = VALUES(fails), locked_until = VALUES(locked_until)")
                    ->execute([$attempt_key, $fails, $locked_until]);
        } catch (Throwable $e) {}
    }
    if ($locked_until) {
        pin_fail_response('กรอกรหัสผ่านผิดครบ ' . PIN_MAX_FAILS . ' ครั้ง ระบบล็อกไว้ 15 นาทีค่ะ', ['locked' => true]);
    }
    pin_fail_response('รหัสผ่านไม่ถูกต้อง (ลองได้อีก ' . (PIN_MAX_FAILS - $fails) . ' ครั้ง)');
}

if ($attempt_row && $pdo_pin) {
    try { $pdo_pin->prepare("DELETE FROM pin_attempts WHERE attempt_key = ?")->execute([$attempt_key]); } catch (Throwable $e) {}
}

// =========================================================================
// 2.2 Netflix: ตั้งค่ารายอีเมลจากหน้า Admin
//   - ปุ่ม "4"  (netflix_otp_4digit)     = ให้ลองหารหัส 4 หลักก่อน (กันดึงเลขผิดชุด)
//   - ปุ่ม "6"  (netflix_6digit_allowed) = เปิด/ปิด ให้ลูกค้าเห็นรหัส Netflix 6 หลัก (ปิด = ซ่อนรหัสและเนื้อหาอีเมลนั้น)
// =========================================================================
$is_netflix_request = (stripos($app_name, 'netflix') !== false);
$netflix_6digit_allowed = !isset($registered_email['netflix_6digit_allowed']) || !empty($registered_email['netflix_6digit_allowed']);

$allowed_otp_digits = ($is_netflix_request && !empty($registered_email['netflix_otp_4digit'])) ? [4] : [];

// มีรหัส 6 หลักแบบมีคำนำหน้า (code/รหัส/OTP ...) อยู่ในเนื้อหาอีเมลไหม — ใช้ตอนปิดรหัส Netflix 6 หลัก
function body_has_6digit_code($html_body) {
    if (empty($html_body)) return false;
    $t = preg_replace('/<(style|script)\b[^>]*>(.*?)<\/\1>/is', '', $html_body);
    $t = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t);
    return (bool)preg_match('/(?:รหัสยืนยัน|รหัสผ่านชั่วคราว|OTP|code|รหัส|โค้ด)[^0-9]{0,40}\b\d(?: ?\d){5}\b/ui', $t);
}

// กรองผลลัพธ์ก่อนส่งให้หน้าเว็บ: ถ้าปิดรหัส Netflix 6 หลักไว้ ห้ามส่งรหัส/เนื้อหาอีเมลที่มีรหัส 6 หลักออกไปเด็ดขาด
function apply_netflix_6digit_policy($emails) {
    global $is_netflix_request, $netflix_6digit_allowed;
    if (!$is_netflix_request || $netflix_6digit_allowed || !is_array($emails)) {
        return $emails;
    }
    foreach ($emails as $i => $mail) {
        $otp = preg_replace('/\D/', '', (string)($mail['otp'] ?? ''));
        if (strlen($otp) === 6 || body_has_6digit_code($mail['html_body'] ?? '')) {
            $emails[$i]['otp'] = '';
            $emails[$i]['ref'] = '';
            $emails[$i]['blocked'] = true;
            $emails[$i]['blocked_reason'] = 'รหัส Netflix 6 หลักถูกปิดไว้โดยแอดมิน';
            $emails[$i]['html_body'] = '<div style="font-family:sans-serif;padding:40px 20px;text-align:center;color:#be185d">'
                . '<h3>รหัส Netflix 6 หลักถูกปิดไว้</h3><p>หากต้องการใช้รหัสนี้ กรุณาติดต่อแอดมินร้านค่ะ</p></div>';
        }
    }
    return $emails;
}

// โหมดทดลองในเครื่องเท่านั้น (OTP_LOCAL=1): อีเมล @demo.local ตอบรหัสปลอมให้เห็นหน้าตาผลลัพธ์ — บนเว็บจริงไม่มีผล
if (getenv('OTP_LOCAL') && substr(strtolower($email), -11) === '@demo.local') {
    $demo_code = $is_netflix_request ? '482913' : '7351';
    $demo_html = '<div style="font-family:sans-serif;padding:24px"><h2>' . htmlspecialchars($app_name) . '</h2><p>Your verification code is</p><h1>' . $demo_code . '</h1><p>(อีเมลทดลอง)</p></div>';
    $dt_demo = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    echo json_encode([
        'success' => true, 'app_name' => $app_name, 'email' => $email,
        'emails'  => apply_netflix_6digit_policy([[
            'subject' => "รหัสยืนยัน $app_name (ทดลอง)", 'from' => "$app_name <no-reply@demo.local>",
            'time' => $dt_demo->format('j') . ' ตุลาคม เวลา ' . $dt_demo->format('H:i') . ' น.',
            'timestamp' => time(), 'otp' => $demo_code, 'ref' => '', 'html_body' => $demo_html
        ]])
    ]);
    exit;
}

// =========================================================================
// 3. จัดการการดึงข้อมูลตามประเภทของอีเมล (Maily Space API & Central Accounts)
// =========================================================================
$lower_email = strtolower($email);

// -------------------------------------------------------------------------
// ช่องทางลัดที่เร็ว/แม่นยำที่สุด: ถ้าอีเมลนี้เชื่อมต่อผ่าน Microsoft Graph API (OAuth) ไว้แล้ว ให้อ่านกล่องเมลจริงโดยตรงทันที
// ไม่ต้องพึ่งการ Forward เข้า Gmail/Maily กลางเลย เพราะอ่านจากกล่องเมลของบัญชีนั้นเองตรงๆ ผ่าน API ทางการของ Microsoft
// -------------------------------------------------------------------------
$direct_ms_account = null;
if (isset($config_data['imap_emails']) && is_array($config_data['imap_emails'])) {
    foreach ($config_data['imap_emails'] as $imap_item) {
        if (strtolower($imap_item['email'] ?? '') === $lower_email
            && ($imap_item['provider'] ?? '') === 'microsoft_graph'
            && !empty($imap_item['refresh_token'])) {
            $direct_ms_account = $imap_item;
            break;
        }
    }
}

if ($direct_ms_account) {
    $ms_found = scan_ms_graph_account($config_data, $config_path, $direct_ms_account, $app_name, $allowed_otp_digits);
    if (!empty($ms_found)) {
        usort($ms_found, function($a, $b) { return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0); });
        echo json_encode([
            'success'  => true,
            'app_name' => $app_name,
            'email'    => $email,
            'emails'   => apply_netflix_6digit_policy($ms_found)
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => "ไม่พบอีเมลยืนยันตัวตนล่าสุดของ $app_name ส่งมายัง $email (กรุณากดส่งรหัส OTP ใหม่อีกครั้ง)"
    ]);
    exit;
}

// -------------------------------------------------------------------------
// ช่องทางลัด: ถ้าอีเมลนี้ถูกลงทะเบียนเป็นบัญชี IMAP ตรงในระบบอยู่แล้ว (ไม่ใช่ผ่าน Maily) ให้ดึงจาก IMAP บัญชีนั้นทันที
// ข้าม Maily Space และ Cloud Run ไปเลย เพราะรู้แน่ชัดอยู่แล้วว่าต้องอ่านจากบัญชีไหน ไม่ต้องเสียเวลารอทั้งสองช่องทางนั้นก่อน
// (แต่ก่อนแม้จะเป็นอีเมล Gmail ที่ลงทะเบียนไว้ตรงๆ ก็ยังต้องรอ Maily Space + Cloud Run ก่อนเสมอ ทำให้ช้าโดยไม่จำเป็น)
// -------------------------------------------------------------------------
$direct_imap_account = null;
if (isset($config_data['imap_emails']) && is_array($config_data['imap_emails'])) {
    foreach ($config_data['imap_emails'] as $imap_item) {
        if (strtolower($imap_item['email'] ?? '') === $lower_email
            && !empty($imap_item['password'])
            && ($imap_item['provider'] ?? '') !== 'maily') {
            $direct_imap_account = $imap_item;
            break;
        }
    }
}

if ($direct_imap_account) {
    $direct_found = scan_imap_account($direct_imap_account, true, $app_name, $lower_email, $allowed_otp_digits);
    if (!empty($direct_found)) {
        usort($direct_found, function($a, $b) { return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0); });
        echo json_encode([
            'success'  => true,
            'app_name' => $app_name,
            'email'    => $email,
            'emails'   => apply_netflix_6digit_policy($direct_found)
        ]);
        exit;
    }
    // ถ้ายังไม่เจอในช่องทางลัด ปล่อยผ่านไปลองบัญชีกลาง Gmail อื่นๆ ต่อด้านล่าง (ไม่ต้องผ่าน Maily Space/Cloud Run เพราะเป็นอีเมล Gmail)
}

// -------------------------------------------------------------------------
// อีเมล @gmail.com จะไม่มีทางเจอข้อมูลใน Maily Space หรือ Cloud Run เลย เพราะสองช่องทางนั้น
// ให้บริการเฉพาะโดเมน Maily Space (@lico.moe/@rdcw.plus/@gooddaymail.com) และ Hotmail/RDCW เท่านั้น
// จึงข้ามสองช่องทางนั้นไปอ่าน Gmail (บัญชีตรง + บัญชีกลางสำรอง) โดยตรงทันที ไม่ต้องเสียเวลารอก่อนเลย
// -------------------------------------------------------------------------
$is_gmail_domain = (substr($lower_email, -10) === '@gmail.com');

if ($is_gmail_domain) {
    $gmail_results = run_gmail_imap_fallback($config_data, $direct_imap_account, $app_name, $lower_email, $allowed_otp_digits);

    if (!empty($gmail_results)) {
        echo json_encode([
            'success'  => true,
            'app_name' => $app_name,
            'email'    => $email,
            'emails'   => apply_netflix_6digit_policy($gmail_results)
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'message' => "ไม่พบอีเมลยืนยันตัวตนล่าสุดของ $app_name ส่งมายัง $email (กรุณากดส่งรหัส OTP ใหม่อีกครั้ง หรือตรวจสอบการตั้งค่า Forwarding)"
    ]);
    exit;
}

$maily_domains = ["@lico.moe", "@rdcw.plus", "@gooddaymail.com"];
$is_maily_domain = false;

foreach ($maily_domains as $d) {
    if (strpos($lower_email, $d) !== false) {
        $is_maily_domain = true;
        break;
    }
}
// อีเมลที่แอดมินเพิ่มไว้เป็นประเภท Maily.space (โดเมนอื่นนอกรายการด้านบน) ก็นับเป็นอีเมล Maily เช่นกัน
if (($registered_email['provider'] ?? '') === 'maily') {
    $is_maily_domain = true;
}

// สร้างรายการบัญชี Maily Space ที่ต้องสืบค้น
$maily_accounts_to_check = [];

if ($is_maily_domain) {
    $maily_accounts_to_check[] = $email;
}

// ดึงรายการ Maily Central Accounts เพิ่มเติมเสมอเพื่อรองรับอีเมลที่ส่งต่อ (Forwarding) มายัง Maily Space
if (isset($config_data['maily_central_accounts']) && is_array($config_data['maily_central_accounts'])) {
    foreach ($config_data['maily_central_accounts'] as $m_acc) {
        if (!in_array(strtolower($m_acc), array_map('strtolower', $maily_accounts_to_check))) {
            $maily_accounts_to_check[] = $m_acc;
        }
    }
}
if (isset($config_data['imap_emails']) && is_array($config_data['imap_emails'])) {
    foreach ($config_data['imap_emails'] as $item) {
        if (($item['provider'] ?? '') === 'maily' && !empty($item['email'])) {
            if (!in_array(strtolower($item['email']), array_map('strtolower', $maily_accounts_to_check))) {
                $maily_accounts_to_check[] = $item['email'];
            }
        }
    }
}
if (empty($maily_accounts_to_check)) {
    $maily_accounts_to_check[] = "codehotmail99@gooddaymail.com";
}

// ดำเนินการสืบค้นข้อมูลผ่าน Maily Space API
$maily_found_mails = [];

foreach ($maily_accounts_to_check as $target_maily_email) {
    $parts = explode('@', $target_maily_email, 2);
    if (count($parts) < 2) continue;
    
    $account_name = strtolower(trim($parts[0]));
    $domain_id = str_replace('.', '', strtolower(trim($parts[1])));
    
    $query_params = http_build_query([
        "size" => 15,
        "page" => 1,
        "accountName" => $account_name,
        "domainId" => $domain_id
    ]);
    
    $response = false;
    $http_code = 0;
    $working_token = "";
    
    foreach ($maily_tokens as $token) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$maily_api_url?$query_params");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]);
        
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200 && $res) {
            $test_data = json_decode($res, true);
            if (isset($test_data['data']['mails'])) {
                $response = $res;
                $http_code = $code;
                $working_token = $token;
                break;
            }
        }
    }

    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        $mails = isset($data['data']['mails']) ? $data['data']['mails'] : [];
        
        foreach ($mails as $mail) {
            $html_body = $mail['html'] ?? '';
            
            // หากเนื้อหา html ว่างเปล่า ให้ดึงรายละเอียดจดหมาย (Detail API) ล่วงหน้า
            if (empty($html_body) && !empty($mail['id'])) {
                $mail_id = $mail['id'];
                $detail_params = http_build_query([
                    "accountName" => $account_name,
                    "domainId" => $domain_id
                ]);
                $detail_url = "https://api.maily.space/mail/public/mails/$mail_id?$detail_params";
                
                $ch_detail = curl_init();
                curl_setopt($ch_detail, CURLOPT_URL, $detail_url);
                curl_setopt($ch_detail, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_detail, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch_detail, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch_detail, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch_detail, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer " . ($working_token ?: $maily_tokens[0]),
                    "Content-Type: application/json"
                ]);
                $detail_response = curl_exec($ch_detail);
                curl_close($ch_detail);
                
                if ($detail_response) {
                    $detail_data = json_decode($detail_response, true);
                    if (isset($detail_data['data']['html'])) {
                        $html_body = $detail_data['data']['html'];
                    }
                }
            }
            
            $full_text = strtolower(($mail['from'] ?? '') . ' ' . ($mail['subject'] ?? '') . ' ' . $html_body);
            
            $user_part = explode('@', $lower_email)[0] ?? '';
            $is_target = (strtolower($target_maily_email) === $lower_email);
            if (!$is_target && !empty($lower_email)) {
                if (strpos($full_text, $lower_email) !== false) {
                    $is_target = true;
                } elseif (!empty($user_part) && strlen($user_part) >= 3 && strpos($full_text, $user_part) !== false) {
                    $is_target = true;
                } elseif (in_array(strtolower($target_maily_email), array_map('strtolower', $maily_accounts_to_check))) {
                    $is_target = true; // อนุโลมสำหรับ Maily Central Forwarding ทุกบัญชีที่ตั้งค่าในระบบ
                }
            }
            if (!$is_target) continue;

            if (matches_app($mail['from'] ?? '', $mail['subject'] ?? '', $app_name, $full_text)) {
                $otp_code = extract_otp_code($html_body, $allowed_otp_digits) ?? '';
                $ref_code = extract_ref_code($html_body) ?? '';
                $time_formatted = parse_utc_timestamp_to_thai($mail['createdAt'] ?? '');
                
                $maily_found_mails[] = [
                    'subject' => $mail['subject'] ?? 'ไม่มีหัวข้อ',
                    'from' => $mail['from'] ?? '',
                    'time' => $time_formatted,
                    'otp' => $otp_code,
                    'ref' => $ref_code,
                    'html_body' => $html_body
                ];
                
                break; // พบอีเมลฉบับล่าสุดเรียบร้อยแล้ว
            }
        }
    }

    if (!empty($maily_found_mails) && !empty($maily_found_mails[0]['otp'])) {
        break; // พบรหัส OTP จาก Maily Space แล้ว ไม่ต้องสืบค้นบัญชีถัดไป
    }
}

// หากพบข้อมูลผ่าน Maily Space ให้ส่งผลลัพธ์กลับทันที
if (!empty($maily_found_mails)) {
    echo json_encode([
        'success' => true,
        'app_name' => $app_name,
        'email' => $email,
        'emails' => apply_netflix_6digit_policy($maily_found_mails)
    ]);
    exit;
}

// หากผู้ใช้ค้นหาด้วยอีเมลที่เป็นโดเมน Maily Space โดยตรงแล้วไม่พบข้อมูล ให้คืนค่าแจ้งเตือน
if ($is_maily_domain) {
    echo json_encode([
        'success' => false,
        'message' => "ไม่พบอีเมลยืนยันตัวตนสำหรับ $app_name ส่งมายัง $email (กรุณากดส่งรหัส OTP ใหม่อีกครั้ง)"
    ]);
    exit;
}

// -------------------------------------------------------------------------
// ช่องทาง B1: Cloud Run API ของลูกค้า (RDCW) — ลองก่อนเสมอเพราะเร็วกว่าการไล่สแกน IMAP มาก
// -------------------------------------------------------------------------
$cloud_run_found = [];
$app_code = get_app_code($app_name);
$cr_query = http_build_query([
    "senderEmail" => $email,
    "appCode" => $app_code
]);

$ch_cr = curl_init();
curl_setopt($ch_cr, CURLOPT_URL, "$cloud_run_url?$cr_query");
curl_setopt($ch_cr, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_cr, CURLOPT_TIMEOUT, 10);
curl_setopt($ch_cr, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch_cr, CURLOPT_SSL_VERIFYHOST, false);
$cr_response = @curl_exec($ch_cr);
$cr_http_code = curl_getinfo($ch_cr, CURLINFO_HTTP_CODE);
curl_close($ch_cr);

if ($cr_http_code === 200 && $cr_response) {
    $cr_data = json_decode($cr_response, true);
    $cr_emails = (isset($cr_data['emails']) && is_array($cr_data['emails'])) ? $cr_data['emails'] : [];

    foreach ($cr_emails as $mail) {
        // เก็บ HTML ต้นฉบับไว้ใช้ค้นหา OTP เสมอ เพราะบางเทมเพลต (เช่น ChatGPT/OpenAI)
        // วางรหัสไว้ก่อนแท็ก <table> แรก การตัดข้อความก่อนหน้านั้นทิ้งอาจทำให้รหัสที่แท้จริงหายไป
        // และไปหยิบตัวเลขอื่นที่ไม่เกี่ยวข้อง (เช่น ปีลิขสิทธิ์ในท้ายอีเมล) มาแสดงผิดแทน
        $cr_original_html = $mail['html'] ?? '';
        $cr_html_body = $cr_original_html;
        if ($cr_html_body) {
            $table_idx = strpos($cr_html_body, '<table');
            if ($table_idx !== false) {
                $cr_html_body = substr($cr_html_body, $table_idx);
            }
        }

        $otp_code = extract_otp_code($cr_original_html, $allowed_otp_digits) ?? '';
        $ref_code = extract_ref_code($cr_original_html) ?? '';
        $formatted_time = parse_cloud_run_date_to_thai($mail['date'] ?? '');

        $cloud_run_found[] = [
            'subject'   => $mail['subject'] ?? 'ไม่มีหัวข้อ',
            'from'      => $mail['sender'] ?? ($app_name . ' Security'),
            'time'      => $formatted_time,
            'otp'       => $otp_code,
            'ref'       => $ref_code,
            'html_body' => $cr_html_body
        ];
    }
}

if (!empty($cloud_run_found)) {
    echo json_encode([
        'success'  => true,
        'app_name' => $app_name,
        'email'    => $email,
        'emails'   => apply_netflix_6digit_policy($cloud_run_found)
    ]);
    exit;
}

// -------------------------------------------------------------------------
// ช่องทาง B2: Centralized Catch-All (ดึงจาก Gmail หลักทั้งหมด) — Fallback หาก Cloud Run ไม่พบ
// (ถึงจุดนี้ได้เฉพาะอีเมลที่ไม่ใช่ @gmail.com เช่น Hotmail ที่ไม่มี Direct Match หรือ Maily/Cloud Run หาไม่เจอ)
// -------------------------------------------------------------------------
$matching_mails = run_gmail_imap_fallback($config_data, $direct_imap_account, $app_name, $lower_email, $allowed_otp_digits);

if (empty($matching_mails)) {
    echo json_encode([
        'success' => false,
        'message' => "ไม่พบอีเมลยืนยันตัวตนล่าสุดของ $app_name ส่งมายัง $email (กรุณากดส่งรหัส OTP ใหม่อีกครั้ง หรือตรวจสอบการตั้งค่า Forwarding)"
    ]);
    exit;
}

echo json_encode([
    'success'  => true,
    'app_name' => $app_name,
    'email'    => $email,
    'emails'   => apply_netflix_6digit_policy($matching_mails)
]);
exit;
