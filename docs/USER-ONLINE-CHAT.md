# แชทจาก User Online

เพิ่มกล่องงานสำหรับตอบย้อนหลังและปิดงานแล้ว ดู [USER-ONLINE-INBOX.md](USER-ONLINE-INBOX.md) งานทีมและแชทส่วนตัวที่ติดตามเก็บประวัติแยกจาก retention ห้องรวมด้านล่าง

เปิด local ที่ https://dev-eis.nmu.ac.th/eis-docs/docMain/user-online ด้วย Admin ของ EIS-DOCS

- ปุ่ม **เปิดห้องรวม / ส่งประกาศ**: ส่งข้อความถึงห้องรวมของ EIS-DOCS และ Supplies ผู้ใช้ในห้องรวมตอบกลับได้
- **แชทส่วนตัว** ที่แถวผู้ใช้ออนไลน์: คุยระหว่าง Admin ที่เลือกกับบัญชีเป้าหมายของระบบนั้น ผู้ใช้คนอื่นและ Admin คนอื่นไม่เห็นห้องส่วนตัว
- Admin เห็น path หน้าที่เปิดอยู่ เช่น `/supplies/sp/dashBard.php` ไม่ส่ง query string หรือเนื้อหาหน้าจออัตโนมัติ
- เมื่อได้รับข้อความ ผู้ใช้เห็น dialog สนทนา มีปุ่มย่อและปุ่มสนทนาพร้อมจำนวนที่ยังไม่อ่าน อ่านเมื่อเปิดบทสนทนาและแท็บอยู่ด้านหน้า
- กด **แนบรูป** เลือก PNG/JPEG/WebP หรือ **แชร์ภาพหน้าจอ** เลือกแท็บ/หน้าต่าง/จอจากตัวเลือกของ browser ภาพแสดง preview ก่อนส่ง ผู้ดูแลขอภาพได้ด้วย **ขอภาพหน้าจอ** ในห้องส่วนตัว
- แชร์เป็นภาพนิ่งเท่านั้น หลังจับภาพหยุด stream ทันที ไม่เก็บภาพหรือดูจอโดยผู้ใช้ไม่กดแชร์ ต้องใช้ HTTPS และ browser ที่รองรับ getDisplayMedia; ใช้แนบรูปแทนได้

รูปต้นทางไม่เกิน 10 MB ฝั่ง browser ย่อรูปเป็น JPEG ก่อนส่ง รูปที่ส่งจริงไม่เกิน 256 KB ข้อความไม่เกิน 2,000 ตัวอักษร จำกัด 8 ข้อความต่อ 10 วินาทีต่อ connection พร้อมตรวจ session/revocation ทุกคำขอ ไม่ใช้ชื่อ/สิทธิ์ sender ที่ client ส่งมา

หลังส่ง แสดงจำนวนบัญชีออนไลน์ที่ได้รับการส่งผ่าน WebSocket และชื่อผู้ที่เปิดอ่าน จำนวนส่งถึงหมายถึง server ส่งเข้าการเชื่อมต่อ ไม่ใช่การยืนยันว่า browser แสดงครบแล้ว ผู้รับที่ยังไม่ออนไลน์เปิดดูประวัติที่มีสิทธิ์เมื่อกลับมาเชื่อมต่อได้

เก็บข้อความ/รูป/สถานะอ่านที่ private `storage/presence/chat.json` ร่วมกับ Node service แสดงย้อนหลังล่าสุด 40 ข้อความที่บัญชีมีสิทธิ์ เก็บสูงสุด 500 ข้อความและข้อมูลข้อความประมาณ 16 MB ทิ้งข้อความเก่าที่เกิน 30 วันเมื่อมีข้อความใหม่ incoming รุ่นนี้ไม่ใช่คลังเอกสารระยะยาว ไม่มีการลบหรือส่งออกข้อความผ่าน UI

มีบันทึกก่อนตอบรับการส่ง, ป้องกันข้อความซ้ำเมื่อ retry ด้วย clientId เดิม, ไฟล์ไม่เปิดผ่าน public URL ภาพใช้ data URI เฉพาะผู้มีสิทธิ์ในห้อง อย่าคัดลอก chat.json ของ dev ไป production

## ไฟล์เพิ่มเติมสำหรับ production

EIS-DOCS:

```text
app/src/Presence.php
app/public/assets/js/presence-client.js
app/public/assets/js/presence-chat.js
app/public/assets/css/presence-chat.css
app/public/docMain/user-online.php
app/public/docMain/user-online.js
```

Supplies (helper และ assets ต้องใช้รุ่นเดียวกับ Docs):

```text
conf/presence/Presence.php
assets/js/presence-client.js
assets/js/presence-chat.js
assets/css/presence-chat.css
```

Node service:

```text
server.js
chat.js
package.json
```

ใช้ secrets/storage/proxy/config ชุดเดิมใน PRESENCE-PRODUCTION-DEPLOY.md ไม่ต้องเพิ่มฐานข้อมูล SQL หากมี CSP ต้องอนุญาตภาพ `img-src data:` รวมถึง connect-src WebSocket ที่ตั้งไว้ ต้องให้ Node เขียน chat.json และไฟล์ .new ได้

## ตรวจสอบ

```powershell
npm.cmd test --prefix D:/EIS-DOCS/app/services/presence
node D:/EIS-DOCS/app/services/presence/test-chat-websocket.js
node D:/EIS-DOCS/app/services/presence/integration-local.js
```

ตรวจสิทธิ์สองคนแยก UID ตามระบบ, ไม่ให้ Admin อื่นอ่านส่วนตัว, ห้องรวม/ตอบกลับ, รูปและ read receipt, reconnect/unread, session ที่ถูกแทนที่, persistence failure และ UI dialog ฝั่งผู้ใช้/ผู้ดูแลด้วย Chrome headless แล้ว การเลือกจอจริงต้องกดผ่าน browser ของผู้ใช้
