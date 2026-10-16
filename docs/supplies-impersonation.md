# สวมสิทธิ์เข้าสู่ Supplies

หน้า `docMain/impersonate.php` มีปุ่ม Login EIS-DOCS และ Login Supplies แยกกัน
เฉพาะ admin ประเภท 2 ที่ไม่ได้กำลังสวมสิทธิ์เท่านั้นที่ส่งสิทธิ์เข้า Supplies ได้
ตรวจบัญชีที่เปิดใช้งานและ `dc_cost_id = 38` ทั้ง Datacenter และ Supplies
จับคู่ด้วย `c_user_name` เพราะรหัสผู้ใช้ของสองฐานข้อมูลอาจต่างกัน
การเข้า Supplies คงบัญชี admin ของ EIS-DOCS ไว้ และแทนที่ session เดิมของ Supplies
ออกจาก Supplies ด้วยเมนู logout ของระบบ

## ติดตั้ง

1. ผู้ดูแลฐานข้อมูลรัน `database/supplies-impersonation.sql` ใน NMU_DATACENTER
2. ให้บัญชีฐานข้อมูลของ EIS-DOCS มี SELECT บน NMU_ERP.dbo.dc_user และ INSERT บน NMU_DATACENTER.dbo.DOCS_SUPPLIES_IMPERSONATION
3. ให้บัญชีฐานข้อมูล Supplies มี SELECT และ UPDATE บนตารางดังกล่าว รวมถึง SELECT บน NMU_DATACENTER.dbo.dc_user
4. เว็บ dev ใช้ `D:/EIS-ERP/public/supplies/access/impersonate.php` พร้อมกฎ rewrite ใน `D:/EIS-ERP/public/supplies/web.config` ที่ URL `/supplies/access/impersonate` ไม่ใช่โครงการ Tomcat ใน D:/ERP
5. ทั้งสองแอปต้องอ้างอิง NMU_DATACENTER เดียวกัน ส่วน host แยกกันได้

Production: EIS-DOCS อยู่ doc-eis.nmu.ac.th แต่ Supplies อยู่ eis.nmu.ac.th
ตั้งปลายทางใน app/config/profiles/production.php หรือ environment variable
SUPPLIES_IMPERSONATION_URL=https://eis.nmu.ac.th/supplies/access/impersonate
ต้องนำไฟล์ app/src/SuppliesImpersonation.php, app/public/docMain/impersonate.php และ app/config/profiles ทั้งสองไฟล์ขึ้น production ด้วย
ไฟล์ .env.production.example เป็นตัวอย่าง ต้องตั้ง environment ของ IIS/PHP จริงหากต้องการ override
ฐานข้อมูล production ต้องติดตั้งตารางและสิทธิ์ตามข้อ 1–3 แยกจาก dev

รหัสส่งต่อสุ่ม 32 bytes เก็บเฉพาะ SHA-256 ในฐานข้อมูล หมดอายุใน 60 วินาที
ใช้ UPDATE ... OUTPUT เพื่อรับสิทธิ์ครั้งเดียวและป้องกันการใช้ซ้ำพร้อมกัน
Supplies ตรวจบัญชี admin และบัญชีเป้าหมายอีกครั้งก่อนสร้าง session ใหม่
สามารถลบแถวที่ expires_at < SYSUTCDATETIME() เป็นระยะเพื่อลดข้อมูลสะสม

## ตรวจสอบ

`php scripts/Test-Impersonation.php` ตรวจการสวมสิทธิ์ EIS-DOCS เดิม
`php scripts/Test-SuppliesImpersonation.php` ตรวจ admin, หน่วยงาน, การจับคู่บัญชี และรหัสใช้ครั้งเดียว
สคริปต์ทดสอบ Supplies ลบเฉพาะรหัสที่สร้างระหว่างทดสอบ
ทดสอบผ่านหน้าเว็บเพิ่มเติมด้วยบัญชีหน่วยงาน 38, บัญชีหน่วยงานอื่น และบัญชีที่ไม่มีใน Supplies

ผลในเครื่องนี้: PHP syntax ผ่านทุกไฟล์ และทดสอบ EIS-DOCS เดิมผ่าน
ติดตั้งตารางและให้สิทธิ์เฉพาะ object แก่ eis_docs_app แล้วด้วย Repair-LocalSuppliesImpersonation.php
ยืนยัน phuriwach อยู่หน่วยงาน 38 และเปิดใช้งานทั้งสองฐานข้อมูล
Test-SuppliesImpersonation.php ผ่าน รวมถึงการสร้างรหัสให้ phuriwach และป้องกันการใช้ซ้ำ
สคริปต์ซ่อมรองรับฐานข้อมูลในเครื่องเท่านั้น ไม่แก้ฐานข้อมูล production
Test-SuppliesHandoffHttp.php ผ่านบน https://dev-eis.nmu.ac.th: HTTP 302 และ session Supplies มี user_id ของ phuriwach และ dc_cost_id=38
