<?php
require_once __DIR__ . '/config_store.php';

try {
    $config = load_config();
} catch (Throwable $e) {
    http_response_code(500);
    die('เชื่อมต่อฐานข้อมูลไม่สำเร็จ กรุณาตรวจสอบไฟล์ db_credentials.php บนโฮสต์: ' . htmlspecialchars($e->getMessage()));
}

// โลโก้ร้าน Monali (ถ้าไม่มีไฟล์ ใช้โลโก้ที่ตั้งไว้ในหน้า Admin แทน)
$shop_logo = file_exists(__DIR__ . '/static/monali_logo.jpg') ? '/static/monali_logo.jpg' : ($config['logo_path'] ?? '/static/logo.jpg');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#fbcfe8">
    <title><?php echo htmlspecialchars($config['shop_name']); ?> - ค้นหารหัส OTP ตัวล่าสุด</title>
    <link rel="icon" href="<?php echo htmlspecialchars($shop_logo); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mali:wght@400;500;600;700&family=Mitr:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Mitr', 'sans-serif'],
                        cute: ['Mali', 'Mitr', 'sans-serif'],
                    },
                    colors: {
                        candy: { 50:'#fff5fa', 100:'#ffe4f1', 200:'#fecde4', 300:'#fba8cf', 400:'#f687b8', 500:'#ec6aa8', 600:'#d94f92', 700:'#b93a79' },
                        lilac: { 100:'#efe7ff', 200:'#ddd0ff', 300:'#c4b5fd', 500:'#9b87f5' },
                        mint:  { 100:'#dcfaf5', 200:'#b8f0e8', 400:'#5fd3c4' },
                    },
                    screens: { 'xs': '375px' }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --pink: #ec6aa8;
            --pink-soft: #fecde4;
            --lilac: #c4b5fd;
            --mint: #b8f0e8;
            --ink: #6b3a5a;
        }
        html, body { min-height: 100%; }
        body {
            font-family: 'Mitr', sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, #ffeef7 0%, #f7efff 50%, #eaf5ff 100%);
            background-attachment: fixed;
            overflow-x: hidden;
        }
        /* ฉากหลังแบบเดียวกับหน้าแอดมิน (รูปชัด ไม่เบลอ ความจาง 35%) */
        .scene {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: url('/static/monali_bg.jpg') center / cover no-repeat;
            opacity: .35;
        }

        /* โลโก้ร้าน */
        .logo-wrap { position: relative; width: 8rem; height: 8rem; }
        @media (min-width: 768px) { .logo-wrap { width: 9.5rem; height: 9.5rem; } }
        .logo-wrap img {
            width: 100%; height: 100%; object-fit: cover; border-radius: 2.2rem;
            border: 5px solid #fff;
            box-shadow: 0 12px 28px -12px rgba(236,106,168,.45);
        }


        .glass {
            background: rgba(255,255,255,.96);
            border: 1px solid #fde2ef;
            box-shadow: 0 16px 40px -20px rgba(217,79,146,.30);
        }
        .title-cute {
            font-family: 'Mali', 'Mitr', sans-serif; font-weight: 700;
            background: linear-gradient(90deg, #ec6aa8, #9b87f5 55%, #5fd3c4);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .app-btn { transition: transform .25s cubic-bezier(.3,1.5,.5,1), box-shadow .25s; }
        .app-btn:hover  { transform: translateY(-2px); box-shadow: 0 10px 20px -14px rgba(217,79,146,.45); }
        .app-btn:active { transform: scale(.96); }

        .btn-candy {
            background: linear-gradient(90deg, #f687b8, #c4b5fd);
            box-shadow: 0 10px 22px -10px rgba(236,106,168,.75);
            transition: transform .2s, box-shadow .2s, filter .2s;
        }
        .btn-candy:hover:not(:disabled) { filter: brightness(1.05); transform: translateY(-1px); }
        .btn-candy:active:not(:disabled) { transform: scale(.98); }
        .btn-candy:disabled { filter: grayscale(.4); opacity: .55; cursor: not-allowed; box-shadow: none; }

        .field {
            border: 2px solid #fde2ef; background: #fffafd;
            transition: border-color .2s, box-shadow .2s;
        }
        .field:focus { outline: none; border-color: #f687b8; box-shadow: 0 0 0 4px rgba(246,135,184,.18); }

        .pin-box {
            width: 3.4rem; height: 3.8rem; font-size: 1.6rem; font-weight: 700; text-align: center;
            border-radius: 1.1rem; color: #d94f92;
            caret-color: #ec6aa8;
        }
        .pin-box.filled { background: #ffe4f1; border-color: #fba8cf; animation: pop .25s ease-out; }
        .pin-shake { animation: shake .45s ease-in-out; }
        @keyframes pop   { 0%{transform:scale(.85)} 100%{transform:scale(1)} }
        @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-8px)} 40%,80%{transform:translateX(8px)} }

        .spinner { width: 2.2rem; height: 2.2rem; border-radius: 999px; border: 4px solid #ffe4f1; border-top-color: #ec6aa8; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #fbcfe8; border-radius: 10px; }
        ::selection { background: #fba8cf; color: #fff; }

        /* ปิดการขยับทั้งหมดในหน้า (เหลือแค่วงกลมหมุนตอนกำลังโหลดเท่านั้น) */
        *, *::before, *::after { animation: none !important; transition: none !important; }
        .spinner { animation: spin 1s linear infinite !important; }
        .app-btn:hover, .app-btn:active, .btn-candy:hover, .btn-candy:active,
        .scale-95, .scale-100, [class*="active:scale"]:active, .group:hover [class*="translate-x"] { transform: none !important; }
    </style>
</head>
<body class="min-h-screen">

    <!-- ฉากหลัง + น้อง ๆ ที่ขยับได้ -->
    <div class="scene" aria-hidden="true"></div>

    <main class="relative z-10 min-h-screen flex flex-col items-center justify-center px-4 py-8 md:py-10 pb-10">
    <div class="w-full max-w-lg flex flex-col gap-6">

        <!-- Header & Shop Logo -->
        <div class="text-center space-y-3">
            <div class="flex flex-col items-center justify-center gap-3">
                <div class="logo-wrap" id="logoWrap">
                    <img src="<?php echo htmlspecialchars($shop_logo); ?>" alt="<?php echo htmlspecialchars($config['shop_name']); ?> logo"
                         onerror="this.src='<?php echo htmlspecialchars($config['logo_path'] ?? ''); ?>'">
                </div>
                <div class="space-y-1">
                    <h1 class="title-cute text-2xl md:text-3xl leading-tight"><?php echo htmlspecialchars($config['shop_name']); ?></h1>
                    <p class="inline-flex items-center gap-1.5 text-xs font-medium text-candy-600 bg-white/80 px-3 py-1 rounded-full shadow-sm">
                        <span class="w-1.5 h-1.5 rounded-full bg-mint-400 animate-pulse"></span>
                        กดรับ OTP เองได้ ตลอด 24 ชั่วโมง
                    </p>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- หน้าแรก: เลือกแอปพลิเคชัน -->
        <!-- ========================================== -->
        <div id="appSelectionScreen" class="glass rounded-[2rem] p-5 md:p-7 space-y-5 transition-all duration-500">
            <h2 class="text-center font-cute text-lg md:text-2xl font-bold text-candy-700">เลือกแอปที่ต้องการรับรหัสยืนยัน</h2>

            <div class="flex flex-wrap justify-center gap-3 md:gap-5">
                <?php
                $apps_list = isset($config['apps']) && is_array($config['apps']) ? $config['apps'] : [];
                $apps_list = array_values(array_filter($apps_list, function ($a) { return !isset($a['active']) || $a['active']; }));
                if (empty($apps_list)) {
                    $apps_list = [
                        ['name' => 'Disney+', 'logo_path' => $config['disney_logo_path'] ?? ''],
                        ['name' => 'TrueID', 'logo_path' => $config['trueid_logo_path'] ?? '']
                    ];
                }
                foreach ($apps_list as $i => $app_item):
                    $app_name = htmlspecialchars($app_item['name']);
                    $app_js   = htmlspecialchars(json_encode($app_item['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                    // ใช้รูปปุ่มสำเร็จรูปในโฟลเดอร์ static ให้อัตโนมัติตามชื่อแอป (ไม่ต้องอัปโหลดเองในแอดมิน)
                    // แอปอื่นที่ไม่มีรูปสำเร็จรูป ใช้รูปที่ตั้งไว้ในหน้าแอดมินตามเดิม
                    $lower_name = mb_strtolower($app_item['name']);
                    $tile = '';
                    if (strpos($lower_name, 'netflix') !== false) $tile = 'netflix';
                    elseif (strpos($lower_name, 'disney') !== false) $tile = 'disney';
                    elseif (strpos($lower_name, 'prime') !== false || strpos($lower_name, 'amazon') !== false) $tile = 'prime';
                    elseif (strpos($lower_name, 'chatgpt') !== false || strpos($lower_name, 'gpt') !== false || strpos($lower_name, 'openai') !== false) $tile = 'chatgpt';
                    elseif (preg_match('/true\s*id/u', $lower_name)) $tile = 'trueid';
                    $tile_path = $tile !== '' ? '/static/app_tile_' . $tile . '.png' : '';
                    $logo = ($tile_path !== '' && file_exists(__DIR__ . $tile_path)) ? $tile_path : htmlspecialchars($app_item['logo_path'] ?? '');
                ?>
                <!-- ปุ่มแอปเป็นรูปเต็มช่อง ไม่มีกรอบ ไม่มีชื่อ (รูปตั้งในหน้าแอดมิน > แอปพลิเคชัน) — ถ้ายังไม่มีรูปจะโชว์ชื่อแอปแทน -->
                <button type="button" onclick="selectApp(<?php echo $app_js; ?>)" aria-label="<?php echo $app_name; ?>" title="<?php echo $app_name; ?>"
                        class="app-btn w-[30%] max-w-[150px] aspect-square rounded-[1.6rem] overflow-hidden flex items-center justify-center <?php echo empty($logo) ? 'bg-candy-50' : ''; ?>">
                    <?php if (!empty($logo)): ?>
                    <img src="<?php echo $logo; ?>" alt="<?php echo $app_name; ?>" class="w-full h-full object-contain">
                    <?php else: ?>
                    <span class="text-sm md:text-base font-semibold text-candy-600 px-2 text-center"><?php echo $app_name; ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- หน้าค้นหา: อีเมล + รหัสผ่าน 4 หลัก -->
        <!-- ========================================== -->
        <div id="searchBoxCard" class="glass w-full rounded-[2rem] p-5 md:p-8 transition-all duration-500 hidden transform scale-95 opacity-0">
            <div class="space-y-5">
                <div class="flex items-center justify-between border-b-2 border-dashed border-candy-100 pb-3">
                    <button onclick="goBackToSelection()" class="flex items-center gap-1.5 text-xs text-candy-400 hover:text-candy-600 font-medium transition-all">
                        <i class="fa-solid fa-arrow-left-long"></i><span>ย้อนกลับ</span>
                    </button>
                    <span id="selectedAppTag" class="text-[11px] font-semibold px-3 py-1 rounded-full bg-candy-100 text-candy-700">-</span>
                </div>

                <div class="text-center space-y-1">
                    <h2 class="font-cute text-lg md:text-xl font-bold text-candy-700">ขอรหัสยืนยันทางอีเมล</h2>
                    <p class="text-xs md:text-sm text-[#9a6f8a]">กรอกอีเมล และรหัสผ่าน 4 หลักที่ได้รับจากแอดมินค่ะ</p>
                </div>

                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <label for="email" class="text-xs font-medium text-candy-600 pl-1"><i class="fa-solid fa-envelope mr-1"></i>อีเมล</label>
                        <input type="email" id="email" autocomplete="email"
                               placeholder="example@gmail.com"
                               class="field w-full px-4 py-3.5 rounded-2xl text-center font-medium text-[#6b3a5a] placeholder-candy-300 text-sm"
                               oninput="updateSearchBtn()"
                               onkeydown="if(event.key === 'Enter') { event.preventDefault(); focusPin(0); }">
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-candy-600 pl-1 block text-center"><i class="fa-solid fa-key mr-1"></i>รหัสผ่าน 4 หลัก</label>
                        <div id="pinGroup" class="flex items-center justify-center gap-2.5 md:gap-3">
                            <?php for ($p = 0; $p < 4; $p++): ?>
                            <input type="password" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off"
                                   class="pin-box field" data-idx="<?php echo $p; ?>" aria-label="รหัสหลักที่ <?php echo $p + 1; ?>">
                            <?php endfor; ?>
                        </div>
                        <div class="text-center">
                            <button type="button" onclick="togglePinVisible()" id="pinEyeBtn" class="text-[11px] text-candy-400 hover:text-candy-600 font-medium">
                                <i class="fa-regular fa-eye mr-0.5"></i> แสดงรหัส
                            </button>
                        </div>
                    </div>

                    <button id="searchBtn" onclick="fetchOTP()" disabled
                            class="btn-candy w-full text-white font-semibold py-3.5 px-6 rounded-2xl flex items-center justify-center gap-2.5 text-sm">
                        <i id="btnIcon" class="fa-solid fa-wand-magic-sparkles"></i>
                        <span id="btnText">กดขอรหัสยืนยัน (OTP)</span>
                    </button>
                    <p id="searchHint" class="text-center text-[11px] text-candy-400 -mt-1">กรอกอีเมลและรหัสผ่าน 4 หลักให้ครบก่อนนะคะ</p>
                </div>

                <div id="statusBox" class="hidden flex flex-col items-center justify-center py-3 space-y-2">
                    <div class="spinner"></div>
                    <p class="text-xs md:text-sm text-candy-500 animate-pulse">น้องกำลังไปหยิบรหัสมาให้นะคะ...</p>
                </div>

                <div id="errorBox" class="hidden bg-rose-50 border-2 border-rose-100 text-rose-700 px-4 py-3 rounded-2xl text-center text-xs md:text-sm space-y-1">
                    <div class="flex items-center justify-center gap-2">
                        <i class="fa-solid fa-heart-crack text-rose-400"></i>
                        <span id="errorTitle" class="font-semibold">อุ๊ย! มีบางอย่างผิดพลาด</span>
                    </div>
                    <p id="errorMessage" class="text-[12px] text-rose-500"></p>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- ผลลัพธ์อีเมล / รหัส OTP -->
        <!-- ========================================== -->
        <div id="resultBox" class="hidden glass rounded-[2rem] overflow-hidden transform scale-95 opacity-0 transition-all duration-500 w-full">
            <div class="bg-candy-50/80 border-b-2 border-dashed border-candy-100 px-4 md:px-6 py-3.5 flex items-center justify-between">
                <button onclick="goBackToSelection()" class="flex items-center gap-1.5 text-xs text-candy-400 hover:text-candy-600 font-medium transition-all">
                    <i class="fa-solid fa-arrow-left-long"></i><span>ย้อนกลับ</span>
                </button>
                <span class="text-[11px] text-candy-400">Monali OTP</span>
            </div>

            <!-- 1. Inbox List View -->
            <div id="inboxListView" class="p-4 md:p-6 space-y-4">
                <div class="flex items-center justify-between text-xs font-semibold text-candy-700 border-b border-candy-100 pb-2">
                    <span>กล่องจดหมายเข้าล่าสุด</span>
                    <span class="inline-flex items-center gap-1 bg-mint-100 text-teal-700 text-[10px] px-2.5 py-0.5 rounded-full">
                        <span class="h-1.5 w-1.5 rounded-full bg-mint-400 animate-pulse"></span>
                        <span>อัปเดตอัตโนมัติ (5s)</span>
                    </span>
                </div>
                <div id="emailListContainer" class="space-y-3 max-h-[380px] overflow-y-auto pr-1"></div>
                <div class="text-center text-[10px] text-candy-400 truncate pt-1">
                    กล่องข้อความสำหรับ: <span id="recipientEmailList" class="underline font-medium text-candy-600"></span>
                </div>
            </div>

            <!-- 2. Detailed Email View -->
            <div id="emailDetailView" class="hidden p-4 md:p-6 space-y-5">
                <div class="flex items-center justify-between border-b border-candy-100 pb-2">
                    <button onclick="goBackToSelection()" class="flex items-center gap-1.5 text-xs text-candy-400 hover:text-candy-600 font-medium transition-all">
                        <i class="fa-solid fa-chevron-left text-[9px]"></i><span>กลับไปหน้าค้นหา</span>
                    </button>
                    <span id="detailEmailTime" class="text-[10px] text-candy-400"></span>
                </div>

                <div id="otpDisplayCard" class="relative overflow-hidden rounded-[1.8rem] p-6 text-center space-y-3 border-2 border-candy-100"
                     style="background: linear-gradient(135deg,#fff0f7 0%,#f3eeff 55%,#eafbf7 100%);">
                    <p id="otpLabel" class="text-[11px] font-semibold text-candy-500 tracking-wider">รหัสยืนยันตัวตน (OTP)</p>
                    <div class="flex flex-col items-center justify-center gap-1">
                        <span id="otpCodeNumber" class="font-cute text-5xl font-bold text-candy-600 tracking-[0.2em] select-all">-</span>
                        <p id="otpRefText" class="text-[11px] text-candy-400 mt-1"></p>
                    </div>
                    <div class="pt-1 flex justify-center">
                        <button id="copyOtpBtn" onclick="copyOTP()" class="btn-candy text-white text-xs font-semibold px-5 py-2.5 rounded-2xl flex items-center gap-1.5">
                            <i class="fa-solid fa-copy text-[11px]"></i><span>คัดลอกรหัส OTP</span>
                        </button>
                    </div>
                </div>

                <div class="text-center pt-1" id="toggleIframeWrap">
                    <button onclick="toggleEmailIframe()" id="toggleIframeBtn" class="inline-flex items-center gap-1.5 text-xs text-candy-400 hover:text-candy-600 font-medium transition-all">
                        <i class="fa-solid fa-eye-slash"></i>
                        <span id="toggleIframeText">แสดงจดหมายฉบับจริง</span>
                    </button>
                </div>

                <div id="iframeContainer" class="hidden border-2 border-candy-100 rounded-2xl overflow-hidden bg-white">
                    <iframe id="emailIframe" class="w-full h-[450px] border-0 bg-white" title="Email preview content" sandbox="allow-same-origin allow-popups"></iframe>
                </div>

                <div class="text-center text-[10px] text-candy-400 truncate">
                    กล่องข้อความสำหรับ: <span id="recipientEmail" class="underline font-medium text-candy-600"></span>
                </div>
            </div>

            <div class="h-2" id="accentBar" style="background: linear-gradient(90deg,#f687b8,#c4b5fd,#5fd3c4);"></div>
        </div>

    </div>
    </main>

    <script>
        let selectedApp = '';
        let currentEmails = [];
        let pollingInterval = null;
        let isPolling = false;

        function bounceLogo() {}

        // ---------- ช่องรหัสผ่าน 4 หลัก ----------
        const pinBoxes = Array.from(document.querySelectorAll('.pin-box'));
        function getPin() { return pinBoxes.map(b => b.value).join(''); }
        function focusPin(i) { if (pinBoxes[i]) pinBoxes[i].focus(); }
        function clearPin() {
            pinBoxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
            updateSearchBtn();
        }
        function shakePin() {
            const g = document.getElementById('pinGroup');
            g.classList.remove('pin-shake'); void g.offsetWidth; g.classList.add('pin-shake');
        }
        function togglePinVisible() {
            const show = pinBoxes[0].type === 'password';
            pinBoxes.forEach(b => b.type = show ? 'text' : 'password');
            document.getElementById('pinEyeBtn').innerHTML = show
                ? '<i class="fa-regular fa-eye-slash mr-0.5"></i> ซ่อนรหัส'
                : '<i class="fa-regular fa-eye mr-0.5"></i> แสดงรหัส';
        }
        pinBoxes.forEach((box, i) => {
            box.addEventListener('input', () => {
                const digits = box.value.replace(/\D/g, '');
                if (digits.length > 1) { // วางรหัสทั้งชุดลงช่องเดียว
                    digits.slice(0, 4 - i).split('').forEach((d, k) => { pinBoxes[i + k].value = d; pinBoxes[i + k].classList.add('filled'); });
                    focusPin(Math.min(i + digits.length, 3));
                } else {
                    box.value = digits;
                    box.classList.toggle('filled', !!digits);
                    if (digits && i < 3) focusPin(i + 1);
                }
                updateSearchBtn();
                if (getPin().length === 4 && isEmailValid()) document.getElementById('searchBtn').focus();
            });
            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !box.value && i > 0) { focusPin(i - 1); pinBoxes[i - 1].value = ''; pinBoxes[i - 1].classList.remove('filled'); updateSearchBtn(); }
                if (e.key === 'ArrowLeft' && i > 0) focusPin(i - 1);
                if (e.key === 'ArrowRight' && i < 3) focusPin(i + 1);
                if (e.key === 'Enter' && getPin().length === 4) fetchOTP();
            });
            box.addEventListener('focus', () => box.select());
        });

        function isEmailValid() {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(document.getElementById('email').value.trim());
        }
        function updateSearchBtn() {
            const ok = isEmailValid() && getPin().length === 4;
            const btn = document.getElementById('searchBtn');
            btn.disabled = !ok;
            document.getElementById('searchHint').classList.toggle('hidden', ok);
        }

        // ---------- เลือกแอป ----------
        function selectApp(appName) {
            selectedApp = appName;
            const appSelectionScreen = document.getElementById('appSelectionScreen');
            const searchBoxCard = document.getElementById('searchBoxCard');
            document.getElementById('selectedAppTag').innerText = appName;
            document.getElementById('errorBox').classList.add('hidden');

            appSelectionScreen.classList.add('hidden');
            searchBoxCard.classList.remove('hidden');
            setTimeout(() => {
                searchBoxCard.classList.remove('scale-95', 'opacity-0');
                searchBoxCard.classList.add('scale-100', 'opacity-100');
                document.getElementById('email').focus();
            }, 50);
            updateSearchBtn();
        }

        function goBackToSelection() {
            const appSelectionScreen = document.getElementById('appSelectionScreen');
            const searchBoxCard = document.getElementById('searchBoxCard');
            const resultBox = document.getElementById('resultBox');

            stopOTPPolling();
            selectedApp = '';
            currentEmails = [];
            document.getElementById('email').value = '';
            clearPin();
            document.getElementById('errorBox').classList.add('hidden');

            searchBoxCard.classList.add('scale-95', 'opacity-0');
            resultBox.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                searchBoxCard.classList.add('hidden');
                resultBox.classList.add('hidden');
                appSelectionScreen.classList.remove('hidden');
            }, 300);
        }

        function showInboxList() {
            document.getElementById('emailDetailView').classList.add('hidden');
            document.getElementById('inboxListView').classList.remove('hidden');
        }

        function openEmailDetail(index) {
            const emailData = currentEmails[index];
            if (!emailData) return;

            const otpCodeNumber = document.getElementById('otpCodeNumber');
            const otpRefText = document.getElementById('otpRefText');
            const otpLabel = document.getElementById('otpLabel');
            const copyBtn = document.getElementById('copyOtpBtn');
            const iframeContainer = document.getElementById('iframeContainer');
            const toggleIframeBtn = document.getElementById('toggleIframeBtn');
            const toggleWrap = document.getElementById('toggleIframeWrap');
            const emailIframe = document.getElementById('emailIframe');

            document.getElementById('detailEmailTime').innerText = emailData.time || '';
            document.getElementById('recipientEmail').innerText = document.getElementById('email').value.trim();

            iframeContainer.classList.add('hidden');
            toggleIframeBtn.innerHTML = '<i class="fa-solid fa-eye-slash"></i> <span id="toggleIframeText">แสดงจดหมายฉบับจริง</span>';

            if (emailData.blocked) {
                otpLabel.innerText = '' + (emailData.blocked_reason || 'รหัสนี้ถูกปิดไว้โดยแอดมิน');
                otpCodeNumber.innerText = '••••••';
                otpCodeNumber.classList.add('opacity-40');
                otpRefText.innerText = 'หากต้องการใช้รหัสนี้ กรุณาติดต่อแอดมินร้านค่ะ';
                otpRefText.classList.remove('hidden');
                copyBtn.classList.add('hidden');
                toggleWrap.classList.add('hidden');
            } else {
                otpLabel.innerText = 'รหัสยืนยันตัวตน (OTP)';
                otpCodeNumber.classList.remove('opacity-40');
                otpCodeNumber.innerText = emailData.otp ? emailData.otp : 'ไม่พบรหัส OTP';
                copyBtn.classList.toggle('hidden', !emailData.otp);
                toggleWrap.classList.remove('hidden');
                if (emailData.ref) {
                    otpRefText.innerText = `รหัสอ้างอิง (Ref): ${emailData.ref}`;
                    otpRefText.classList.remove('hidden');
                } else {
                    otpRefText.innerText = '';
                    otpRefText.classList.add('hidden');
                }
            }

            if (emailData.html_body) {
                setTimeout(() => {
                    const iframeDoc = emailIframe.contentDocument || emailIframe.contentWindow.document;
                    iframeDoc.open();
                    iframeDoc.write(emailData.html_body);
                    iframeDoc.close();
                }, 50);
            }

            document.getElementById('inboxListView').classList.add('hidden');
            document.getElementById('emailDetailView').classList.remove('hidden');
        }

        function toast(text) {
            const notify = document.createElement('div');
            notify.className = "fixed top-5 left-1/2 -translate-x-1/2 btn-candy text-white text-xs font-semibold px-4 py-2.5 rounded-2xl z-50";
            notify.innerText = text;
            document.body.appendChild(notify);
            setTimeout(() => notify.remove(), 2000);
        }

        function copyOTP() {
            const current = currentEmails[0];
            if (current && current.blocked) return;
            const otpText = document.getElementById('otpCodeNumber').innerText;
            if (otpText && otpText !== '-' && otpText !== 'ไม่พบรหัส OTP') {
                navigator.clipboard.writeText(otpText).then(() => {
                    toast('คัดลอกรหัส OTP แล้วค่ะ');
                    bounceLogo();
                }).catch(err => console.error('Could not copy text: ', err));
            }
        }

        function toggleEmailIframe() {
            const iframeContainer = document.getElementById('iframeContainer');
            const toggleIframeBtn = document.getElementById('toggleIframeBtn');
            if (iframeContainer.classList.contains('hidden')) {
                iframeContainer.classList.remove('hidden');
                toggleIframeBtn.innerHTML = '<i class="fa-solid fa-eye"></i> <span id="toggleIframeText">ซ่อนจดหมายฉบับจริง</span>';
            } else {
                iframeContainer.classList.add('hidden');
                toggleIframeBtn.innerHTML = '<i class="fa-solid fa-eye-slash"></i> <span id="toggleIframeText">แสดงจดหมายฉบับจริง</span>';
            }
        }

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function renderEmails(emails, appName) {
            const container = document.getElementById('emailListContainer');
            container.innerHTML = '';

            if (!emails || emails.length === 0) {
                container.innerHTML = `
                    <div class="flex flex-col items-center justify-center py-9 px-4 text-center space-y-4 bg-candy-50/70 rounded-3xl border-2 border-dashed border-candy-200 select-none">
                        <div class="relative flex items-center justify-center">
                            <i class="fa-regular fa-envelope text-3xl text-candy-300"></i>
                        </div>
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-candy-600">กำลังรอรับข้อความ OTP ล่าสุด...</p>
                            <p class="text-[11px] text-candy-400 leading-snug">ระบบจะอัปเดตหน้านี้ให้อัตโนมัติทุก ๆ 5 วินาที<br>กดส่งรหัสยืนยันจากแอปปลายทางได้เลยค่ะ</p>
                        </div>
                    </div>`;
                return;
            }

            const emailData = emails[0];
            const card = document.createElement('div');
            card.className = "group border-2 border-candy-100 hover:border-candy-300 rounded-2xl p-4 bg-white/80 hover:bg-white transition-all duration-300 cursor-pointer flex items-start gap-3.5 active:scale-[0.99] select-none text-left";
            card.onclick = () => openEmailDetail(0);

            let senderClean = String(emailData.from || '').split('<')[0].replace(/"/g, '').trim();
            if (!senderClean) senderClean = appName + " Security";
            const time = String(emailData.time || '');
            const timePart = time.split(' เวลา ')[1] || '';
            const datePart = time.split(' เวลา ')[0] || '';

            card.innerHTML = `
                <div class="w-10 h-10 rounded-xl bg-candy-50 flex items-center justify-center text-candy-400 flex-shrink-0">
                    <i class="fa-solid fa-envelope text-sm"></i>
                </div>
                <div class="flex-1 min-w-0 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-[#6b3a5a] truncate">${escapeHtml(senderClean)}</span>
                        <span class="text-[10px] text-candy-400 whitespace-nowrap">${timePart ? 'เวลา ' + escapeHtml(timePart.replace(' (ตามเวลาประเทศไทย)', '')) : ''}</span>
                    </div>
                    <p class="text-[12px] font-medium text-[#6b3a5a] leading-snug truncate">${escapeHtml(emailData.subject)}</p>
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-[10px] text-candy-400">${escapeHtml(datePart)}</span>
                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-candy-600 group-hover:translate-x-1 transition-transform">
                            <span>เปิดดู OTP</span><i class="fa-solid fa-arrow-right-long text-[8px]"></i>
                        </span>
                    </div>
                </div>`;
            container.appendChild(card);
        }

        function requestBody(emailInput) {
            return `email=${encodeURIComponent(emailInput)}&app_name=${encodeURIComponent(selectedApp)}&pin=${encodeURIComponent(getPin())}`;
        }

        function handlePinError(data) {
            showError(data.message);
            if (data.pin_error) {
                const resultBox = document.getElementById('resultBox');
                resultBox.classList.add('hidden');
                clearPin();
                shakePin();
                focusPin(0);
            }
        }

        function startOTPPolling(emailInput) {
            stopOTPPolling();
            isPolling = true;
            pollingInterval = setInterval(async () => {
                const resultBox = document.getElementById('resultBox');
                if (resultBox.classList.contains('hidden') || !isPolling) {
                    stopOTPPolling();
                    return;
                }
                try {
                    const response = await fetch('get_otp.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: requestBody(emailInput)
                    });
                    if (response.ok) {
                        const data = await response.json();
                        if (data.success && data.emails && data.emails.length > 0) {
                            currentEmails = data.emails;
                            renderEmails(data.emails, selectedApp);
                            document.getElementById('errorBox').classList.add('hidden');
                            openEmailDetail(0);
                            copyOTP();
                            stopOTPPolling();
                        } else if (data.success === false && data.pin_error) {
                            handlePinError(data);
                            stopOTPPolling();
                        }
                        // success:false แบบอื่น (เช่น ยังไม่พบอีเมล) ให้รอรอบถัดไปต่อ
                    }
                } catch (e) {
                    console.log("Polling failure:", e);
                }
            }, 5000);
        }

        function stopOTPPolling() {
            isPolling = false;
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }

        async function fetchOTP() {
            const emailInput = document.getElementById('email').value.trim();
            const searchBtn = document.getElementById('searchBtn');
            const statusBox = document.getElementById('statusBox');
            const errorBox = document.getElementById('errorBox');
            const resultBox = document.getElementById('resultBox');

            errorBox.classList.add('hidden');
            stopOTPPolling();

            if (!emailInput) { showError('กรุณากรอกอีเมลของคุณก่อนนะคะ'); return; }
            if (!isEmailValid()) { showError('รูปแบบอีเมลไม่ถูกต้อง เช่น name@gmail.com'); return; }
            if (getPin().length !== 4) { showError('กรุณากรอกรหัสผ่าน 4 หลักให้ครบค่ะ'); shakePin(); focusPin(0); return; }

            searchBtn.disabled = true;
            statusBox.classList.remove('hidden');
            resultBox.classList.add('hidden');
            resultBox.classList.remove('scale-100', 'opacity-100');
            resultBox.classList.add('scale-95', 'opacity-0');

            try {
                const response = await fetch('get_otp.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: requestBody(emailInput)
                });
                const data = await response.json();
                const appName = selectedApp || 'OTP';

                if (data.success === false && data.pin_error) {
                    statusBox.classList.add('hidden');
                    handlePinError(data);
                    return;
                }
                if (data.success === false && data.message && /ล็อก/.test(data.message)) {
                    statusBox.classList.add('hidden');
                    showError(data.message);
                    return;
                }

                document.getElementById('recipientEmailList').innerText = emailInput;
                document.getElementById('inboxListView').classList.remove('hidden');
                document.getElementById('emailDetailView').classList.add('hidden');

                if (data.success && data.emails && data.emails.length > 0) {
                    currentEmails = data.emails;
                    renderEmails(data.emails, appName);
                    openEmailDetail(0);
                    copyOTP();
                } else {
                    currentEmails = [];
                    renderEmails([], appName);
                    startOTPPolling(emailInput);
                }

                setTimeout(() => {
                    statusBox.classList.add('hidden');
                    resultBox.classList.remove('hidden');
                    setTimeout(() => {
                        resultBox.classList.remove('scale-95', 'opacity-0');
                        resultBox.classList.add('scale-100', 'opacity-100');
                        resultBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 50);
                }, 400);

            } catch (error) {
                showError(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อเครือข่าย');
                statusBox.classList.add('hidden');
            } finally {
                updateSearchBtn();
            }
        }

        function showError(message) {
            document.getElementById('errorMessage').innerText = message;
            document.getElementById('errorBox').classList.remove('hidden');
        }
    </script>
</body>
</html>
