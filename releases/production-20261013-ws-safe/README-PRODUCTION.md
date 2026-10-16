# Production release - 13 ตุลาคม 2026

ชุดไฟล์: production-20261013-ws-safe
สถานะ: เตรียมแพ็กเกจแล้ว ยังไม่ได้ deploy production

คำสั่งรันและซ่อม service/proxy: อ่าน RUN-PRODUCTION.md หรือรัน `ops/SHOW-RUN.ps1` โหมดเริ่มต้นจะแสดงคำสั่งโดยไม่แก้ระบบ

## สิ่งที่เปลี่ยน
- แยกชื่อ Admin และ UID ตามผู้ดูแล/ระบบ: admin-art-supplies, admin-art-EIS-DOCS, admin-art-procure, admin-art-NMU-EIS
- Supplies re-entry ใช้ session เดิมที่ยังถูกต้อง
- Procure และ NMU-EIS ส่งข้อความ/รูปเข้า Ticket กลาง และรับคำตอบย้อนหลัง
- User Online แสดงเฉพาะออนไลน์; ออฟไลน์ดูที่ประวัติ
- กล่องงานเริ่มที่รอตอบและกรองระบบได้ 6 ระบบ (ระบบที่เชื่อมจริงตอนนี้: EIS-DOCS, Supplies, Procure, NMU-EIS; NMU/FM ยังเป็นตัวเลือกกรอง)
- แก้ connection/token ซ้ำ, timeout/reconnect, stale callback, rate limit, bounded output queue และ service startup ซ้ำ

## โครงสร้างไฟล์ 74 runtime files
| โฟลเดอร์ | จำนวน | ปลายทางตามเครื่องพัฒนา (ปรับให้ตรง production) |
|---|---:|---|
| payload/EIS-DOCS | 20 | D:/EIS-DOCS |
| payload/Supplies | 13 | D:/EIS-ERP/public/supplies |
| payload/Procure | 15 | D:/EIS-ERP/public/procure |
| payload/NMU-EIS | 26 | C:/xampp/htdocs/NMU_EIS |

ไฟล์ถูกจัดตาม relative path ให้ merge เฉพาะรายการใน manifest ห้ามลบ directory ปลายทางหรือทับ config/database.php ของจริง
Auth.php / login.php ของ EIS-DOCS และ SuppliesLogin / confirm-login / login-approval ถูกแนบเป็น dependency ให้ใช้ชุดที่เข้าคู่กัน; แพ็กเกจเป็น incremental release บน application เดิม ไม่ใช่ application เต็มสำหรับติดตั้งใหม่
package.json สำหรับ production มี scripts.start เท่านั้น; dependencies และ lockfile คงเดิม ไม่รวม node_modules

## ขั้นตอนติดตั้ง
1. รัน VERIFY.ps1 ตรวจ SHA-256 ก่อนใช้
2. สำรองไฟล์ตาม manifest และ config ที่จะ merge รวมถึง private history/chat data เพื่อ rollback
3. ตรวจ baseline EIS-DOCS/Supplies Messenger และ IIS routes/reverse proxy ของ production ว่าพร้อมใช้งาน
4. อ่าน ops/CONFIG-MERGE.md แล้ว merge config ของ NMU-EIS/Procure และตรวจ hook ของ Supplies พร้อมตั้ง shared private directory / wss URL / permissions
5. ถ้ายังไม่มี procure.secret / nmu-eis.secret ใช้ ops/New-MessengerKeys.ps1 -PrivateDirectory <private directory จริง> สร้าง key บน production ค่า key ไม่รวมในแพ็กเกจนี้
6. คัดลอก payload แต่ละระบบเข้า root ที่ตรงกันเป็นชุด PHP/HTML/JS พร้อมกัน
7. ใน service directories รัน npm ci --omit=dev ตามขั้นตอน service manager ของ production หาก dependencies ยังไม่มี
8. รีโหลด PHP/OPcache และ restart shared Messenger service; restart NMU-EIS legacy Presence เฉพาะกรณีใช้งาน service นั้นอยู่ ใช้ service manager จริงของ production ไม่ใช้ start-local.ps1 ของเครื่องพัฒนา
9. ตรวจ /health ของ shared service ต้อง status=ok, build=20261013-ws-safe และตรวจ error logs
10. ทดสอบจริง: Admin สองชื่อคนละ browser, กลับเข้าระบบ, ส่ง Ticket/รูปจาก Procure/NMU-EIS, Admin กรอง/รับงาน/ตอบ/ปิด, ผู้ใช้รับย้อนหลัง, logout, เปิดหลายแท็บและ reconnect

Asset version: 20261013-ws-safe; ไม่มี SQL migration

## Rollback
หยุด service ที่เปลี่ยน, คืนไฟล์/config จาก backup และ dependency เวอร์ชันเดิม แล้วเริ่ม service เดิมใหม่ อย่าทับ/ลบ history.json, chat.json หรือ signing keys โดยอัตโนมัติ ถ้าต้องกู้ข้อมูลให้ตัดสินใจจาก backup และข้อความใหม่ที่เกิดหลัง deploy

## ผลทดสอบ
- Shared automated suite: 91 passed
- NMU-EIS legacy Presence suite: 10 passed
- Single Login: 55 checks passed
- Named identity: 32 checks passed; forms/CSRF: 20 checks passed
- WebSocket integration/security, token endpoints และ live development client routes ผ่าน
- ตรวจ syntax PHP/JS แล้ว
ยังไม่ได้ทดสอบโหลดจริง production หรือ acceptance ด้วยบัญชีจริง การยืนยันสิทธิ์ของ backend แจ้งเตือนเก่าที่แยกจาก Presence เป็นงานตรวจต่อจาก infrastructure เดิม

## สิ่งที่ไม่นำมาทับ
conf/config.php ที่มี credentials, .env, signing/TLS keys, sessions, chat/history, logs, node_modules และข้อมูลผู้ใช้ ไม่รวมใน payload
