# Local / Production

การเลือกโหมดตามโดเมนของคำขอ (`HTTP_HOST`):
- `dev-eis.nmu.ac.th` → Local
- `doc-eis.nmu.ac.th` → Production

รองรับ IIS ที่มีหลาย host binding โดยใช้ `HTTP_HOST` ก่อน `SERVER_NAME`
รวมถึงชื่อโดเมนที่มี port, ตัวพิมพ์ใหญ่ และจุดท้ายชื่อโดเมน
หากต้องการให้เลือกโหมดตามโดเมนอัตโนมัติ ให้เว้น `APP_ENV` ไว้;
เมื่อกำหนด `APP_ENV` ค่านั้นจะทับการเลือกจากโดเมนเสมอ
URL ของ API และ WebSocket ใช้ origin ของหน้าเว็บ จึงใช้ config เว็บชุดเดียวกันได้ทั้งสองโดเมน

ทดสอบการเลือก config โดยไม่เชื่อมต่อฐานข้อมูล: `php scripts/Test-EnvironmentConfig.php`

ใช้แนวทางเดียวกับ `D:\EIS-ERP\public\api-eis\procure\conf\database.php`:
เลือกโหมดอัตโนมัติ และกำหนด `APP_ENV` ทับได้

| รายการ | Local | Production |
|---|---|---|
| APP_ENV | local / development / dev | production / prod / product |
| Login URL | https://dev-eis.nmu.ac.th/eis-docs/login | https://doc-eis.nmu.ac.th/eis-docs/login |
| ค่าเริ่มต้น SQL host | localhost | 192.168.201.3 |
| Profile | app/config/profiles/local.php | app/config/profiles/production.php |
| บัญชีฐานข้อมูล | app/config/database.local.php | app/config/database.production.local.php |
| WebSocket | /eis-docs/ws/notifications (ใช้ wss เมื่อหน้าเว็บเป็น HTTPS) | /eis-docs/ws/notifications (ใช้ wss และ hostname เดียวกับหน้าเว็บ) |

ถ้าไม่ได้ตั้ง APP_ENV: localhost, 127.0.0.1, ::1 และ dev-eis.nmu.ac.th ใช้ Local; doc-eis.nmu.ac.th และชื่อโฮสต์อื่นใช้ Production
CLI ที่ไม่มีชื่อโฮสต์ใช้ Local จึงต้องตั้ง APP_ENV=production ก่อนรันคำสั่งบน Production
หากทดสอบ Local ผ่าน IP LAN ให้กำหนด APP_ENV=local ใน IIS

## ตั้งค่าบน Production

1. ตั้ง APP_ENV=production ใน environment ของ PHP/FastCGI บน IIS เพื่อให้โหมดแน่นอนแม้เรียกผ่าน localhost
2. ตั้ง DB_HOST ตาม SQL Server จริง (ค่าเริ่มต้นนำมาจากโปรเจคตัวอย่าง ยังไม่ได้ยืนยันการเชื่อมต่อ Production)
3. ตั้ง DB_USER และ DB_PASSWORD ใน environment หรือคัดลอก
   `app/config/database.production.local.php.example` เป็น `database.production.local.php` และกรอกบัญชีบนเซิร์ฟเวอร์
   ระบบ Production ไม่อ่านบัญชีจาก database.local.php
4. ตั้งตัวแปร DB_* ให้บริการ Tomcat ด้วย Java ไม่อ่าน PHP profile และไม่อ่านไฟล์ .env อัตโนมัติ
5. Production ใช้ DB_ENCRYPT=true และ DB_TRUST_SERVER_CERTIFICATE=true ชั่วคราวสำหรับ SQL ภายในที่ใบรับรองยังไม่ trusted
   ข้อมูลยังเข้ารหัส แต่ไม่ตรวจสอบใบรับรองเซิร์ฟเวอร์ เมื่อติดตั้งใบรับรองที่ trusted แล้วให้ตั้ง DB_TRUST_SERVER_CERTIFICATE=false
   และใช้ DB_HOST ที่ตรงกับ certificate ของ SQL Server จริง
   หาก IIS/FastCGI ตั้ง DB_TRUST_SERVER_CERTIFICATE=false อยู่ ค่านั้นจะทับ profile ต้องแก้เป็น true แล้ว recycle App Pool ของ EIS-DOCS
6. web.config ใช้ร่วมกันได้ทั้งสองโหมด และมี named remove ป้องกันกฎ IIS ที่สืบทอดมาซ้ำ

ไฟล์ .env.local.example และ .env.production.example เป็นรายการตัวแปรอ้างอิง ไม่ใช่ไฟล์ที่โหลดอัตโนมัติ
Environment variables มีลำดับความสำคัญเหนือ PHP profile และบัญชีในไฟล์
ค่า DB_USER ว่างอย่างชัดเจนจะเลือก Windows Authentication; หากใช้ SQL Login ให้ระบุบัญชีจริง
อย่าคัดลอกไฟล์บัญชี Local ทับบัญชี Production ระหว่าง deploy

เปลี่ยนตัวแปรระดับ service แล้ว recycle เฉพาะ IIS App Pool / Tomcat ที่เกี่ยวข้อง
การแยก config นี้ไม่ใช่การ deploy หรือการยืนยันการเชื่อมต่อ Production
