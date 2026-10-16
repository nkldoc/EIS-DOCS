# SQL Manager

เปิดจากกล่องงาน → เลือกเคส → **แก้ไข / จัดการ SQL** หรือ `/eis-docs/docMain/sql-manager.php` ใช้ได้เฉพาะ Admin และปฏิเสธ session สวมสิทธิ์

Object Explorer แสดง SQL server → Databases → System Databases / ฐานข้อมูลผู้ใช้ → Tables เลือกชื่อฐานข้อมูลเพื่อโหลดตารางและตั้งเป็นเป้าหมาย Backup ได้แม้ไม่มีตาราง มีช่องค้นหาฐานข้อมูล ค้นหาตาราง และปุ่มรีเฟรชรายชื่อ

ช่องค้นหาฐานข้อมูลรับ `NMU_ERP..sp_tor` (ใช้ schema dbo) หรือ `NMU_ERP.dbo.sp_tor` ได้ กด SELECT / UPDATE / DELETE ใต้ช่องค้นหาหรือเหนือ query เพื่อเลือกฐานข้อมูล/ตารางและสร้าง SQL ตั้งต้น กด Enter ในช่องค้นหาใช้ SELECT ปุ่มเหล่านี้ยังไม่ execute SQL ต้องตรวจสอบและยืนยันตามขั้นตอนเดิม ถ้าไม่พบฐานข้อมูล/ตารางหรือไม่มีสิทธิ์จะแจ้งข้อผิดพลาดและไม่สร้างคำสั่งให้ตารางอื่น

กล่อง Database Backup แสดงเป้าหมายชัดเจน กดสร้าง Backup แล้วจะแสดงสถานะ รายชื่อไฟล์ ขนาด วันหมดอายุ และลิงก์ดาวน์โหลด stream ของแต่ละไฟล์ รายการสำเร็จยังอยู่เมื่อสลับฐานข้อมูลหรือเกิดข้อผิดพลาดในงานถัดไป (รายการใน UI อยู่เฉพาะหน้าที่เปิดนี้) การดาวน์โหลดเป็นลิงก์ตรงไป PHP streaming endpoint ไม่โหลดไฟล์ทั้งก้อนเป็น JavaScript Blob

ใช้ connection เดิมของระบบตาม APP_ENV / host detection ไม่มีการรับ host หรือ credentials จาก browser รายชื่อฐานข้อมูลอ่านจาก `master.sys.databases` โดยแสดงฐานข้อมูลทั้งหมดที่ SQL login มองเห็น พร้อม state และ HAS_DBACCESS แยกกัน ฐานข้อมูลที่ไม่มีสิทธิ์ยังแสดงชื่อ แต่เปิดตาราง/backup ไม่ได้ จึงเลือกฐานข้อมูลอื่นนอกจาก default ได้ตามสิทธิ์ SQL login ทุกคำขอตรวจชื่อที่เลือกกับรายชื่อจาก server อีกครั้ง ชื่อฐานข้อมูลใช้ตรงตัวโดยไม่แปลงผ่าน alias ของ default ส่วนตาราง/คอลัมน์อ่านจาก SQL metadata ตามสิทธิ์ของ login

เลือกฐานข้อมูล ตาราง และ SELECT / UPDATE / DELETE เพื่อสร้าง query ตั้งต้นแล้วแก้ไขได้ รองรับ SQL ตารางเดียว พร้อม WHERE แบบเปรียบเทียบค่า, LIKE, IN/NOT IN, IS NULL, AND/OR และวงเล็บ ไม่มี JOIN, ORDER BY, function, subquery, DDL, EXEC หรือ batch รองรับ comment แบบ -- และ /* ... */ (ไม่ซ้อน block comment) และ IN/NOT IN สูงสุด 1,000 ค่าต่อรายการ จำกัด parameter รวม 2,000 ตัว ค่าทั้งหมดส่งเป็น bound parameters ส่วน identifiers ตรวจจาก metadata และ quote ด้วย brackets SELECT จำกัด TOP 1–500 แถว (default 200)

ขณะพิมพ์ field เช่น `i_` จะเสนอชื่อคอลัมน์จริงของตารางปัจจุบัน เลือกด้วยเมาส์หรือ ↑/↓ แล้วกด Enter/Tab เพื่อแทรกชื่อแบบ bracket ใช้ Esc ปิดรายการ และ Ctrl+Space เรียกรายการ ไม่เสนอชื่อภายใน string/comment

UPDATE/DELETE ที่ไม่มี WHERE จะถูกบล็อกพร้อม dialog โดยไม่มีปุ่มข้ามการตรวจ ตัวตรวจฝั่ง browser ไม่นับคำว่า WHERE ใน string/comment/quoted identifier และฝั่ง API ยังคงตรวจ grammar แยกอีกชั้น เมื่อดู preview แล้ว กดยืนยันจะเปิด dialog แสดง connection/database/table, SQL และจำนวนแถว ต้องพิมพ์ operation กับจำนวนแถว เช่น `DELETE 10` หรือ `UPDATE 1` ให้ตรงก่อนส่งคำสั่งบันทึก Cancel/Esc ไม่แก้ข้อมูล

UPDATE/DELETE ต้องมี WHERE โดยเปรียบเทียบคอลัมน์กับค่าคงที่ แสดงจำนวนและตัวอย่างก่อนแก้สูงสุด 50 แถว ยืนยันได้ภายใน 5 นาที ครั้งละ 1–1,000 แถว ตอนยืนยันจะนับใหม่ภายใต้ UPDLOCK/HOLDLOCK และยกเลิกถ้าจำนวนเปลี่ยน จากนั้นรันใน transaction การยืนยันอ้างอิง query และจำนวนแถว ไม่ใช่ snapshot ของทุกค่า ถ้าระบบอื่นแก้ค่าแต่จำนวนเท่าเดิม คำสั่งจะใช้ข้อมูลล่าสุด ณ เวลายืนยัน

Audit อยู่ที่ `storage/logs/sql-console.jsonl` เก็บ actor, case, database/table, operation, query hash, จำนวนแถว และ phase attempt/committed ไม่เก็บค่าข้อมูลใน SQL หากไม่ได้รับผลยืนยันจาก network ให้ SELECT ตรวจข้อมูลก่อนส่งซ้ำ Trigger ของตารางยังทำงานตาม SQL Server ตามปกติ

## Environment

Dev: profile local ใช้ SQL instance `.` เป็นค่าเริ่มต้น ตั้ง `APP_ENV=local` บน IIS/PHP process ได้ และ DB_HOST ยัง override ได้ตามเดิม Production ใช้ profile เดิม (`192.168.201.3`) หรือ DB_HOST ที่กำหนดไว้ เลือก Local / Production ได้จาก SQL Connection ในหน้า Admin โดยโหลดเฉพาะ profile ฝั่ง server ที่กำหนดไว้ และแสดงชื่อ SQL Server / login ที่เชื่อมต่อจริง หากเชื่อมต่อไม่สำเร็จจะล้างรายการเดิม ไม่มีการ fallback ไปอีกเครื่อง

ถ้าต้องการใช้บัญชี SQL เฉพาะหน้า console ให้กำหนดไฟล์ private `app/config/sql-console.local.local.php` (dev) หรือ `app/config/sql-console.production.local.php` (production) ที่ return array ของ `user`, `password` และ optional `host`, `port` ค่าเหล่านี้ override เฉพาะการเชื่อมต่อหน้า SQL Manager ไม่เปลี่ยนบัญชีของระบบงานหลัก ตั้ง `enabled => false` เพื่อพักการใช้งาน credential ชุดนั้น ไฟล์เหล่านี้ถูกยกเว้นใน .gitignore ห้ามใส่รหัสผ่านไว้ใน JavaScript หรือไฟล์ตัวอย่าง

## Backup .bak

กำหนด environment บน IIS/PHP แล้ว recycle process:

```text
SQL_BACKUP_SQL_DIR=D:/SQLBackups/EISConsole
SQL_BACKUP_WEB_DIR=D:/SQLBackups/EISConsole
```

สองค่านี้ต้องชี้โฟลเดอร์เดียวกันจากมุมมองของ SQL Server และ PHP หากแยกเครื่อง SQL กับเว็บ ให้ SQL_BACKUP_SQL_DIR เป็น path ฝั่ง SQL และ SQL_BACKUP_WEB_DIR เป็น UNC share ที่ IIS อ่านไฟล์นั้นได้ เช่น `\\sql-host\EISConsoleBackups` (ใช้ชื่อ share และสิทธิ์จริงของระบบ)

สร้าง directory ล่วงหน้า SQL Server service account ต้องเขียนได้ และ IIS/PHP identity ต้องอ่านได้ ให้เก็บนอก web root และจำกัด ACL ให้เฉพาะ service/admin SQL login ต้องมีสิทธิ์ BACKUP DATABASE ในฐานข้อมูลที่เลือก ไม่ต้องเปิด xp_cmdshell

ปุ่ม Backup สร้าง full `.bak` ด้วย `COPY_ONLY, CHECKSUM` ไม่เปลี่ยน differential base ไม่ใช้ FORMAT/INIT และใช้ชื่อสุ่มใหม่ทุกครั้ง งาน backup รอจน SQL เสร็จแล้วคืนลิงก์ดาวน์โหลดที่ผูกกับ session Admin อายุ 1 ชั่วโมง ตั้ง IIS/FastCGI timeout ให้เพียงพอกับขนาดฐานข้อมูล ไฟล์ยังอยู่ในโฟลเดอร์หลังลิงก์หมดอายุ ให้กำหนด retention/cleanup ฝั่ง server ตามพื้นที่เก็บ ไม่มีการ restore ผ่านหน้านี้

## Validation

```powershell
php scripts/Test-SqlConsole.php
php scripts/Test-EnvironmentConfig.php
node --check app/public/docMain/sql-manager.js
node scripts/test-sql-manager-ui.cjs
node scripts/test-sql-editor.cjs
```

ทดสอบ SELECT, preview/commit, denied session และ backup/download บนฐานข้อมูลทดสอบก่อนใช้งาน production การทดสอบ grammar ไม่ได้รัน UPDATE/DELETE หรือ BACKUP บนข้อมูลจริง
