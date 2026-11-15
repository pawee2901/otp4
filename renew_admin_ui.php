<?php // แท็บ "ต่อเมล" ในหน้าแอดมิน (include จาก admin.php เมื่อล็อกอินแล้ว) — ข้อมูลโหลดผ่าน admin.php?action=renew_data ?>
<div id="tabContentRenew" class="hidden space-y-5">

    <!-- รายการลูกค้าต่อเมล -->
    <div class="main-card rounded-3xl border border-gray-100 p-4 md:p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2"><i class="fa-solid fa-repeat text-purple-600"></i><h2 class="text-base font-extrabold text-gray-900">ต่อเมล (เมลลูกค้าใน fam)</h2></div>
                <p class="text-xs text-gray-400 mt-0.5">เตือนลูกค้าทาง LINE ก่อนหมดอายุ · ลูกค้าจ่ายผ่าน QR ในแชท · ตรวจสลิปด้วย Slip2Go แล้วต่อวันให้เอง</p>
            </div>
            <button type="button" onclick="openRenewForm()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm flex items-center gap-1.5 self-start sm:self-auto">
                <i class="fa-solid fa-plus"></i><span>เพิ่มลูกค้า</span>
            </button>
        </div>
        <div id="renewSetupWarn" class="hidden bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3 text-xs leading-relaxed"></div>
        <div class="flex flex-col sm:flex-row gap-2">
            <input type="text" id="renewSearch" oninput="renderRenewList()" placeholder="ค้นหาเมลลูกค้า / เมลร้าน / ชื่อ LINE / รหัส"
                   class="flex-1 px-4 py-2 rounded-xl border border-gray-200 text-xs focus:ring-2 focus:ring-purple-600 outline-none">
        </div>
        <div class="flex flex-wrap gap-1.5" id="renewFilters"></div>
        <div id="renewList" class="space-y-2"><div class="py-8 text-center text-gray-400 text-xs"><i class="fa-solid fa-circle-notch fa-spin mr-1"></i> กำลังโหลด...</div></div>
    </div>

    <!-- เมลร้าน (fam) -->
    <div class="main-card rounded-3xl border border-gray-100 p-4 md:p-6 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 text-sm font-extrabold text-gray-900"><i class="fa-solid fa-people-roof text-purple-600"></i><span>เมลร้าน (fam)</span></div>
            <div class="flex items-center gap-2">
                <span id="renewFamSummary" class="text-[11px] text-gray-400"></span>
                <button type="button" onclick="addRenewFam()" class="text-xs font-bold text-purple-700 bg-purple-50 hover:bg-purple-100 border border-purple-200 px-3 py-1.5 rounded-lg"><i class="fa-solid fa-plus mr-1"></i>เพิ่มเมลร้าน</button>
            </div>
        </div>
        <div id="renewFams" class="grid grid-cols-1 sm:grid-cols-2 gap-2"></div>
    </div>

    <!-- แพ็กเกจ -->
    <div class="main-card rounded-3xl border border-gray-100 p-4 md:p-6 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 text-sm font-extrabold text-gray-900"><i class="fa-solid fa-tags text-purple-600"></i><span>แพ็กเกจต่อเมล</span></div>
            <button type="button" onclick="editRenewPkg(null)" class="text-xs font-bold text-purple-700 bg-purple-50 hover:bg-purple-100 border border-purple-200 px-3 py-1.5 rounded-lg"><i class="fa-solid fa-plus mr-1"></i>เพิ่มแพ็กเกจ</button>
        </div>
        <p class="text-[11px] text-gray-400">ปุ่มในการ์ด LINE จะแสดงแพ็กเกจเดิมของลูกค้าก่อน แล้วตามด้วยแพ็กเกจอื่นที่เปิดอยู่ (สูงสุด 4 ปุ่ม)</p>
        <div id="renewPkgs" class="space-y-2"></div>
    </div>

    <!-- ตั้งค่า -->
    <div class="main-card rounded-3xl border border-gray-100 p-4 md:p-6 space-y-4">
        <div class="flex items-center gap-2 text-sm font-extrabold text-gray-900 border-b border-gray-100 pb-3"><i class="fa-solid fa-gear text-purple-600"></i><span>ตั้งค่า LINE / รับเงิน / ค่าต่อช้า</span></div>

        <details class="bg-sky-50/60 border border-sky-100 rounded-2xl p-3 text-xs text-gray-700">
            <summary class="font-bold text-sky-800 cursor-pointer">วิธีตั้งค่าครั้งแรก (กดเพื่อดู)</summary>
            <ol class="list-decimal pl-5 mt-2 space-y-1.5 leading-relaxed">
                <li><b>LINE Developers</b> → เลือก Channel ของ LINE OA ร้าน (Messaging API) → แท็บ <b>Basic settings</b> คัดลอก <b>Channel secret</b> · แท็บ <b>Messaging API</b> กด Issue <b>Channel access token</b> แล้ววางในช่องด้านล่าง</li>
                <li><b>ถ้าช่อง Webhook URL ใน LINE Developers มีลิงก์ของบริการอื่นอยู่แล้ว (เช่น Slip2Go ตรวจสลิปในแชท)</b> → คัดลอกลิงก์นั้นมาใส่ช่อง <b>"Webhook เดิม (ส่งต่อ)"</b> ด้านล่างก่อน แล้วกดบันทึก — บริการเดิมจะยังทำงานเหมือนเดิม</li>
                <li>แท็บ Messaging API → <b>Webhook URL</b> ใส่ <code id="renewWebhookUrl2" class="bg-white px-1 rounded break-all"></code> แทน → กด Update → Verify → เปิด <b>Use webhook</b></li>
                <li>LINE Official Account Manager → ตั้งค่าการตอบกลับ: เปิด <b>Webhook</b> และ<b>ปิดข้อความตอบกลับอัตโนมัติ</b> (ไม่งั้นบอทจะตอบซ้อน) — แชทกับแอดมินยังใช้ได้ตามปกติ</li>
                <li><b>Slip2Go</b> → เมนู API Connect: คัดลอก <b>API URL</b> และ <b>Secret Key</b> มาใส่ด้านล่าง (ถ้าตั้ง IP Whitelist ไว้ ต้องปิดหรือเพิ่ม IP ของ Render)</li>
                <li>ใส่ <b>พร้อมเพย์</b> ที่จะรับเงิน (QR สร้างยอดพอดีให้ลูกค้าอัตโนมัติ)</li>
                <li><b>cron-job.org</b> → เพิ่ม Cronjob เรียก URL งานอัตโนมัติด้านล่าง <b>ทุก 15 นาที</b> (ใช้ส่งเตือนก่อนหมดอายุ)</li>
                <li>แอดมินพิมพ์ <b id="renewAdminCode2"></b> ในแชท LINE ร้าน (จาก LINE ส่วนตัว) เพื่อรับแจ้งเตือน เช่น ลูกค้าต่อแล้ว / เลยกำหนดต้องลบเมล</li>
            </ol>
        </details>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">LINE Channel access token <span id="rsTokenSet" class="text-emerald-600"></span></span>
                <input id="rsLineToken" type="password" placeholder="เว้นว่าง = ไม่เปลี่ยน" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">LINE Channel secret <span id="rsSecretSet" class="text-emerald-600"></span></span>
                <input id="rsLineSecret" type="password" placeholder="เว้นว่าง = ไม่เปลี่ยน" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">LINE OA ID (เช่น @monalishop)</span>
                <input id="rsOaId" type="text" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <label class="space-y-1 sm:col-span-2"><span class="text-[11px] font-bold text-gray-600">Webhook เดิม (ส่งต่อ) — ลิงก์ที่เคยอยู่ในช่อง Webhook URL ของ LINE เช่น ของ Slip2Go · เว้นว่างถ้าไม่มี</span>
                <input id="rsForward" type="text" placeholder="https://..." class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600">
                <span class="block text-[10px] text-gray-400">ข้อความที่ไม่ใช่เรื่องต่อเมล (เช่น สลิปซื้อของปกติ) จะถูกส่งต่อไปที่ลิงก์นี้ บริการเดิมจึงยังตรวจสลิป/ตอบแชทได้เหมือนเดิม</span></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">พร้อมเพย์รับเงิน (เบอร์ / เลข 13 หลัก)</span>
                <input id="rsPromptpay" type="text" inputmode="numeric" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">ชื่อบัญชีผู้รับ (แสดงให้ลูกค้า)</span>
                <input id="rsPayName" type="text" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <label class="space-y-1 sm:col-span-2"><span class="text-[11px] font-bold text-gray-600">วิธีตรวจสลิปค่าต่อเมล</span>
                <select id="rsSlipMode" onchange="document.getElementById('rsApiBox').classList.toggle('opacity-50', this.value !== 'api')" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none bg-white">
                    <option value="api">อัตโนมัติ — ระบบตรวจสลิปผ่าน Slip2Go API แล้วต่ออายุ + ตอบลูกค้าทันที (แอดมินไม่ต้องกด)</option>
                    <option value="chat">ให้ Slip2Go ในแชทของร้านตรวจ แล้วแอดมินกด "ยืนยันรับเงิน" (ไม่ต้องใช้ API)</option>
                </select></label>
            <div id="rsApiBox" class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">Slip2Go API URL (เฉพาะแบบตรวจเองผ่าน API)</span>
                <input id="rsSlipUrl" type="text" placeholder="https://..." class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">Slip2Go Secret Key <span id="rsSlipSet" class="text-emerald-600"></span></span>
                <input id="rsSlipSecret" type="password" placeholder="เว้นว่าง = ไม่เปลี่ยน" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            </div>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">รับยอดในสลิปเกินได้ไม่เกิน (บาท) — เช่น โอนผ่าน Wallet +5</span>
                <input id="rsAmountExtra" type="number" min="0" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600"></label>
            <details class="sm:col-span-2 text-[11px] text-gray-500" open>
                <summary class="cursor-pointer font-bold">ตรวจบัญชีผู้รับในสลิป (แนะนำ — กันสลิปที่โอนเข้าบัญชีอื่น)</summary>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-2">
                    <label class="space-y-1 sm:col-span-2"><span class="font-bold text-gray-600">บัญชีที่รับเงิน ใส่ได้หลายบัญชี รูปแบบ <code>ประเภท:เลข</code> คั่นด้วยจุลภาค</span>
                        <input id="rsRecvAcc" type="text" placeholder="01004:1848641592, 04000:0813154812" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none">
                        <span class="block text-[10px] text-gray-400">ประเภทของ Slip2Go: 01004 = กสิกร (K Plus) · 04000 = TrueMoney Wallet · 02001 = พร้อมเพย์เบอร์มือถือ</span></label>
                    <label class="space-y-1"><span class="font-bold text-gray-600">ประเภทตั้งต้น (ถ้าใส่แค่เลข)</span>
                        <input id="rsRecvType" type="text" placeholder="01004" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
                </div>
            </details>
        </div>

        <div class="border-t border-gray-100 pt-4 space-y-3">
            <p class="text-xs font-extrabold text-gray-800"><i class="fa-regular fa-image text-purple-600 mr-1"></i>การ์ดเตือนลูกค้าใน LINE</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">แบบการ์ด</span>
                    <select id="rsCardStyle" onchange="renderRenewCardPreview()" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none bg-white">
                        <option value="image">การ์ดรูปของร้าน (เติมเมล + วันที่ลงในรูป · ปุ่ม "กดต่ออายุ" / "ไม่ต้องการต่ออายุแล้ว")</option>
                        <option value="basic">การ์ดข้อความ + ปุ่มเลือกแพ็กเกจ</option>
                    </select></label>
                <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">วันครบกำหนดชำระ = วันหมดอายุ − กี่วัน</span>
                    <input id="rsPayBefore" type="number" min="0" oninput="renderRenewCardPreview()" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 items-start">
                <div id="renewCardPreview" class="relative w-full max-w-[300px] rounded-2xl overflow-hidden border border-gray-200 flex-shrink-0"></div>
                <div class="text-[11px] text-gray-500 space-y-2">
                    <p>ตัวอย่างการ์ดที่ลูกค้าจะเห็นใน LINE — ระบบเติม <b>อีเมล / วันครบกำหนดชำระ / วันหมดอายุ</b> ลงในช่องให้เอง</p>
                    <p><b>กดต่ออายุ</b> → ส่งช่องทางโอนทันที ตามแพ็กเกจที่ตั้งให้ลูกค้าคนนั้น<br><b>ไม่ต้องการต่ออายุแล้ว</b> → ถามยืนยัน แล้วหยุดเตือน + แจ้งแอดมินให้ลบเมลหลังหมดอายุ</p>
                    <p>เปลี่ยนรูปได้ (JPG/PNG สี่เหลี่ยมจัตุรัส ไม่เกิน 1.5MB) — ช่องกรอกและปุ่มต้องอยู่ตำแหน่งเดิมเหมือนรูปตั้งต้น</p>
                    <div class="flex flex-wrap gap-1.5">
                        <label class="cursor-pointer text-[11px] font-bold px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"><i class="fa-solid fa-upload mr-1"></i>เปลี่ยนรูป
                            <input type="file" accept="image/jpeg,image/png" class="hidden" onchange="uploadRenewCard(this, 'card')"></label>
                        <button type="button" id="renewCardResetBtn" onclick="if(confirm('กลับไปใช้รูปตั้งต้น?'))renewAction('renew_card_reset',{k:'card'})" class="hidden text-[11px] font-bold px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white text-gray-500">ใช้รูปตั้งต้น</button>
                        <button type="button" onclick="renewAction('renew_card_test',{},false)" class="text-[11px] font-bold px-2.5 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700"><i class="fa-brands fa-line mr-1"></i>ส่งตัวอย่างเข้า LINE แอดมิน</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-t border-gray-100 pt-4 space-y-3">
            <p class="text-xs font-extrabold text-gray-800"><i class="fa-solid fa-qrcode text-purple-600 mr-1"></i>หน้าชำระเงิน (หลังลูกค้ากด "กดต่ออายุ")</p>
            <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">แบบหน้าชำระเงิน</span>
                <select id="rsPayStyle" onchange="renderRenewPayPreview()" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none bg-white">
                    <option value="static">รูป How To Pay ของร้านตามเดิม (QR / บัญชีในรูป) + ข้อความบอกยอด — ไม่ต้องตั้งพร้อมเพย์</option>
                    <option value="image">รูป How To Pay ของร้าน + QR ยอดพอดีวางทับช่อง QR (ต้องใส่พร้อมเพย์)</option>
                    <option value="qr">รูป QR ยอดพอดีอย่างเดียว (ต้องใส่พร้อมเพย์)</option>
                </select></label>
            <label class="flex items-center gap-2 text-xs text-gray-700"><input type="checkbox" id="rsPayText" class="accent-purple-600 w-4 h-4">
                ส่งข้อความบอกยอดโอนตามหลังรูป <span class="text-[10px] text-gray-400">(ไม่ติ๊ก = ส่งแค่รูป · แก้ข้อความได้ที่ "ข้อความที่ส่งให้ลูกค้า")</span></label>
            <div class="flex flex-col sm:flex-row gap-3 items-start">
                <div id="renewPayPreview" class="relative w-full max-w-[300px] rounded-2xl overflow-hidden border border-gray-200 flex-shrink-0"></div>
                <div class="text-[11px] text-gray-500 space-y-2">
                    <p>ระบบสร้าง <b>QR พร้อมเพย์ยอดพอดี</b> ของแต่ละรายการ แล้ววางทับช่อง QR ในรูป ลูกค้าแตะ QR เพื่อเปิดรูปใหญ่ไว้บันทึก/สแกนได้ ตามด้วยข้อความบอกยอดโอน</p>
                    <p>⚠️ QR ใช้ <b>พร้อมเพย์ที่ตั้งไว้ด้านบน</b> — ต้องเป็นบัญชีเดียวกับชื่อในรูป</p>
                    <div class="flex flex-wrap gap-1.5">
                        <label class="cursor-pointer text-[11px] font-bold px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50"><i class="fa-solid fa-upload mr-1"></i>เปลี่ยนรูป
                            <input type="file" accept="image/jpeg,image/png" class="hidden" onchange="uploadRenewCard(this, 'pay')"></label>
                        <button type="button" id="renewPayResetBtn" onclick="if(confirm('กลับไปใช้รูปตั้งต้น?'))renewAction('renew_card_reset',{k:'pay'})" class="hidden text-[11px] font-bold px-2.5 py-1.5 rounded-lg border border-gray-200 bg-white text-gray-500">ใช้รูปตั้งต้น</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 border-t border-gray-100 pt-4">
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">เวลาส่งแจ้งเตือนอัตโนมัติ (เวลาไทย)</span><input id="rsRemindTime" type="time" step="900" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"><span class="block text-[10px] text-gray-400">ส่งภายใน ~15 นาทีหลังเวลานี้</span></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">เตือนก่อนหมด (วัน)</span><input id="rsRemind" type="number" min="0" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">ต่อราคาปกติได้ถึง หมดอายุ + (วัน)</span><input id="rsGrace" type="number" min="0" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">ต่อช้า บวก (บาท)</span><input id="rsLate" type="number" min="0" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">ต่อหลังลบเมล บวก (บาท)</span><input id="rsRemoved" type="number" min="0" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
            <label class="space-y-1"><span class="text-[11px] font-bold text-gray-600">1 fam ใส่ได้กี่เมล</span><input id="rsFamSize" type="number" min="1" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none"></label>
        </div>

        <div class="bg-gray-50 border border-gray-100 rounded-2xl p-3 space-y-2 text-[11px] text-gray-600">
            <div><b>Webhook URL (ใส่ใน LINE Developers):</b> <code id="renewWebhookUrl" class="break-all"></code> <button type="button" onclick="renewCopy(document.getElementById('renewWebhookUrl').innerText)" class="text-purple-600 underline ml-1">คัดลอก</button></div>
            <div><b>URL งานอัตโนมัติ (ใส่ใน cron-job.org ทุก 15 นาที):</b> <code id="renewCronUrl" class="break-all"></code> <button type="button" onclick="renewCopy(document.getElementById('renewCronUrl').innerText)" class="text-purple-600 underline ml-1">คัดลอก</button>
                <span id="renewLastCron" class="block text-gray-400"></span></div>
            <div><b>รหัสแอดมิน (พิมพ์ในแชท LINE ร้านเพื่อรับแจ้งเตือน):</b> <code id="renewAdminCode"></code> · <span id="renewAdmins" class="text-gray-500"></span></div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="saveRenewSettings()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2.5 rounded-xl shadow-sm"><i class="fa-solid fa-floppy-disk mr-1"></i>บันทึกการตั้งค่า</button>
            <button type="button" onclick="renewAction('renew_test_line', {})" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-200 font-bold text-xs px-4 py-2.5 rounded-xl"><i class="fa-brands fa-line text-emerald-500 mr-1"></i>ทดสอบ LINE</button>
        </div>
    </div>

    <!-- ข้อความที่ส่งให้ลูกค้า -->
    <div class="main-card rounded-3xl border border-gray-100 p-4 md:p-6 space-y-3">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 text-sm font-extrabold text-gray-900"><i class="fa-regular fa-comment-dots text-purple-600"></i><span>ข้อความที่ส่งให้ลูกค้า</span></div>
            <button type="button" onclick="saveRenewMsgs()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2 rounded-xl shadow-sm"><i class="fa-solid fa-floppy-disk mr-1"></i>บันทึกข้อความ</button>
        </div>
        <p class="text-[11px] text-gray-400">แก้ข้อความได้ตามใจ · กดปุ่ม <b>{ตัวแปร}</b> เพื่อแทรกข้อมูลจริงของลูกค้า (เช่น เมล วันหมดอายุ ยอดเงิน) · ลบข้อความทั้งหมด = กลับไปใช้ข้อความตั้งต้น</p>
        <div id="renewMsgs" class="space-y-3"></div>
        <div class="flex justify-end"><button type="button" onclick="saveRenewMsgs()" class="bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs px-4 py-2 rounded-xl shadow-sm"><i class="fa-solid fa-floppy-disk mr-1"></i>บันทึกข้อความ</button></div>
    </div>

    <!-- บันทึกเหตุการณ์ -->
    <div class="main-card rounded-3xl border border-gray-100 p-4 md:p-6 space-y-3">
        <div class="flex items-center gap-2 text-sm font-extrabold text-gray-900"><i class="fa-solid fa-bell text-purple-600"></i><span>แจ้งเตือนล่าสุด</span></div>
        <div id="renewLog" class="space-y-1.5 max-h-96 overflow-y-auto"></div>
    </div>
</div>

<!-- Modal: เพิ่ม/แก้ไขลูกค้าต่อเมล -->
<div id="renewFormModal" class="hidden fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-3xl w-full max-w-md p-5 space-y-3 shadow-xl max-h-[92vh] overflow-y-auto">
        <p id="renewFormTitle" class="text-sm font-extrabold text-gray-900">เพิ่มลูกค้าต่อเมล</p>
        <input type="hidden" id="rfId">
        <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">เมลลูกค้า (เมลที่อยู่ใน fam)</span>
            <input id="rfEmail" type="email" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-purple-600"></label>
        <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">เมลร้าน (fam) ที่ลูกค้าอยู่</span>
            <select id="rfFamSelect" onchange="onRenewFamSelect()" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none bg-white"></select>
            <input id="rfFam" type="email" placeholder="พิมพ์เมลร้านใหม่" class="hidden w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-purple-600 mt-1.5"></label>
        <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">แพ็กเกจ</span>
            <select id="rfPkg" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none bg-white"></select></label>
        <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">วันหมดอายุ</span>
            <input id="rfExp" type="date" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-purple-600">
            <span class="flex flex-wrap gap-1 pt-1" id="rfExpQuick"></span></label>
        <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">โน้ต (ลูกค้าไม่เห็น)</span>
            <input id="rfNote" type="text" maxlength="500" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none focus:ring-2 focus:ring-purple-600"></label>
        <div class="flex gap-2 pt-1">
            <button type="button" onclick="document.getElementById('renewFormModal').classList.add('hidden')" class="flex-1 py-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
            <button type="button" onclick="saveRenewForm()" class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl">บันทึก</button>
        </div>
    </div>
</div>

<!-- Modal: ข้อความส่งรหัสให้ลูกค้า / ต่อเอง / แพ็กเกจ -->
<div id="renewInfoModal" class="hidden fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-3xl w-full max-w-md p-5 space-y-3 shadow-xl max-h-[92vh] overflow-y-auto" id="renewInfoBody"></div>
</div>

<script>
    let RENEW = null;
    let renewFilter = 'all';
    const rEsc = (t) => { const d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }; // textContent: คงบรรทัดใหม่ไว้ (innerText แปลงเป็น <br>)
    const rBaht = (n) => '฿' + Number(n).toLocaleString('th-TH', { maximumFractionDigits: 2 });

    async function renewAction(action, data, reload = true) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        try {
            const res = await fetch('admin.php?action=' + action, { method: 'POST', body: fd });
            const j = await res.json();
            if (!j.success) { alert(j.message || 'ทำรายการไม่สำเร็จ'); return null; }
            if (j.message && action !== 'renew_data') showNotification(j.message);
            if (reload && action !== 'renew_data') await loadRenew();
            return j;
        } catch (e) { alert('เกิดข้อผิดพลาดเครือข่าย'); return null; }
    }

    async function loadRenew() {
        const j = await renewAction('renew_data', {}, false);
        if (!j) return;
        RENEW = j;
        renderRenewAll();
    }

    function renewCopy(text) {
        navigator.clipboard.writeText(text).then(() => showNotification('คัดลอกแล้ว'), () => prompt('คัดลอกข้อความนี้', text));
    }

    function renewAddFriend() {
        const oa = RENEW.settings.line_oa_id;
        return oa ? `\nแอด LINE ร้าน: https://line.me/R/ti/p/${encodeURIComponent(oa.startsWith('@') ? oa : '@' + oa)}` : '';
    }

    function renewFill(tpl, vars) {
        return tpl.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m));
    }

    function renewMsgTpl(key) {
        const m = (RENEW.msgs || []).find(x => x.key === key);
        return m ? (m.value.trim() || m.default) : '';
    }

    function renewCodeMsg(r) {
        return renewFill(renewMsgTpl('msg_code'), { code: r.link_code, email: r.customer_email, add_friend: renewAddFriend() });
    }

    // ---------- แก้ข้อความที่ส่งให้ลูกค้า ----------
    const RENEW_VAR_LABELS = {
        email: 'เมลลูกค้า', expiry: 'วันหมดอายุ', package: 'ชื่อแพ็กเกจ', days: 'จำนวนวัน', amount: 'ยอดโอน', fee_line: 'บรรทัดค่าต่อช้า (ถ้ามี)',
        pay_hint: 'วิธีจ่าย (อัตโนมัติตามแบบหน้าชำระเงิน)', after_slip: 'หลังส่งสลิป (อัตโนมัติตามวิธีตรวจสลิป)', readd_line: 'บรรทัดเพิ่มเมลกลับ fam (ถ้ามี)',
        reason: 'เหตุผลที่สลิปไม่ผ่าน', code: 'รหัสผูกแชท', add_friend: 'ลิงก์แอด LINE ร้าน', remind_days: 'เตือนก่อนหมดกี่วัน',
    };

    function renewSampleVars() {
        const s = RENEW.settings;
        return {
            email: 'customer@gmail.com', expiry: rThai(rAddDays(RENEW.today, 3)), package: (RENEW.packages[0] || {}).name || 'Youtube ต่อเมล',
            days: (RENEW.packages[0] || {}).days || 30, amount: rBaht((RENEW.packages[0] || {}).price || 79), fee_line: '',
            pay_hint: s.pay_mode === 'static' ? 'โอนยอดนี้ตามช่องทางในรูปด้านบน' : 'สแกน QR ด้านบนจ่ายยอดนี้พอดี · QR ใช้ได้ 15 นาที',
            after_slip: s.slip_mode === 'api' ? 'ระบบจะต่ออายุให้อัตโนมัติ' : 'ตรวจสลิปแล้วแอดมินจะยืนยันการต่ออายุให้ค่ะ',
            readd_line: '', reason: 'ยอดในสลิป ฿78 ไม่ตรงกับยอดที่ต้องโอน ฿79', code: 'R12345', add_friend: renewAddFriend(), remind_days: s.remind_days,
        };
    }

    function renderRenewMsgs() {
        const box = document.getElementById('renewMsgs');
        if (!box || !RENEW.msgs) return;
        if (box.dataset.ready === '1') return; // ไม่ทับข้อความที่กำลังแก้อยู่ตอนโหลดข้อมูลใหม่
        box.dataset.ready = '1';
        box.innerHTML = RENEW.msgs.map(m => `
            <div class="border border-gray-200 rounded-2xl p-3 space-y-2" data-key="${rEsc(m.key)}">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0"><p class="text-xs font-extrabold text-gray-900">${rEsc(m.label)}</p><p class="text-[10px] text-gray-400">ส่งเมื่อ: ${rEsc(m.when)}</p></div>
                    <button type="button" onclick="resetRenewMsg('${rEsc(m.key)}')" class="flex-shrink-0 text-[10px] font-bold text-gray-500 hover:text-purple-700 underline">คืนค่าเดิม</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <textarea rows="5" oninput="previewRenewMsg('${rEsc(m.key)}')" class="w-full px-3 py-2 rounded-xl border border-gray-200 text-xs outline-none focus:ring-2 focus:ring-purple-600 font-sans">${rEsc(m.value.trim() || m.default)}</textarea>
                    <div class="rounded-xl bg-[#8cabd9]/20 border border-sky-100 p-2"><p class="text-[9px] font-bold text-gray-400 mb-1">ตัวอย่างที่ลูกค้าเห็น</p>
                        <div class="msg-preview bg-white rounded-2xl rounded-tl-sm px-3 py-2 text-xs text-gray-800 whitespace-pre-wrap break-words shadow-sm"></div></div>
                </div>
                ${m.vars.length ? `<div class="flex flex-wrap gap-1">${m.vars.map(v => `<button type="button" title="${rEsc(RENEW_VAR_LABELS[v] || v)}" onclick="insertRenewVar('${rEsc(m.key)}','${v}')" class="text-[10px] font-bold px-2 py-0.5 rounded-lg border border-purple-200 bg-purple-50 text-purple-700 hover:bg-purple-100">{${v}} <span class="font-normal text-purple-500">${rEsc(RENEW_VAR_LABELS[v] || '')}</span></button>`).join('')}</div>` : ''}
            </div>`).join('');
        RENEW.msgs.forEach(m => previewRenewMsg(m.key));
    }

    function renewMsgBox(key) { return document.querySelector(`#renewMsgs [data-key="${key}"]`); }

    function previewRenewMsg(key) {
        const el = renewMsgBox(key);
        if (!el) return;
        const t = el.querySelector('textarea').value;
        const def = (RENEW.msgs.find(x => x.key === key) || {}).default || '';
        el.querySelector('.msg-preview').innerText = renewFill(t.trim() ? t : def, renewSampleVars());
    }

    function insertRenewVar(key, v) {
        const ta = renewMsgBox(key).querySelector('textarea');
        const s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? ta.value.length;
        ta.value = ta.value.slice(0, s) + '{' + v + '}' + ta.value.slice(e);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = s + v.length + 2;
        previewRenewMsg(key);
    }

    function resetRenewMsg(key) {
        const m = RENEW.msgs.find(x => x.key === key);
        renewMsgBox(key).querySelector('textarea').value = m.default;
        previewRenewMsg(key);
    }

    async function saveRenewMsgs() {
        const out = {};
        document.querySelectorAll('#renewMsgs [data-key]').forEach(el => { out[el.dataset.key] = el.querySelector('textarea').value; });
        const j = await renewAction('renew_msgs_save', { msgs: JSON.stringify(out) });
        if (j) { document.getElementById('renewMsgs').dataset.ready = '0'; renderRenewMsgs(); }
    }

    function renderRenewAll() {
        const s = RENEW.settings;
        const warn = [];
        if (!s.line_token_set || !s.line_secret_set) warn.push('ยังไม่ได้ใส่ LINE Channel access token / secret');
        if (s.pay_style !== 'static' && !s.promptpay_id) warn.push('เลือกหน้าชำระเงินแบบมี QR ยอดพอดี แต่ยังไม่ได้ใส่พร้อมเพย์ — ตอนนี้ระบบส่งรูปวิธีชำระเงินของร้านตามเดิมแทน');
        if (s.slip_mode === 'api' && (!s.slip2go_url || !s.slip2go_secret_set)) warn.push('ยังไม่ได้ตั้งค่า Slip2Go API (เลือกแบบตรวจเองผ่าน API ไว้)');
        if (s.slip_mode !== 'api' && !s.forward_webhook) warn.push('เลือกให้ Slip2Go ในแชทตรวจสลิป แต่ยังไม่ได้ใส่ "Webhook เดิม (ส่งต่อ)" — สลิปจะไม่ถูกส่งไปให้ Slip2Go (แอดมินต้องดูสลิปเอง)');
        const waiting = RENEW.renewals.filter(r => r.slip_sent).length;
        if (waiting) warn.unshift(`มีสลิปรอยืนยัน ${waiting} รายการ (ดูกรอบสีฟ้าในรายการ)`);
        if (!RENEW.packages.length) warn.push('ยังไม่มีแพ็กเกจ — เพิ่มแพ็กเกจก่อนเพิ่มลูกค้า');
        if (!s.last_cron) warn.push('งานอัตโนมัติยังไม่เคยทำงาน — ตั้ง cron-job.org ตาม URL ในส่วนตั้งค่า');
        const w = document.getElementById('renewSetupWarn');
        w.classList.toggle('hidden', !warn.length);
        w.innerHTML = '<b><i class="fa-solid fa-triangle-exclamation mr-1"></i>ตั้งค่ายังไม่ครบ:</b><br>• ' + warn.map(rEsc).join('<br>• ');

        // ตั้งค่า
        document.getElementById('rsTokenSet').innerText = s.line_token_set ? '✓ ตั้งแล้ว' : '';
        document.getElementById('rsSecretSet').innerText = s.line_secret_set ? '✓ ตั้งแล้ว' : '';
        document.getElementById('rsSlipSet').innerText = s.slip2go_secret_set ? '✓ ตั้งแล้ว' : '';
        const set = (id, v) => { const el = document.getElementById(id); if (document.activeElement !== el) el.value = v; };
        set('rsCardStyle', s.card_style || 'image'); set('rsPayBefore', s.pay_before_days);
        document.getElementById('renewCardResetBtn').classList.toggle('hidden', !s.card_custom);
        renderRenewCardPreview();
        set('rsPayStyle', s.pay_style || 'static');
        document.getElementById('rsPayText').checked = s.pay_text !== false;
        document.getElementById('renewPayResetBtn').classList.toggle('hidden', !s.pay_custom);
        renderRenewPayPreview();
        set('rsOaId', s.line_oa_id); set('rsSlipMode', s.slip_mode || 'chat');
        document.getElementById('rsApiBox').classList.toggle('opacity-50', (s.slip_mode || 'chat') !== 'api'); set('rsForward', s.forward_webhook || ''); set('rsPromptpay', s.promptpay_id); set('rsPayName', s.pay_name);
        set('rsAmountExtra', s.amount_extra); set('rsSlipUrl', s.slip2go_url); set('rsRecvType', s.receiver_type); set('rsRecvAcc', s.receiver_account);
        set('rsRemindTime', s.remind_time || '09:00'); set('rsRemind', s.remind_days); set('rsGrace', s.grace_days); set('rsLate', s.late_fee); set('rsRemoved', s.removed_fee); set('rsFamSize', s.fam_size);
        document.getElementById('renewWebhookUrl').innerText = s.webhook_url;
        document.getElementById('renewWebhookUrl2').innerText = s.webhook_url;
        document.getElementById('renewCronUrl').innerText = s.cron_url;
        document.getElementById('renewLastCron').innerText = s.last_cron ? `ทำงานล่าสุด: ${s.last_cron}` : 'ยังไม่เคยทำงาน';
        document.getElementById('renewAdminCode').innerText = s.admin_code;
        document.getElementById('renewAdminCode2').innerText = s.admin_code;
        document.getElementById('renewAdmins').innerText = s.admins.length ? 'รับแจ้งเตือน: ' + s.admins.join(', ') : 'ยังไม่มีแอดมินรับแจ้งเตือนทาง LINE';

        renderRenewList();
        renderRenewMsgs();
        renderRenewFams();
        renderRenewPkgs();
        document.getElementById('renewLog').innerHTML = RENEW.log.length ? RENEW.log.map(l => `
            <div class="border border-gray-100 rounded-xl px-3 py-2 text-[11px]">
                <div class="flex justify-between gap-2"><b class="text-gray-800">${rEsc(l.title)}</b><span class="text-gray-400 flex-shrink-0">${rEsc(l.time)}</span></div>
                <div class="text-gray-500 whitespace-pre-line">${rEsc(l.body || '')}</div></div>`).join('') : '<p class="text-xs text-gray-400">ยังไม่มีแจ้งเตือน</p>';
    }

    function renewStateOf(r) {
        if (r.status !== 'active') return 'stopped';
        if (r.removed) return 'removed';
        if (r.left < 0) return 'expired';
        if (r.left <= RENEW.settings.remind_days) return 'soon';
        return 'ok';
    }

    function renderRenewList() {
        if (!RENEW) return;
        const q = document.getElementById('renewSearch').value.trim().toLowerCase();
        const rows = RENEW.renewals;
        const counts = { all: rows.length, soon: 0, expired: 0, removed: 0, unlinked: 0, stopped: 0 };
        rows.forEach(r => { const st = renewStateOf(r); if (counts[st] !== undefined) counts[st]++; if (!r.line_linked && r.status === 'active') counts.unlinked++; });
        const chips = [['all', 'ทั้งหมด'], ['soon', 'ใกล้หมด'], ['expired', 'หมดแล้ว'], ['removed', 'ลบเมลแล้ว'], ['unlinked', 'ยังไม่ผูก LINE'], ['stopped', 'หยุดแล้ว']];
        document.getElementById('renewFilters').innerHTML = chips.map(([k, label]) =>
            `<button type="button" onclick="renewFilter='${k}';renderRenewList()" class="text-[11px] font-bold px-2.5 py-1 rounded-lg border ${renewFilter === k ? 'bg-purple-600 text-white border-purple-600' : 'bg-white text-gray-600 border-gray-200'}">${label} ${counts[k]}</button>`).join('');

        const list = rows.filter(r => {
            const st = renewStateOf(r);
            if (renewFilter === 'unlinked' ? (r.line_linked || r.status !== 'active') : (renewFilter !== 'all' && st !== renewFilter)) return false;
            if (!q) return true;
            return [r.customer_email, r.shop_email, r.line_name, r.link_code, r.note].join(' ').toLowerCase().includes(q);
        });
        const box = document.getElementById('renewList');
        if (!list.length) { box.innerHTML = `<p class="text-center text-xs text-gray-400 py-6">${rows.length ? 'ไม่พบรายการ' : 'ยังไม่มีลูกค้าต่อเมล กดปุ่ม "เพิ่มลูกค้า"'}</p>`; return; }
        box.innerHTML = list.map(r => {
            const st = renewStateOf(r);
            const badge = {
                ok: ['bg-emerald-50 text-emerald-700 border-emerald-200', `เหลือ ${r.left} วัน`],
                soon: ['bg-amber-50 text-amber-700 border-amber-200', r.left === 0 ? 'หมดวันนี้' : `เหลือ ${r.left} วัน`],
                expired: ['bg-rose-50 text-rose-700 border-rose-200', `หมดแล้ว ${-r.left} วัน`],
                removed: ['bg-gray-100 text-gray-600 border-gray-200', 'ลบเมลออกแล้ว'],
                stopped: ['bg-gray-100 text-gray-500 border-gray-200', 'หยุดแล้ว'],
            }[st];
            const line = r.line_linked
                ? `<span class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-2 py-0.5"><i class="fa-brands fa-line"></i>${rEsc(r.line_name || 'ผูกแล้ว')}</span>`
                : `<button type="button" onclick='showRenewCode(${JSON.stringify(r.id)})' class="inline-flex items-center gap-1 text-[10px] font-bold text-white bg-rose-500 hover:bg-rose-600 rounded-lg px-2 py-0.5"><i class="fa-solid fa-link"></i>ส่งรหัส ${rEsc(r.link_code)}</button>`;
            return `<div class="border ${st === 'expired' ? 'border-rose-200 bg-rose-50/30' : st === 'soon' ? 'border-amber-200 bg-amber-50/30' : 'border-gray-200 bg-white'} rounded-2xl p-3 flex gap-3">
                <div class="flex-1 min-w-0 space-y-2">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="text-xs font-extrabold text-gray-900 break-all">${rEsc(r.customer_email)}</div>
                        <div class="text-[11px] text-gray-500 break-all">fam: ${rEsc(r.shop_email || '-')} · ${rEsc(r.package)}</div>
                    </div>
                    <span class="flex-shrink-0 text-[10px] font-bold border rounded-lg px-2 py-0.5 ${badge[0]}">${badge[1]}</span>
                </div>
                <div class="flex flex-wrap items-center gap-1.5 text-[10px]">
                    <span class="text-gray-600 font-bold"><i class="fa-regular fa-calendar mr-0.5"></i>หมด ${rEsc(r.expires_th)}</span>
                    ${line}
                    ${r.reminded ? '<span class="text-sky-600 font-bold"><i class="fa-solid fa-paper-plane"></i> เตือนแล้ว</span>' : ''}
                    ${r.pending !== null && !r.slip_sent ? `<span class="text-purple-700 font-bold"><i class="fa-solid fa-qrcode"></i> รอจ่าย ${rBaht(r.pending)}</span>` : ''}
                    ${r.renewed ? `<span class="text-gray-400">ต่อแล้ว ${r.renewed} ครั้ง</span>` : ''}
                    ${r.note ? `<span class="text-gray-400 break-all">· ${rEsc(r.note)}</span>` : ''}
                </div>
                ${r.slip_sent ? `<div class="flex flex-wrap items-center gap-1.5 bg-sky-50 border border-sky-200 rounded-xl p-2 text-[11px] text-sky-800">
                    <span class="font-bold"><i class="fa-solid fa-receipt mr-1"></i>ลูกค้าส่งสลิปแล้ว ${rBaht(r.pending)} — ดูผลตรวจของ Slip2Go ในแชท</span>
                    <button type="button" onclick='if(confirm("ยืนยันว่าสลิปผ่าน แล้วต่ออายุให้ลูกค้า?"))renewAction("renew_confirm_pay",{pay_id:${JSON.stringify(r.pay_id)}})' class="font-bold px-2 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700"><i class="fa-solid fa-check mr-0.5"></i>ยืนยันรับเงิน</button>
                    <button type="button" onclick='if(confirm("สลิปไม่ผ่าน? ระบบจะแจ้งลูกค้าทาง LINE ให้ทักแอดมิน"))renewAction("renew_reject_pay",{pay_id:${JSON.stringify(r.pay_id)},notify:"1"})' class="font-bold px-2 py-1 rounded-lg border border-rose-200 bg-white text-rose-600">สลิปไม่ผ่าน</button>
                </div>` : ''}
                <div class="flex flex-wrap gap-1.5 pt-0.5">
                    <button type="button" onclick='openRenewExtend(${JSON.stringify(r.id)})' class="text-[10px] font-bold px-2 py-1 rounded-lg bg-purple-600 text-white hover:bg-purple-700"><i class="fa-solid fa-rotate-right mr-0.5"></i>ต่อเอง</button>
                    ${r.line_linked && r.status === 'active' ? `<button type="button" onclick='renewAction("renew_remind",{id:${JSON.stringify(r.id)}})' class="text-[10px] font-bold px-2 py-1 rounded-lg border border-sky-200 bg-sky-50 text-sky-700"><i class="fa-brands fa-line mr-0.5"></i>ส่งเตือนตอนนี้</button>` : ''}
                    <button type="button" onclick='renewAction("renew_removed",{id:${JSON.stringify(r.id)},on:${r.removed ? '"0"' : '"1"'}})' class="text-[10px] font-bold px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-600">${r.removed ? 'ยกเลิก "ลบเมลแล้ว"' : 'ลบเมลแล้ว'}</button>
                    <button type="button" onclick='openRenewForm(${JSON.stringify(r.id)})' class="text-[10px] font-bold px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-600"><i class="fa-solid fa-pen mr-0.5"></i>แก้ไข</button>
                    ${r.line_linked ? `<button type="button" onclick='if(confirm("ยกเลิกการผูก LINE ของรายการนี้? (ลูกค้าต้องพิมพ์รหัสใหม่)"))renewAction("renew_unlink",{id:${JSON.stringify(r.id)}})' class="text-[10px] font-bold px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-500">ยกเลิกผูก LINE</button>` : ''}
                    <button type="button" onclick='renewAction("renew_cancel",{id:${JSON.stringify(r.id)},on:${r.status === 'active' ? '"1"' : '"0"'}})' class="text-[10px] font-bold px-2 py-1 rounded-lg border border-gray-200 bg-white text-gray-500">${r.status === 'active' ? 'หยุดต่อ' : 'เปิดอีกครั้ง'}</button>
                    <button type="button" onclick='if(confirm("ลบรายการนี้ถาวร?"))renewAction("renew_delete",{id:${JSON.stringify(r.id)}})' class="text-[10px] font-bold px-2 py-1 rounded-lg text-gray-400 hover:text-rose-600"><i class="fa-regular fa-trash-can"></i></button>
                </div>
                </div>
                <button type="button" onclick='openRenewPreview(${JSON.stringify(r.id)})' title="ตัวอย่างที่ระบบจะส่งให้ลูกค้า" class="flex-shrink-0 w-24 sm:w-32 self-start text-center group">
                    ${renewCardHtml(r, true)}
                    <span class="block text-[9px] font-bold text-purple-600 mt-1 group-hover:underline"><i class="fa-regular fa-eye mr-0.5"></i>ลูกค้าจะได้รับแบบนี้</span>
                </button>
            </div>`;
        }).join('');
    }

    // ---------- ตัวอย่างสิ่งที่ลูกค้าจะได้รับ (ตำแหน่งเดียวกับ renew_card_layout() / renew_pay_flex() ใน renew_lib.php) ----------
    const rThai = (ymd) => { const [y, m, d] = ymd.split('-').map(Number); return new Date(y, m - 1, d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }); };
    const rAddDays = (ymd, n) => { const [y, m, d] = ymd.split('-').map(Number); const x = new Date(y, m - 1, d + n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); };

    // การ์ดเตือน: r = {customer_email, expires_on} · ตัวอักษรย่อ/ขยายตามขนาดกล่อง (container query)
    function renewCardHtml(r, small = false, style = null, payBefore = null) {
        const s = RENEW.settings;
        style = style || s.card_style;
        payBefore = payBefore === null ? s.pay_before_days : payBefore;
        if (style !== 'image') {
            return `<div class="rounded-xl border border-gray-200 bg-white p-2 text-left text-[9px] text-gray-600 leading-snug">
                <b class="text-gray-900">⏰ ใกล้หมดอายุ</b><br>เมล: ${rEsc(r.customer_email)}<br>หมดอายุ ${rEsc(rThai(r.expires_on))}
                <span class="block mt-1 rounded bg-pink-500 text-white text-center font-bold py-0.5">ต่อแพ็กเกจ</span></div>`;
        }
        const f = (t, l, w, text) => `<div style="position:absolute;top:${t};left:${l};width:${w};height:5.6%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:3.3cqw;color:#5a2a2a;white-space:nowrap;overflow:hidden">${rEsc(text)}</div>`;
        return `<div class="relative rounded-xl overflow-hidden border border-gray-200 shadow-sm" style="container-type:inline-size">
            <img src="${rEsc(s.card_url)}" class="w-full block" alt="การ์ดเตือน" loading="lazy">
            ${f('27.3%', '23.2%', '56%', r.customer_email)}${f('37.3%', '39.6%', '36.8%', rThai(rAddDays(r.expires_on, -payBefore)))}${f('45.3%', '30.9%', '40%', rThai(r.expires_on))}
        </div>`;
    }

    function renewPayHtml() {
        const mode = RENEW.settings.pay_mode;
        const qr = '<div class="flex items-center justify-center bg-white" style="position:absolute;top:36.3%;left:62.3%;width:31.6%;height:31.6%;border-radius:6px"><i class="fa-solid fa-qrcode text-purple-500" style="font-size:20cqw"></i></div>';
        return mode !== 'qr'
            ? `<div class="relative rounded-xl overflow-hidden border border-gray-200 shadow-sm" style="container-type:inline-size"><img src="${rEsc(RENEW.settings.pay_url)}" class="w-full block" alt="วิธีชำระเงิน" loading="lazy">${mode === 'overlay' ? qr : ''}</div>`
            : '<div class="rounded-xl border border-gray-200 bg-white aspect-square flex items-center justify-center"><i class="fa-solid fa-qrcode text-6xl text-purple-500"></i></div>';
    }

    function openRenewPreview(id) {
        const r = RENEW.renewals.find(x => x.id === id);
        if (!r) return;
        const s = RENEW.settings;
        const pkg = RENEW.packages.find(p => p.id === r.package_id);
        const amount = pkg ? pkg.price + (r.fee || 0) : null;
        const remindOn = rThai(rAddDays(r.expires_on, -s.remind_days));
        const choices = RENEW.packages.filter(p => p.active).length;
        document.getElementById('renewInfoBody').innerHTML = `
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-extrabold text-gray-900"><i class="fa-brands fa-line text-emerald-500 mr-1"></i>สิ่งที่ลูกค้าจะได้รับทาง LINE</p>
                <button type="button" onclick="document.getElementById('renewInfoModal').classList.add('hidden')" class="w-8 h-8 rounded-lg text-gray-400 hover:bg-gray-100"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p class="text-[11px] text-gray-500 break-all">${rEsc(r.customer_email)} · ${r.line_linked ? 'ผูก LINE แล้ว (' + rEsc(r.line_name || '-') + ')' : '<b class="text-rose-600">ยังไม่ผูก LINE — ลูกค้าจะยังไม่ได้รับการ์ด</b>'}</p>
            <div class="space-y-1">
                <p class="text-[11px] font-bold text-gray-700">① การ์ดเตือน — ส่งอัตโนมัติวันที่ ${rEsc(remindOn)} (ก่อนหมด ${s.remind_days} วัน เวลา ${rEsc(s.remind_time || '09:00')} น.) หรือกด "ส่งเตือนตอนนี้"</p>
                <div class="max-w-[300px]">${renewCardHtml(r, false)}</div>
            </div>
            <div class="space-y-1">
                <p class="text-[11px] font-bold text-gray-700">② ลูกค้ากด "กดต่ออายุ" → ได้หน้าชำระเงินทันที (แพ็กเกจ ${rEsc(r.package)})${amount !== null ? ' ยอด ' + rBaht(amount) : ''}</p>
                <div class="max-w-[300px]">${renewPayHtml()}</div>
                <p class="text-[10px] text-gray-400">${s.pay_mode === 'static' ? 'ส่งรูปนี้ตามเดิม' : 'ช่อง QR จะเป็น QR พร้อมเพย์ยอดพอดีของรายการนี้'} ตามด้วยข้อความบอกยอดโอน${amount !== null ? ' ' + rBaht(amount) : ''}${r.fee ? ' (รวมค่าต่อช้า ' + rBaht(r.fee) + ')' : ''}</p>
            </div>
            <div class="space-y-1 text-[11px] text-gray-600">
                <p class="font-bold text-gray-700">③ ลูกค้าส่งสลิปในแชท</p>
                <p>${s.slip_mode === 'api' ? 'ระบบตรวจสลิปผ่าน Slip2Go API แล้วต่ออายุ + แจ้งลูกค้าให้เอง' : 'Slip2Go ในแชทตรวจสลิป → แอดมินกด "ยืนยันรับเงิน" ในหน้านี้ → ระบบต่ออายุ + แจ้งลูกค้า'}</p>
            </div>`;
        document.getElementById('renewInfoModal').classList.remove('hidden');
    }

    function renderRenewFams() {
        const size = RENEW.settings.fam_size;
        const fams = RENEW.fams;
        const free = fams.reduce((n, f) => n + Math.max(0, size - f.members.length), 0);
        document.getElementById('renewFamSummary').innerText = fams.length ? `${fams.length} เมล · ว่างรวม ${free} ที่` : '';
        document.getElementById('renewFams').innerHTML = fams.length ? fams.map(f => {
            const used = f.members.length;
            const cls = used > size ? 'bg-rose-50 text-rose-700 border-rose-200' : used >= size ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200';
            return `<div class="border border-gray-200 rounded-xl px-3 py-2 flex items-start gap-2">
                <details class="flex-1 min-w-0"><summary class="flex justify-between gap-2 cursor-pointer text-xs"><span class="font-bold break-all">${rEsc(f.email)}</span><span class="flex-shrink-0 text-[10px] font-bold border rounded-lg px-2 ${cls}">${used}/${size}</span></summary>
                <div class="text-[11px] text-gray-500 pt-1.5 break-all">${used ? f.members.map(rEsc).join('<br>') : 'ยังไม่มีลูกค้า'}</div></details>
                <button type="button" title="ลบเมลร้านนี้" onclick='deleteRenewFam(${JSON.stringify(f.email)}, ${used})' class="flex-shrink-0 w-6 h-6 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 flex items-center justify-center"><i class="fa-regular fa-trash-can text-xs"></i></button>
            </div>`;
        }).join('') : '<p class="text-xs text-gray-400">ยังไม่มี — กด "เพิ่มเมลร้าน" หรือใส่ตอนเพิ่มลูกค้า</p>';
    }

    function addRenewFam() {
        const email = (prompt('เมลร้าน (fam) ที่จะเพิ่ม เช่น family01@gmail.com') || '').trim();
        if (email) renewAction('renew_fam_add', { email });
    }

    function deleteRenewFam(email, used) {
        const msg = used
            ? `ลบเมลร้าน ${email}?\n\nมีลูกค้าอยู่ ${used} คน — รายการลูกค้าไม่ถูกลบ แต่จะเปลี่ยนเป็น "ไม่ระบุ fam"`
            : `ลบเมลร้าน ${email}?`;
        if (confirm(msg)) renewAction('renew_fam_delete', { email });
    }

    function renderRenewPkgs() {
        document.getElementById('renewPkgs').innerHTML = RENEW.packages.length ? RENEW.packages.map(p => `
            <div class="flex items-center justify-between gap-2 border ${p.active ? 'border-gray-200' : 'border-dashed border-gray-200 opacity-60'} rounded-xl px-3 py-2">
                <div class="min-w-0 text-xs"><b class="text-gray-900">${rEsc(p.name)}</b> <span class="text-gray-500">· ${rBaht(p.price)} · ${p.days} วัน${p.active ? '' : ' · ปิดอยู่'}</span></div>
                <div class="flex gap-1 flex-shrink-0">
                    <button type="button" onclick='editRenewPkg(${JSON.stringify(p.id)})' class="text-[10px] font-bold px-2 py-1 rounded-lg border border-gray-200 text-gray-600">แก้ไข</button>
                    <button type="button" onclick='if(confirm("ลบแพ็กเกจนี้?"))renewAction("renew_pkg_delete",{id:${JSON.stringify(p.id)}})' class="text-[10px] px-2 py-1 rounded-lg text-gray-400 hover:text-rose-600"><i class="fa-regular fa-trash-can"></i></button>
                </div>
            </div>`).join('') : '<p class="text-xs text-gray-400">ยังไม่มีแพ็กเกจ เช่น "YouTube Premium 1 เดือน" ราคา 59 บาท 30 วัน</p>';
    }

    // ---------- ฟอร์มเพิ่ม/แก้ไข ----------
    function openRenewForm(id) {
        if (!RENEW) return;
        if (!RENEW.packages.length) { alert('เพิ่มแพ็กเกจก่อน (ส่วน "แพ็กเกจต่อเมล" ด้านล่าง)'); return; }
        const r = id ? RENEW.renewals.find(x => x.id === id) : null;
        const size = RENEW.settings.fam_size;
        document.getElementById('renewFormTitle').innerText = r ? 'แก้ไขลูกค้าต่อเมล' : 'เพิ่มลูกค้าต่อเมล';
        document.getElementById('rfId').value = r ? r.id : '';
        document.getElementById('rfEmail').value = r ? r.customer_email : '';
        document.getElementById('rfNote').value = r ? r.note : '';
        document.getElementById('rfPkg').innerHTML = RENEW.packages.filter(p => p.active || (r && p.id === r.package_id))
            .map(p => `<option value="${rEsc(p.id)}" data-days="${p.days}" ${r && p.id === r.package_id ? 'selected' : ''}>${rEsc(p.name)} · ${rBaht(p.price)} · ${p.days} วัน</option>`).join('');
        // เลือก fam: แนะนำเมลที่มีที่ว่างและใส่ไปมากสุด (เติมให้เต็มทีละเมล)
        const cur = r ? r.shop_email.toLowerCase() : '';
        const fams = RENEW.fams.map(f => ({ email: f.email.toLowerCase(), used: f.members.length - (f.email.toLowerCase() === cur ? 1 : 0) }));
        const auto = r ? cur : ([...fams].filter(f => f.used < size).sort((a, b) => b.used - a.used)[0]?.email || '');
        const sel = document.getElementById('rfFamSelect');
        sel.innerHTML = '<option value="">— ไม่ระบุ —</option>' + fams.map(f => {
            const full = f.used >= size;
            return `<option value="${rEsc(f.email)}" ${full && f.email !== cur ? 'disabled' : ''}>${rEsc(f.email)} · ${f.used}/${size}${full ? ' เต็ม' : ` (ว่าง ${size - f.used})`}</option>`;
        }).join('') + '<option value="__new">+ เมลร้านใหม่…</option>';
        const known = fams.some(f => f.email === auto);
        sel.value = auto && !known ? '__new' : auto;
        document.getElementById('rfFam').value = auto && !known ? auto : '';
        onRenewFamSelect();
        const exp = document.getElementById('rfExp');
        exp.value = r ? r.expires_on : '';
        document.getElementById('rfExpQuick').innerHTML = [['วันนี้ + แพ็กเกจที่เลือก', -1], ['+7 วัน', 7], ['+30 วัน', 30], ['+90 วัน', 90]].map(([l, d]) =>
            `<button type="button" class="text-[10px] font-bold px-2 py-0.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50" onclick="renewQuickExp(${d})">${l}</button>`).join('');
        document.getElementById('renewFormModal').classList.remove('hidden');
    }

    // ตั้งวันหมดอายุ = วันนี้ + N วัน (-1 = ตามจำนวนวันของแพ็กเกจที่เลือก)
    function renewQuickExp(days) {
        if (days < 0) days = +(document.getElementById('rfPkg').selectedOptions[0]?.dataset.days || 30);
        const [y, m, d] = RENEW.today.split('-').map(Number);
        const x = new Date(y, m - 1, d + days);
        document.getElementById('rfExp').value = x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
    }

    function onRenewFamSelect() {
        const isNew = document.getElementById('rfFamSelect').value === '__new';
        document.getElementById('rfFam').classList.toggle('hidden', !isNew);
    }

    async function saveRenewForm() {
        const famSel = document.getElementById('rfFamSelect').value;
        const j = await renewAction('renew_save', {
            id: document.getElementById('rfId').value,
            customer_email: document.getElementById('rfEmail').value.trim(),
            shop_email: famSel === '__new' ? document.getElementById('rfFam').value.trim() : famSel,
            package_id: document.getElementById('rfPkg').value,
            expires_on: document.getElementById('rfExp').value,
            note: document.getElementById('rfNote').value.trim(),
        });
        if (!j) return;
        document.getElementById('renewFormModal').classList.add('hidden');
        if (j.link_code) {
            const r = RENEW.renewals.find(x => x.link_code === j.link_code);
            if (r) showRenewCode(r.id);
        }
    }

    function showRenewCode(id) {
        const r = RENEW.renewals.find(x => x.id === id);
        if (!r) return;
        const msg = renewCodeMsg(r);
        document.getElementById('renewInfoBody').innerHTML = `
            <p class="text-sm font-extrabold text-gray-900"><i class="fa-brands fa-line text-emerald-500 mr-1"></i>ส่งรหัสผูกแชทให้ลูกค้า</p>
            <p class="text-[11px] text-gray-500">ส่งข้อความนี้ให้ <b>${rEsc(r.customer_email)}</b> ทางแชท LINE ร้าน — ลูกค้าพิมพ์รหัสตอบกลับ ระบบจะผูกบัญชีและเตือนก่อนหมดอายุให้เอง</p>
            <div class="text-center text-3xl font-black tracking-widest text-purple-700 py-2">${rEsc(r.link_code)}</div>
            <textarea readonly class="w-full h-28 px-3 py-2 rounded-xl border border-gray-200 text-xs bg-gray-50">${rEsc(msg)}</textarea>
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('renewInfoModal').classList.add('hidden')" class="flex-1 py-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ปิด</button>
                <button type="button" onclick='renewCopy(${JSON.stringify(msg)})' class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl"><i class="fa-regular fa-copy mr-1"></i>คัดลอกข้อความ</button>
            </div>`;
        document.getElementById('renewInfoModal').classList.remove('hidden');
    }

    function openRenewExtend(id) {
        const r = RENEW.renewals.find(x => x.id === id);
        if (!r) return;
        const opts = RENEW.packages.filter(p => p.active || p.id === r.package_id)
            .map(p => `<option value="${rEsc(p.id)}" ${p.id === r.package_id ? 'selected' : ''}>${rEsc(p.name)} · ${rBaht(p.price)} · +${p.days} วัน</option>`).join('');
        document.getElementById('renewInfoBody').innerHTML = `
            <p class="text-sm font-extrabold text-gray-900">ต่ออายุเอง</p>
            <p class="text-[11px] text-gray-500 break-all">${rEsc(r.customer_email)} · หมด ${rEsc(r.expires_th)}<br>ใช้เมื่อลูกค้าโอนนอกระบบ หรือแอดมินตรวจสลิปเอง (นับต่อจากวันหมดอายุ หรือวันนี้ถ้าหมดไปแล้ว)</p>
            <select id="rxPkg" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white">${opts}</select>
            ${r.line_linked ? '<label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" id="rxNotify" checked class="accent-purple-600"> แจ้งลูกค้าทาง LINE ว่าต่อแล้ว</label>' : ''}
            ${r.removed ? '<p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-xl p-2">รายการนี้ถูกลบเมลออกแล้ว — ต่อแล้วอย่าลืมเชิญเมลกลับเข้า fam</p>' : ''}
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('renewInfoModal').classList.add('hidden')" class="flex-1 py-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
                <button type="button" onclick='doRenewExtend(${JSON.stringify(r.id)})' class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl">ต่ออายุ</button>
            </div>`;
        document.getElementById('renewInfoModal').classList.remove('hidden');
    }

    async function doRenewExtend(id) {
        const n = document.getElementById('rxNotify');
        const j = await renewAction('renew_extend', { id, package_id: document.getElementById('rxPkg').value, notify: n && n.checked ? '1' : '0' });
        if (j) document.getElementById('renewInfoModal').classList.add('hidden');
    }

    function editRenewPkg(id) {
        const p = id ? RENEW.packages.find(x => x.id === id) : { id: '', name: '', price: '', days: 30, active: true };
        document.getElementById('renewInfoBody').innerHTML = `
            <p class="text-sm font-extrabold text-gray-900">${id ? 'แก้ไขแพ็กเกจ' : 'เพิ่มแพ็กเกจ'}</p>
            <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">ชื่อแพ็กเกจ (แสดงบนปุ่มใน LINE ควรสั้น)</span>
                <input id="rpName" maxlength="120" value="${rEsc(p.name)}" placeholder="เช่น YouTube 1 เดือน" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none"></label>
            <div class="grid grid-cols-2 gap-2">
                <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">ราคา (บาท)</span><input id="rpPrice" type="number" min="1" step="0.01" value="${rEsc(p.price)}" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none"></label>
                <label class="block space-y-1"><span class="text-[11px] font-bold text-gray-600">ต่อได้กี่วัน</span><input id="rpDays" type="number" min="1" value="${rEsc(p.days)}" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm outline-none"></label>
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" id="rpActive" ${p.active ? 'checked' : ''} class="accent-purple-600"> เปิดให้ลูกค้าเลือก</label>
            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('renewInfoModal').classList.add('hidden')" class="flex-1 py-2.5 text-xs font-semibold text-gray-600 hover:bg-gray-100 rounded-xl">ยกเลิก</button>
                <button type="button" onclick='saveRenewPkg(${JSON.stringify(p.id)})' class="flex-1 py-2.5 bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs rounded-xl">บันทึก</button>
            </div>`;
        document.getElementById('renewInfoModal').classList.remove('hidden');
    }

    async function saveRenewPkg(id) {
        const j = await renewAction('renew_pkg_save', {
            id, name: document.getElementById('rpName').value.trim(), price: document.getElementById('rpPrice').value,
            days: document.getElementById('rpDays').value, active: document.getElementById('rpActive').checked ? '1' : '0',
        });
        if (j) document.getElementById('renewInfoModal').classList.add('hidden');
    }

    // ตัวอย่างการ์ด (ตำแหน่งเดียวกับ renew_card_layout() ใน renew_lib.php)
    function renderRenewCardPreview() {
        const box = document.getElementById('renewCardPreview');
        if (!RENEW || !box) return;
        if (document.getElementById('rsCardStyle').value !== 'image') {
            box.innerHTML = '<div class="p-4 text-[11px] text-gray-500">การ์ดข้อความ: ชื่อแพ็กเกจ, fam, เมล, วันหมดอายุ, เงื่อนไขค่าต่อช้า และปุ่มแพ็กเกจ (สูงสุด 4 ปุ่ม)</div>';
            return;
        }
        const before = Math.max(0, +document.getElementById('rsPayBefore').value || 0);
        box.innerHTML = renewCardHtml({ customer_email: 'customer.email@gmail.com', expires_on: rAddDays(RENEW.today, 3) }, false, 'image', before);
    }

    function renderRenewPayPreview() {
        const box = document.getElementById('renewPayPreview');
        if (!RENEW || !box) return;
        const qrBox = '<div class="flex items-center justify-center bg-white text-gray-400 text-[10px] font-bold" style="position:absolute;top:36.3%;left:62.3%;width:31.6%;height:31.6%;border-radius:6px;outline:2px dashed #a855f7"><span><i class="fa-solid fa-qrcode text-2xl block text-center text-purple-500"></i>QR ยอดพอดี</span></div>';
        const v = document.getElementById('rsPayStyle').value;
        box.innerHTML = v === 'qr'
            ? '<div class="p-4 text-[11px] text-gray-500">ส่งรูป QR พร้อมเพย์ยอดพอดี + ข้อความบอกยอด</div>'
            : `<img src="${rEsc(RENEW.settings.pay_url)}" class="w-full block" alt="วิธีชำระเงิน">` + (v === 'image' ? qrBox : '');
    }

    async function uploadRenewCard(input, k = 'card') {
        const file = input.files[0];
        if (!file) return;
        if (file.size > 1536 * 1024) { alert('ไฟล์ใหญ่เกิน 1.5MB'); input.value = ''; return; }
        const fd = new FormData(); fd.append('card', file); fd.append('k', k);
        try {
            const j = await (await fetch('admin.php?action=renew_card_upload', { method: 'POST', body: fd })).json();
            if (!j.success) alert(j.message || 'อัปโหลดไม่สำเร็จ'); else { showNotification(j.message); await loadRenew(); }
        } catch (e) { alert('เกิดข้อผิดพลาดเครือข่าย'); }
        input.value = '';
    }

    async function saveRenewSettings() {
        const v = (id) => document.getElementById(id).value.trim();
        const j = await renewAction('renew_settings_save', {
            pay_style: v('rsPayStyle'), pay_text: document.getElementById('rsPayText').checked ? '1' : '0', card_style: v('rsCardStyle'), pay_before_days: v('rsPayBefore'), slip_mode: v('rsSlipMode'), line_token: v('rsLineToken'), line_secret: v('rsLineSecret'), line_oa_id: v('rsOaId'), forward_webhook: v('rsForward'),
            promptpay_id: v('rsPromptpay'), pay_name: v('rsPayName'), slip2go_url: v('rsSlipUrl'), slip2go_secret: v('rsSlipSecret'),
            receiver_type: v('rsRecvType'), receiver_account: v('rsRecvAcc'), amount_extra: v('rsAmountExtra'),
            remind_time: v('rsRemindTime') || '09:00', remind_days: v('rsRemind'), grace_days: v('rsGrace'), late_fee: v('rsLate'), removed_fee: v('rsRemoved'), fam_size: v('rsFamSize'),
        });
        if (j) ['rsLineToken', 'rsLineSecret', 'rsSlipSecret'].forEach(id => document.getElementById(id).value = '');
    }
</script>
