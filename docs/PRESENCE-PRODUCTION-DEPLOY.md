# ไฟล์และขั้นตอนนำ User Online / ยืนยันล็อกอินซ้ำขึ้น production

ปรับโค้ดวันที่ 10 ตุลาคม 2026 รองรับทั้ง dev และ production แต่ยังไม่ได้ติดตั้งบน production จริง

เพิ่มแชท/ประกาศ/แนบภาพหน้าจอจาก User Online แล้ว ดู [USER-ONLINE-CHAT.md](USER-ONLINE-CHAT.md) สำหรับการใช้งาน ไฟล์เพิ่มเติม และข้อจำกัดการเก็บข้อมูล

เพิ่มกล่องงานตอบย้อนหลัง/ตอบหลายผู้ใช้/ปิดงานแล้ว ดู [USER-ONLINE-INBOX.md](USER-ONLINE-INBOX.md) และ backup chat.json ก่อนอัปเกรด Node บน production

## URL

| รายการ | dev | production |
|---|---|---|
| EIS-DOCS | https://dev-eis.nmu.ac.th/eis-docs/docMain/ | https://doc-eis.nmu.ac.th/eis-docs/docMain/ |
| Supplies | https://dev-eis.nmu.ac.th/supplies/ | https://eis.nmu.ac.th/supplies/ |
| Admin User Online | `/eis-docs/docMain/user-online` บน dev | `/eis-docs/docMain/user-online` บน doc-eis |
| WebSocket | wss://dev-eis.nmu.ac.th:4003/presence | wss://doc-eis.nmu.ac.th/eis-docs/presence |

Supply token/API และ JavaScript ใช้ URL ของ Supplies เอง ไม่ต้องมี EIS-DOCS ติดตั้งบนโดเมน eis.nmu.ac.th เมนู Admin อยู่ใน EIS-DOCS สำหรับผู้ดูแลประเภท 2 และไม่อยู่ระหว่างสวมสิทธิ์

## 1. ไฟล์ EIS-DOCS

ต้นทาง `D:/EIS-DOCS` ปลายทาง root ของ EIS-DOCS บน production รักษา relative path ตามนี้:

```text
app/src/Presence.php
app/src/SingleLogin.php
app/src/Auth.php
app/src/bootstrap.php
app/src/presence.config.example.php
app/public/login.php
app/public/assets/js/presence-client.js
app/public/docMain/index.php
app/public/docMain/index.html
app/public/docMain/user-online.php
app/public/docMain/user-online.js
app/public/docMain/user-online.css
app/public/docMain/presence-token.php
app/public/docMain/impersonate.php
app/public/docMain/admin-documents.php
app/public/docMain/admin-pin-requests.php
app/public/docMain/department-config.php
```

ใช้กับ EIS-DOCS รุ่นเดียวกับ local ซึ่งมี Database, DocumentAccess, SuppliesImpersonation และโครงสร้าง login/session เดิมอยู่แล้ว ตรวจ diff ของไฟล์เดิมกับ production ก่อนแทนที่ โดยเฉพาะ Auth/bootstrap/หน้า Admin เพื่อเก็บการแก้ไขเฉพาะ production ไว้

## 2. ไฟล์ Supplies

ต้นทาง `D:/EIS-ERP/public/supplies` ปลายทาง application `/supplies`:

```text
conf/presence/Presence.php
conf/presence/SingleLogin.php
conf/presence/SuppliesLogin.php
conf/presence/presence.config.example.php
assets/js/presence-client.js
index.php
access/login.php
access/login_1.php
access/login_2.php
access/login_3.php
access/confirm-login.php
access/impersonate.php
access/logout.php
access/presence-token.php
access/js/signin.js
lib/database/DatabaseServer.php
```

**`conf/config.php` ให้ merge เฉพาะส่วนที่เปลี่ยน ห้ามเขียนทับด้วย config dev ทั้งไฟล์** เพราะมีค่าฐานข้อมูลและบริการเดิมของเครื่อง:

- session path ใช้ `EIS_SUPPLIES_SESSION_DIR` หรือ default `<ERP root>/storage/sessions` และ idle timeout 7200 วินาที
- โหลด `__DIR__ . '/presence/SuppliesLogin.php'` และเรียก validate/ตอบ SESSION_REPLACED ตาม local
- STATUS_SERVER เลือก `eis` เมื่อ HTTP_HOST คือ `eis.nmu.ac.th` และ `dev-eis` สำหรับ dev
- HTTPS_HOST_API ใช้ `EIS_SUPPLIES_API_HOST` หรือ HTTPS_HOST_NAME; IPAPIBG ใช้ MAIN_HOST_API จึงไม่ชี้ dev บน production หาก API production อยู่คนละโดเมนให้กำหนดค่าจริง
- เก็บ DB_SERVER/DB_NAME/DB_USER/DB_PASS, API, ERP log path และบริการอื่นของ production เดิมไว้

DatabaseServer เปลี่ยน signature ของ postPeriod เพื่อไม่ให้ PHP 8.2 ส่ง deprecated warning ก่อน session header; merge จุดนี้หาก production มี DatabaseServer รุ่นต่างกัน

helper สามไฟล์ใน `conf/presence` เป็นสำเนาเดียวกับ `app/src` ของ EIS-DOCS ต้องส่งขึ้นเป็นชุดเดียวกันเมื่อแก้ไขภายหลัง

## 3. Node WebSocket service

ติดตั้งนอก webroot บนเครื่องที่รับ proxy จาก doc-eis:

```text
app/services/presence/server.js
app/services/presence/store.js
app/services/presence/package.json
app/services/presence/package-lock.json
app/services/presence/server.config.example.json
app/services/presence/setup-production.ps1
app/services/presence/install-production-task.ps1
app/services/presence/iis-presence-rule.xml
```

ตัวอย่างใช้ private storage `D:/EIS-Presence/storage`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File ./setup-production.ps1 -StorageDirectory D:/EIS-Presence/storage
```

สร้าง secret ใหม่สำหรับ production เฉพาะเมื่อยังไม่มี และติดตั้ง dependency ตาม lockfile ไม่คัดลอก secrets/certificate/history จาก dev ไม่ต้องนำ node_modules จากเครื่อง dev ขึ้น

คัดลอก `server.config.example.json` เป็น `server.config.json` ปรับ directory จริง ใช้ `transport: "http"`, port 4003, origins เฉพาะ `https://doc-eis.nmu.ac.th` และ `https://eis.nmu.ac.th` จากนั้นเริ่ม `node server.js` หรือรัน `install-production-task.ps1` ใน PowerShell Administrator เพื่อเริ่มตอน boot และ restart หากล้ม ไม่ใช้ start-local/watch-local/Startup ของ ASUS บน production

ตรวจ backend: `http://127.0.0.1:4003/health` ต้องได้ `status: ok` พอร์ตนี้ bind เฉพาะ loopback ไม่ต้องเปิด public firewall

## 4. ตั้งค่า PHP ทั้งสอง application

สร้าง config จากตัวอย่าง:

- EIS-DOCS: `app/src/presence.config.php`
- Supplies: `conf/presence/presence.config.php`

ทั้งสองไฟล์:

```php
<?php
return [
    'enabled' => true,
    'directory' => 'D:/EIS-Presence/storage',
    'websocket_url' => 'wss://doc-eis.nmu.ac.th/eis-docs/presence',
];
```

ค่า directory ต้องเป็น storage เดียวกับ Node จริง ไม่ใช่แค่ชื่อเหมือนกันบนคนละเครื่อง ทั้ง PHP สองระบบและ Node ต้องอ่าน secrets/เขียน logins และ revoked ร่วมกันได้ ส่วน Node ต้องเขียน history ได้ จำกัด ACL เฉพาะ service/application identities ที่เกี่ยวข้อง ไม่เปิดให้เข้าผ่านเว็บ

หากสองโดเมนอยู่คนละเครื่อง ต้องจัด private shared filesystem ที่ทุกเครื่องเข้าถึงได้และรองรับ file locking/atomic rename พร้อม service account/ACL ที่เหมาะสมก่อนเปิดใช้ รุ่นนี้ใช้ไฟล์ร่วมกัน ไม่ใช่ฐานข้อมูลหรือ Redis และไม่สามารถแทนที่เซสชันข้ามเครื่องด้วยการคัดลอก secrets อย่างเดียว

กำหนด `EIS_PERMISSION_SESSION_DIR` สำหรับ PHP Supplies ให้ตรงกับ session directory ของ NMU_permission production เดิม เช่น path ของ `tmp/sessions` จริง เพื่อให้ SSO login_1 ตรวจบัญชีที่ผ่าน login จาก portal ได้ ค่า default ปัจจุบันเป็น path local XAMPP จึงต้องตั้งบน production (หรือคง path เดิมเฉพาะกรณีเครื่องมี path นี้จริง)

Environment ที่ใช้ override ได้: `EIS_PRESENCE_CONFIG` (path config PHP), `EIS_DOCS_PRESENCE_DIR`, `EIS_PRESENCE_WS_URL`, `EIS_SUPPLIES_SESSION_DIR`, `EIS_SUPPLIES_API_HOST`, `EIS_PERMISSION_SESSION_DIR`. Node ใช้ `EIS_PRESENCE_SERVER_CONFIG`, `EIS_DOCS_PRESENCE_DIR`, `EIS_DOCS_PRESENCE_PORT` และ `EIS_PRESENCE_TRANSPORT`.

production จะไม่เปิด feature จนกว่าจะกำหนด directory ผ่าน config หรือ environment สามารถ rollback feature ด้วย `enabled => false` ที่ config PHP ทั้งสองแห่งและหยุด Node; อย่าลบ secrets/history ระหว่าง rollback

## 5. Reverse proxy บน doc-eis

ตั้ง TLS certificate ปกติของ doc-eis ที่ IIS พอร์ต 443 ติดตั้ง/เปิด ARR proxy, URL Rewrite และ WebSocket Protocol แล้ว merge fragment `iis-presence-rule.xml` เข้า `system.webServer/rewrite/rules` ของ application `/eis-docs` ก่อน generic rewrite rules:

```xml
<rule name="EIS-DOCS Supplies Presence" stopProcessing="true">
  <match url="^presence$" ignoreCase="true" />
  <action type="Rewrite" url="http://127.0.0.1:4003/presence" appendQueryString="false" />
</rule>
```

เปิด WebSocket ที่ server/site ตาม delegation ของ IIS อย่าแทน `web.config` ทั้งไฟล์จาก local ซึ่งอาจมี PHP handler/path/proxy อื่นเฉพาะเครื่อง ใช้ ARR ที่รองรับ WebSocket และตั้ง proxy timeout ให้รองรับการเชื่อมต่อยาว โดย backend ping ทุก 10 วินาที ถ้ามี CSP ต้องอนุญาต `connect-src wss://doc-eis.nmu.ac.th` บนทั้งสองเว็บไซต์

อ้างอิงการตั้ง reverse proxy และ Enable Proxy: [Microsoft IIS ARR documentation](https://learn.microsoft.com/en-us/iis/extensions/url-rewrite-module/reverse-proxy-with-url-rewrite-v2-and-application-request-routing)

## 6. ตรวจหลัง deploy

1. เปิด backend health ได้และ task/service ยังทำงาน
2. ล็อกอิน Docs ด้วย Admin เปิด User Online แล้วดู Network: WebSocket URL production ได้ HTTP 101 และ authenticated/snapshot ไม่ใช้ port 4003 บน browser
3. ล็อกอิน Supplies ที่ eis.nmu.ac.th แล้วปรากฏใน Admin ของ doc-eis โดยโหลด client จาก `/supplies/assets/js/presence-client.js` ได้ HTTP 200
4. ล็อกอินบัญชีเดิมด้วย browser profile ใหม่ กด cancel แล้วเซสชันเดิมยังใช้ได้ กดยืนยันแล้วเซสชันเดิมของระบบเดียวกันถูกปฏิเสธทั้ง PHP/API/WS; อีกระบบไม่ถูก logout
5. หลายแท็บของเซสชันเดียวกันนับหนึ่งรอบ ตรวจเวลาเข้า ออก รวม และ reconnect หลัง restart
6. ทดสอบ SSO จาก NMU_permission โดยไม่รับ bare user id ที่ไม่มี parent session และตรวจ cookie บน production จริง

ไม่เปลี่ยน schema SQL สำหรับ feature นี้ เวลาและประวัติเก็บใน private storage เริ่มใหม่แยก dev/production

## ผลตรวจ local

PHP SingleLogin 40 checks, Node Presence 12 tests, environment config 23 checks และ integration IIS/PHP/WebSocket ผ่าน ตรวจ production origin/HTTP proxy backend แยกด้วย fixture; ยังต้องตรวจ TLS/proxy/ACL/SSO บน production จริงหลังติดตั้ง

## เพิ่มเติม: เข้า Supplies และ Procure จาก NMU_permission entrance

ปรับ local ให้คลิกการ์ดแล้วเรียก launch.php ตรวจสิทธิ์ parent session และจับคู่บัญชีด้วย c_user_name ไปยัง ID จริงของแต่ละฐานข้อมูล ก่อนส่งต่อเข้าระบบ ลด iframe/login ซ้ำจากหน้า login และแก้ colon เกินใน URL Procure

นำไฟล์กลุ่ม NMU_permission ในชุด release ไปยัง root `/NMU_permission`:

```text
launch.php
entrance.php
index.php
lib/database/DatabaseServer.php
```

merge `NMU_permission/conf/config.php` เพิ่ม HTTPS_HOST_NAME ที่เลือก eis.nmu.ac.th สำหรับ production/dev-eis.nmu.ac.th สำหรับ dev และ `NMU_PROCURE_PORT` เป็นค่าว่าง โดยเก็บ path session/DB/ค่าของ production เดิมไว้ ไม่เขียนทับ config จาก local

นำไฟล์กลุ่ม Procure ไปยัง root `/procure`:

```text
access/login_1.php
conf/PortalSession.php
lib/database/DatabaseServer.php
```

Procure ต้องอ่าน parent session ใน `EIS_PERMISSION_SESSION_DIR` เช่นเดียวกับ Supplies เมื่อเข้าด้วย portal_entry ส่วน login_1 เดิมสำหรับ caller อื่นยังคง response JSON เดิม ค่าฐานข้อมูล Procure และ canonical host ของ Procure production ให้ใช้ของเครื่องจริง

DatabaseServer ของทั้งสามระบบกำหนด ODBC Driver 18 + Encrypt=true + TrustServerCertificate=true เฉพาะ dev host ที่ใช้ SQL local (`.`, localhost, 127.0.0.1) เพื่อแก้ ODBC17 encryption error ใน IIS; production ไม่เปลี่ยนตัวเลือก TLS ด้วยเงื่อนไขนี้

ตรวจจริงด้วย `node app/services/presence/test-portal-launch.js` บน local: entrance links, account mapping, authenticated Supplies/Procure sessions, ไม่มี parent session/ไม่มีสิทธิ์ถูกปฏิเสธ และ Supplies duplicate confirmation ไม่ทำลายเซสชันเดิม ผ่านแล้ว fixture ใช้บัญชีที่เปิดใช้งานและมีสิทธิ์จริง อ่าน SQL เท่านั้น ล้างเซสชันและ registry ที่สร้างเพื่อทดสอบ ไม่แทนที่บัญชีที่มี registry เดิม
