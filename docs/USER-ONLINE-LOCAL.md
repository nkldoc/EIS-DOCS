# ผู้ใช้ออนไลน์ EIS-DOCS และ Supplies (local)

เพิ่มนโยบายยืนยันก่อนแทนที่เซสชันเดิมแล้ว ดู `DUPLICATE-LOGIN-LOCAL.md`

หน้า Admin: `https://dev-eis.nmu.ac.th/eis-docs/docMain/user-online.php`

## การใช้งาน

เข้าสู่ EIS-DOCS ด้วยบัญชีผู้ดูแลระบบประเภท 2 แล้วกด **ผู้ใช้งานออนไลน์ (Admin)** ในเมนูฝั่งซ้าย ระบบไม่อนุญาตให้เปิดหน้า Admin ระหว่างสวมสิทธิ์

หน้าแสดงผู้ใช้เฉพาะ **EIS-DOCS** และ **Supplies** พร้อมตัวกรองระบบ ช่วงวันที่ ชื่อ หน่วยงาน และสถานะ แต่ละแถวแยกตามระบบ มีเวลาเริ่ม เวลาออก เวลารอบล่าสุด เวลารวมในช่วงวันที่ และจำนวนแท็บ เปิดแท็บประวัติเพื่อดูแต่ละรอบและเหตุผลสิ้นสุด

ผู้ใช้ต้องรีโหลดหน้าหลักหลังติดตั้งเพื่อเริ่มติดตาม EIS-DOCS ติดตามหน้าหลัก หน้า Admin ออนไลน์ และหน้าจัดการ Admin (เอกสาร, PIN, หน่วยงาน, สวมสิทธิ์) ส่วน Supplies ติดตามหน้าหลักที่ครอบหน้าเมนูภายใน ไม่ได้ใช้การล็อกอินเข้า NMU_permission หรือ EIS เป็นหลักฐานว่าเข้ามาใช้สองระบบนี้

## นิยามและขอบเขตเวลา

- ออนไลน์หมายถึงมีหน้าของระบบนั้นเปิดและเชื่อมต่อ WebSocket ได้ ไม่ใช่เวลาที่ผู้ใช้กำลังทำงานกับเอกสาร
- ใช้ user ID ของแต่ละระบบ รหัสที่เหมือนกันในสองระบบแยกเป็นคนละรายการ ไม่รวมบัญชีโดยเดาจากชื่อ
- เปิดหลายแท็บหรือหลายอุปกรณ์ในระบบเดียวกันรวมเป็นรอบเดียวและไม่นับเวลาซ้ำ
- เวลารวมทั้งสองระบบเป็นผลรวมเวลาแยกตามระบบ หากคนเดียวเปิดสองระบบพร้อมกัน เวลาของแต่ละระบบถูกนับแยกกัน
- เมื่อแท็บสุดท้ายหลุดรอ reconnect 30 วินาที ถ้าไม่กลับมาปิดรอบ ณ เวลาหลุด ไม่บวกเวลารอ หากกลับมาภายในช่วงนี้คงรอบเดิม
- ใช้เวลาของ service เก็บ timestamp UTC แสดง Asia/Bangkok ช่วงวันที่ตัดตามวันไทย รวมรอบที่ข้ามเที่ยงคืนได้
- การ์ด EIS-DOCS/Supplies ออนไลน์ขณะนี้แสดงจำนวนปัจจุบันของแต่ละระบบเสมอ การ์ดอื่นเป็นผลตามระบบ/วันที่เลือก ชื่อและสถานะกรองรายการเท่านั้น
- ผู้เปิดหน้า Admin ออนไลน์ถือว่าใช้ EIS-DOCS จึงถูกนับด้วย

## สถาปัตยกรรม local

service แยกจากตัวติดตาม EIS เดิม ใช้ WebSocket บน `wss://dev-eis.nmu.ac.th:4003/presence` ฟังเฉพาะ `127.0.0.1` ไม่มีข้อมูล EIS ในประวัติชุดนี้

PHP ของ EIS-DOCS และ Supplies ออก token อายุ 60 วินาทีจาก session ของระบบตนเอง ใช้ secret แยกต่อระบบ service ตรวจทั้งลายเซ็น แหล่งที่มา และการเพิกถอนก่อนนับผู้ใช้ สิทธิ์ดูข้อมูลทั้งหมดรับจาก token ของ EIS-DOCS Admin เท่านั้น token Supplies ไม่สามารถยกระดับเป็นสิทธิ์ผู้ดูแลได้

ตัวติดตามต่ออายุ token ทุก 20 วินาที การต่ออายุของ Supplies เป็น passive request จึงไม่เปลี่ยน `last_activity` และยังใช้ idle timeout เดิม 2 ชั่วโมง ออกผ่านลิงก์ Logout จะเพิกถอนการเชื่อมต่อทันที ส่วน PHP logout/เปลี่ยนตัวตนทำเครื่องหมายเพิกถอน ซึ่ง service ตรวจทุก 10 วินาที ช่องทางอื่นที่ทำลาย session จะถูกตรวจพบเมื่อต่ออายุไม่สำเร็จหรือ token หมดอายุ

ข้อมูลของ service อยู่ที่ `D:/EIS-DOCS/storage/presence/` ภายนอก web root:

- `history.json`: ประวัติถาวรสำหรับ local dev
- `eis-docs.secret`, `supplies.secret`: secret ห้ามเผยแพร่
- `local.key`, `local.crt`: TLS local ใช้ใบรับรองโดเมนเดียวกับ WebSocket local ที่ติดตั้งไว้ก่อนหน้า
- `revoked/`: เครื่องหมายเพิกถอน session
- `service.log`, `service-error.log`: log

service ไม่ใช้ฐานข้อมูล SQL ในรุ่น local นี้ ประวัติเริ่มเก็บหลังติดตั้ง และไม่มีการนำเวลาของ EIS หรือประวัติก่อนหน้าเข้ามา การ restart ปิดรอบค้างที่ heartbeat ที่บันทึกล่าสุด ไม่บวกช่วง downtime

## เริ่ม service หลังเปิดเครื่อง

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:/EIS-DOCS/app/services/presence/start-local.ps1
```

ตรวจสุขภาพ: `https://dev-eis.nmu.ac.th:4003/health`

ติดตั้ง dependency/secret อีกครั้งโดยไม่เขียนทับ secret หรือประวัติ:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:/EIS-DOCS/app/services/presence/setup-local.ps1
```

ตั้งให้ service เริ่มอัตโนมัติเมื่อผู้ใช้ Windows ASUS ล็อกอิน โดยไฟล์ Startup `EIS-DOCS-Supplies-Presence.vbs` เรียก `watch-local.ps1` แบบซ่อนหน้าต่าง และเปิด service ใหม่หากโปรเซสหยุดทำงาน (ยังไม่ใช่ Windows service ที่เริ่มก่อนล็อกอิน) ตัวเชื่อมของ Supplies อ้าง helper ที่ `D:/EIS-DOCS/app/src/Presence.php` สำหรับเครื่อง dev นี้โดยเฉพาะ ก่อนนำขึ้นระบบร่วมต้องปรับ path, TLS/proxy, service lifecycle และการเก็บประวัติ SQL

## การทดสอบ

```powershell
npm.cmd test --prefix D:/EIS-DOCS/app/services/presence
node D:/EIS-DOCS/app/services/presence/integration-local.js
```

Unit tests ตรวจ multi-tab, reconnect, logout, ข้ามวัน, restart, file lock บน Windows, รหัสผู้ใช้ชนกันระหว่างระบบ และการกัน EIS

Integration test ใช้ PHP session จำลองชั่วคราวกับ IIS จริง และ service แยกบนพอร์ต 4004 เพื่อไม่เพิ่มผู้ใช้ทดสอบในประวัติหลัก ตรวจเมนู/สคริปต์ของหน้าจริง, สิทธิ์ Admin, การสวมสิทธิ์, idle timeout, ลายเซ็นแต่ละระบบ, snapshot/ตัวกรอง, การปฏิเสธ EIS และการออกจากระบบ แล้วลบ session และไฟล์ทดสอบที่สร้างขึ้น
