# ติดตั้ง EIS-DOCS บน Production

Error 18456 `NT AUTHORITY\ANONYMOUS LOGON` เกิดก่อนอ่านตาราง: PHP ไม่ส่ง SQL Login และกำลังใช้ Windows Authentication ข้ามเครื่อง

1. สำรองฐานข้อมูลเดิม เปิด SSMS เชื่อม SQL Server ของ Production ด้วยบัญชี DBA
2. รัน `01_EIS_DOCS_schema.sql` สร้าง EIS_DOCS และ 6 ตาราง หรือเพิ่มคอลัมน์ตาม migrations ถ้ามีอยู่แล้ว ไม่คัดลอกข้อมูล Local ไม่ล้างข้อมูลเดิม
3. ต้องมีฐานข้อมูลจริง `NMU_DATACENTER` (ผู้ใช้/พนักงาน) และ `NMU_ERP` (PR/AP) อยู่บน SQL instance เดียวกัน ถ้ายังไม่มี ให้ restore ฐานข้อมูลของระบบเดิม ไม่สร้างตารางว่างเพื่อแก้ Login
4. แก้ `@Password` ใน `02_SQL_login_permissions.sql` แล้วรันด้วย DBA สคริปต์ใช้ SQL Login `eis_docs_app` ถ้ามี Login อยู่แล้วจะไม่เปลี่ยนรหัสผ่าน ต้องใช้รหัสผ่านเดิม บัญชีได้ CRUD เฉพาะ 6 ตาราง EIS_DOCS และ SELECT เฉพาะตารางอ้างอิง
5. บนเว็บเซิร์ฟเวอร์คัดลอก `app/config/database.production.local.php.example` เป็น `app/config/database.production.local.php` ใส่ `user` เป็น `eis_docs_app` และ `password` ให้ตรง SQL Login จริง ห้ามส่งรหัสผ่านในแชต
6. ตั้ง `APP_ENV=production` ตรวจ DB_HOST ให้ตรง SQL Server ถ้า IIS/FastCGI มี DB_USER หรือ DB_PASSWORD ว่าง ให้ลบตัวแปรว่างเหล่านั้นหรือใส่ค่าจริง เพราะ environment มีลำดับสูงกว่าไฟล์ PHP ไม่โหลด .env อัตโนมัติ
7. Recycle เฉพาะ IIS App Pool ของโปรเจค แล้วทดสอบ Login อีกครั้ง ตั้ง DB_USER/DB_PASSWORD ของ Tomcat ด้วยถ้าใช้บริการแจ้งเตือน

SQL Server ต้องเปิด Mixed Mode สำหรับ SQL Login สคริปต์จะหยุดพร้อมข้อความถ้ายังใช้ Windows-only
Production profile ปัจจุบันเข้ารหัสและข้ามการตรวจใบรับรองชั่วคราว เมื่อติดตั้งใบรับรองที่ trusted แล้วให้ตั้ง DB_TRUST_SERVER_CERTIFICATE=false

ตารางที่สร้าง: WF_USER_HDR, WF_USER_DTL, DOCS_HDR, DOCS_DTL, DOCS_SET, DOCS_NOTIFICATION
ทดสอบสคริปต์ schema บนฐานข้อมูลแยก Local และรันซ้ำแล้ว ไม่ใช่การยืนยันการติดตั้ง Production
