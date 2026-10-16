# แก้ไขการเชื่อมต่อฐานข้อมูลหน้า Login EIS DOCS

Log บน local วันที่ 10 ตุลาคม 2026 แสดงข้อผิดพลาด ODBC Driver 17 `Encryption not supported on the client` ทำให้หน้า login แจ้งว่าไม่สามารถเชื่อมต่อฐานข้อมูลได้

ปรับ local profile ให้กำหนด `ODBC Driver 18 for SQL Server` และใช้ encryption พร้อม trust certificate ของ SQL Server local ส่วน production profile ยังคงค่าเดิม ผู้ดูแลสามารถกำหนด `DB_DRIVER` เพื่อเลือกไดรเวอร์ได้โดยตรง

ไฟล์ที่แก้ไข

- `app/src/Database.php`
- `app/config/database.php`
- `app/config/profiles/local.php`

ชุดไฟล์รวม `app/config/runtime.php` และ profile production เพื่อให้โครงสร้าง config ครบ โดยไม่รวมไฟล์ credentials

ทดสอบผ่าน HTTPS IIS หน้า login จริงด้วยชื่อบัญชีที่ไม่มีอยู่ ระบบเชื่อมต่อฐานข้อมูลได้และตอบว่าชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง รวมถึงทดสอบการเลือกไดรเวอร์ local ค่า production และ DB_DRIVER override
