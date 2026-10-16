# รายการไฟล์นำขึ้น Production EIS DOCS NMU permission และ Supplies

ชุดไฟล์วันที่ 10 ตุลาคม 2026 รวม User Online แยก EIS-DOCS และ Supplies, Admin หลายชื่อ, การยืนยันล็อกอินซ้ำ, แชทและแนบภาพ, ฝากเรื่องขณะ Admin ออฟไลน์, ตอบย้อนหลังและปิดงาน, ตอบหลายงาน, ยกเลิกข้อความ และดูรูปเต็มจอพร้อมปรับขนาด

สถานะ ตรวจชุดไฟล์ตรงกับ source local แล้ว ยังไม่ได้ติดตั้งบน Production ชุดนี้เป็นไฟล์ปรับปรุงสำหรับระบบเดิม ไม่ใช่โครงการใหม่ทั้งหมด

## ไฟล์และตำแหน่งติดตั้ง

นำไฟล์ภายในแต่ละกลุ่มไปยัง root ของ application ที่ตรงกัน คง relative path ไว้ ไม่สร้างโฟลเดอร์ชื่อ EIS-DOCS หรือ Supplies ซ้อนเพิ่มใน application

### EIS-DOCS

ปลายทาง https://doc-eis.nmu.ac.th/eis-docs/login

ตำแหน่ง root โครงการ EIS-DOCS โดยคง app/src, app/config และ app/public ตามเดิม IIS application /eis-docs ชี้ app/public

จำนวน 32 ไฟล์

```text
app/config/database.php
app/config/profiles/local.php
app/config/profiles/production.php
app/config/runtime.php
app/public/assets/css/presence-chat.css
app/public/assets/js/presence-chat.js
app/public/assets/js/presence-client.js
app/public/assets/js/presence-image-viewer.js
app/public/assets/js/xlsx-js-style.min.js
app/public/docMain/admin-documents.php
app/public/docMain/admin-pin-requests.php
app/public/docMain/department-config.php
app/public/docMain/impersonate.php
app/public/docMain/index.html
app/public/docMain/index.php
app/public/docMain/message-inbox.css
app/public/docMain/message-inbox.js
app/public/docMain/message-inbox.php
app/public/docMain/message-report.js
app/public/docMain/message-report.php
app/public/docMain/presence-token.php
app/public/docMain/user-online.css
app/public/docMain/user-online.js
app/public/docMain/user-online.php
app/public/login.php
app/src/Auth.php
app/src/Database.php
app/src/Presence.php
app/src/SingleLogin.php
app/src/SuppliesLogin.php
app/src/bootstrap.php
app/src/presence.config.example.php
```

### NMU_permission

ปลายทาง https://eis.nmu.ac.th/NMU_permission/entrance

ตำแหน่ง root application /NMU_permission

จำนวน 4 ไฟล์

```text
entrance.php
index.php
launch.php
lib/database/DatabaseServer.php
```

### Supplies

ปลายทาง https://eis.nmu.ac.th/supplies/

ตำแหน่ง root application /supplies

จำนวน 20 ไฟล์

```text
access/confirm-login.php
access/impersonate.php
access/js/signin.js
access/login.php
access/login_1.php
access/login_2.php
access/login_3.php
access/logout.php
access/presence-token.php
assets/css/admin-login.css
assets/css/presence-chat.css
assets/js/presence-chat.js
assets/js/presence-client.js
assets/js/presence-image-viewer.js
conf/presence/Presence.php
conf/presence/SingleLogin.php
conf/presence/SuppliesLogin.php
conf/presence/presence.config.example.php
index.php
lib/database/DatabaseServer.php
```

### WebSocket-Service

ปลายทาง wss://doc-eis.nmu.ac.th/eis-docs/presence

ตำแหน่ง โฟลเดอร์บริการนอก webroot เช่น D:/EIS-Presence/service

จำนวน 10 ไฟล์

```text
chat.js
iis-presence-rule.xml
install-production-task.ps1
package-lock.json
package.json
report.js
server.config.example.json
server.js
setup-production.ps1
store.js
```

### Procure

ปลายทาง ใช้เฉพาะเมื่อต้องการเข้า Procure จาก NMU_permission

ตำแหน่ง root application /procure

จำนวน 3 ไฟล์

```text
access/login_1.php
conf/PortalSession.php
lib/database/DatabaseServer.php
```

## Config ที่ต้อง merge และสร้างบน Production

### EIS DOCS

- ตรวจ app/config/profiles/production.php ให้ตรงฐานข้อมูลจริง หรือกำหนด APP_ENV=production, DB_HOST, DB_PORT, DB_USER, DB_PASSWORD ตามเครื่องจริง เก็บ credentials เดิมไว้ หากใช้ไฟล์ credentials รุ่นนี้อ่าน app/config/database.production.local.php ซึ่งไม่อยู่ใน ZIP
- การแก้ ODBC Driver 18 แบบ default อยู่ใน local profile เท่านั้น หาก Production ต้องระบุไดรเวอร์ ให้กำหนด DB_DRIVER เป็นชื่อไดรเวอร์ที่ติดตั้งจริง ตรวจ Encrypt และ TrustServerCertificate ตาม config ของ Production
- สร้าง app/src/presence.config.php จาก presence.config.example.php

### Supplies

conf/config.php ไม่อยู่ใน ZIP ให้ merge ส่วนต่อไปนี้จาก local เข้า config Production โดยเก็บค่าฐานข้อมูล API และ log path เดิมไว้

- session_name เป็น NMU_SUPPLIES_SESSID; session directory ใช้ EIS_SUPPLIES_SESSION_DIR; idle timeout 7200 วินาที; cookie secure/httponly/samesite
- หลังเริ่ม session ให้โหลด conf/presence/SuppliesLogin.php และเรียก SuppliesLogin::validate() พร้อมตอบ SESSION_REPLACED สำหรับ session ที่ถูกแทนที่
- background presence token ต้องไม่อัปเดต last_activity; ใช้ presencePassiveRequest เพื่อแยกจากการใช้งานจริง
- STATUS_SERVER และ HTTPS_HOST_NAME ต้องเป็น eis.nmu.ac.th บน Production; API ใช้ EIS_SUPPLIES_API_HOST หรือโดเมน Production เดิม ไม่ชี้ dev-eis
- กำหนด EIS_PERMISSION_SESSION_DIR ให้ตรง session directory จริงของ NMU_permission เพื่อให้ Supplies ตรวจ SSO ได้ ค่า default เป็น path local XAMPP
- สร้าง conf/presence/presence.config.php จากตัวอย่าง

### NMU permission

conf/config.php ไม่อยู่ใน ZIP ให้ merge การเลือก HTTPS_HOST_NAME เป็น eis.nmu.ac.th บน Production และ NMU_PROCURE_PORT เป็นค่าว่าง โดยคง session directory ฐานข้อมูล และค่าระบบเดิม หากไม่ติดตั้งกลุ่ม Procure อย่าเปิดทางเข้า Procure ใหม่ที่พึ่งพา portal_entry

### Shared Presence config

PHP ทั้ง EIS-DOCS และ Supplies ต้องตั้งค่าตรงกับ Node เช่น

```php
<?php
return [
    'enabled' => true,
    'directory' => 'D:/EIS-Presence/storage',
    'websocket_url' => 'wss://doc-eis.nmu.ac.th/eis-docs/presence',
];
```

directory ต้องเป็น private storage เดียวกันจริงสำหรับ PHP ทั้งสองระบบและ Node หากอยู่คนละเครื่องต้องใช้ shared filesystem ที่รองรับ locking และ atomic rename พร้อม service account ที่เข้าถึงได้ การตั้งชื่อ path ให้เหมือนกันอย่างเดียวไม่เพียงพอ

Node สร้าง server.config.json จาก server.config.example.json ใช้ transport=http, port=4003, directory เดียวกัน และ origins เฉพาะ https://doc-eis.nmu.ac.th กับ https://eis.nmu.ac.th

## IIS และบริการ WebSocket

บน doc-eis เปิด ARR proxy, URL Rewrite และ WebSocket Protocol แล้ว merge iis-presence-rule.xml ใน application /eis-docs ก่อน generic rewrite rule เพื่อให้ /eis-docs/presence ส่งต่อไป http://127.0.0.1:4003/presence ไม่แทน web.config ทั้งไฟล์

ถ้าใช้ CSP ต้องรองรับ connect-src wss://doc-eis.nmu.ac.th และ img-src data: สำหรับรูปแนบ CSS/JS viewer โหลดจาก application ของแต่ละโดเมน

ติดตั้ง dependency ตาม package-lock.json ด้วย setup-production.ps1 และตั้ง task/service ให้เริ่มตอน boot ตรวจ service identity และ ACL ของ storage ให้ PHP อ่าน secret และเขียน logins/revoked ได้ ส่วน Node เขียน history/chat ได้ ตัวอย่าง task ใช้ SYSTEM หากใช้ network share ต้องตั้ง account ที่เข้าถึง share ได้จริง

Browser ใช้ WSS ผ่าน 443 ตาม URL Production ไม่ใช้ dev port 4003 ส่วน Node port 4003 รับเฉพาะ loopback

## ลำดับติดตั้ง

1. สำรองไฟล์ application เดิม config และ private storage โดยเฉพาะ chat.json และ history.json
2. ตรวจ diff ไฟล์เดิมของ Production ก่อนแทนที่ แล้วอัปโหลดไฟล์ในกลุ่ม EIS-DOCS NMU_permission และ Supplies หากใช้ Procure ให้นำกลุ่มนั้นขึ้นด้วย
3. Merge config Production และตั้ง session directory, database และ Presence config
4. ติดตั้ง WebSocket-Service นอก webroot สร้าง secret Production เฉพาะเมื่อยังไม่มี คง secret เดิมเมื่ออัปเกรด ตั้ง server.config.json และ ACL
5. Merge IIS rewrite เริ่มหรือ restart Node service และตรวจ http://127.0.0.1:4003/health ได้ status ok
6. ทดสอบหน้า login จริง ตรวจ WebSocket ได้ HTTP 101, ทดลอง admin-eak กับ admin-moo พร้อมกัน และชื่อซ้ำต้องยืนยันเฉพาะชื่อนั้น
7. ตรวจทางเข้าจาก NMU_permission ไป Supplies ตรวจแชท ฝากเรื่อง ตอบย้อนหลัง ปิดงาน ตอบหลายงาน ยกเลิกข้อความ และรูปเต็มจอ ทั้งผู้ใช้ออนไลน์และออฟไลน์
8. ให้ผู้ใช้กด Ctrl+F5 และล็อกอินใหม่เพื่อระบุชื่อ Admin

## ไฟล์ที่ไม่คัดลอกจาก dev

ไม่คัดลอก database.local.php, database.production.local.php, config ที่มีรหัสผ่าน, secret, certificate/key, session, history/chat ของ dev, node_modules หรือ Startup/watch-local ของเครื่อง ASUS ไป Production ZIP นี้ไม่รวมไฟล์เหล่านั้น ไม่ต้องเปลี่ยน schema SQL สำหรับ feature นี้

manifest.csv ระบุ SHA256 ของไฟล์ใน ZIP สำหรับตรวจความครบถ้วน รายละเอียด reverse proxy และคำสั่งติดตั้งอยู่ใน DEPLOY.md ส่วนคู่มือแยกอยู่ใน ADMIN-MULTI-OPERATOR.md, USER-ONLINE-CHAT.md, USER-ONLINE-INBOX.md, MESSENGER-RETRACT.md, MESSENGER-IMAGE-VIEWER.md และ LOGIN-DATABASE-FIX.md


รายงานรายเดือนและ Excel: อ่าน MESSENGER-MONTHLY-REPORT.md อัปโหลดไฟล์ใหม่ของหน้า UI, ไลบรารี Excel และ WebSocket-Service/report.js พร้อมไฟล์ปรับปรุง แล้วรีสตาร์ต WebSocket service
