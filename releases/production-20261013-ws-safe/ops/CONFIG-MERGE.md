# Merge config ก่อนขึ้น production

ไม่ใช้ conf/config.php ของเครื่องพัฒนาทับ production: คง DB_SERVER, DB_NAME, DB_USER, DB_PASS, domain และ session directory ของจริงไว้

## NMU-EIS: conf/config.php
บันทึกเป็น UTF-8 ไม่มี BOM เพื่อให้ token endpoint ส่ง JSON ถูกต้อง แก้ส่วนเริ่มต้นเป็น:

```php
session_save_path(getenv("EIS_NMU_EIS_SESSION_DIR") ?: "<SESSION_DIRECTORY_ของ_PRODUCTION>");
if (session_status() === PHP_SESSION_NONE) session_start();
```

คง session_name / cookie settings เดิมไว้ เพราะระบบใช้ร่วมกับ flow เดิม

## Procure: conf/config.php
ในเงื่อนไขหมดอายุ 2 ชั่วโมง ก่อนล้าง $_SESSION เพิ่ม:

```php
require_once __DIR__ . '/presence/Presence.php';
ProcurePresence::revoke('PROCURE', (string)$_SESSION['user_id'], (string)($_SESSION['last_login'] ?? ''));
```

เปลี่ยนเงื่อนไขอัปเดต last_activity เป็น:

```php
} elseif (!empty($_SESSION['user_id']) && empty($presencePassiveRequest)) {
    $_SESSION['last_activity'] = $now;
}
```

เพื่อให้การต่ออายุ token เบื้องหลังไม่ยืดเวลาล็อกอิน

## Supplies: prerequisite
production ต้องมี config hook ของ SuppliesLogin จากระบบเดิม: require conf/presence/SuppliesLogin.php และ validate session โดยยกเว้น login/confirmation/logout endpoints ตามไฟล์ปัจจุบัน ตรวจให้ครบก่อนเปิด service
ไฟล์ SingleLogin.php ของ Supplies มี transfer() และ start() 6 parameters ส่วน EIS-DOCS เป็นอีก implementation ห้ามคัดลอกสลับกัน

## Shared Messenger configuration
ตั้ง EIS_DOCS_PRESENCE_DIR ให้ PHP ทุกระบบและ Node ชี้ private directory เดียวกัน หรือใช้ไฟล์ presence.config.php ของแต่ละระบบ:
- EIS-DOCS: app/src/presence.config.php
- Supplies / Procure: conf/presence/presence.config.php
- NMU-EIS: conf/messenger/presence.config.php

ตั้ง EIS_PRESENCE_WS_URL เป็น wss endpoint จริงของ production
Node ใช้ EIS_PRESENCE_SERVER_CONFIG หรือ app/services/presence/server.config.json คง transport, TLS, port และ origins ตาม infrastructure จริง
Production domains ที่โค้ดเปิด Messenger รองรับ: eis.nmu.ac.th / doc-eis.nmu.ac.th ถ้าใช้ hostname อื่น ต้องปรับ enabled() ก่อนใช้งาน

Private directory ต้องมี eis-docs.secret, supplies.secret ของเดิม และ procure.secret, nmu-eis.secret ใหม่ โดยเป็นค่าเดียวกันระหว่าง PHP/Node ห้ามเปลี่ยน key เดิมที่ใช้งานอยู่
คง history.json, chat.json, logins/, revoked/, TLS keys และ PHP sessions ของ production ไว้
NMU-EIS legacy Presence แยกใช้ EIS_PRESENCE_DIR กับ secret.txt และ EIS_PRESENCE_URL ตามระบบเดิม

ให้บัญชี service อ่าน signing keys และเขียน history/chat/logins/revoked ได้ โดย directory อยู่นอก web root
