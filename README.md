# EIS-DOCS

โครงสร้างตั้งต้นของระบบเอกสาร EIS สำหรับ Windows Server โดยมี IIS เป็นทางเข้าหลัก
และแยกการประมวลผลระหว่าง PHP (FastCGI) กับ Spring Boot บน Tomcat 9

## เส้นทางคำขอ

```text
Browser
  |
  v
IIS + ARR
  |-- *.php --------------> PHP FastCGI --------> SQL Server
  `-- /api/* -------------> Tomcat 9 / Java ----> SQL Server
```

## โครงสร้าง

```text
EIS-DOCS/
|-- app/                    หน้าเว็บและ PHP
|   |-- public/             IIS document root
|   |-- src/                PHP helpers/services
|   `-- config/             การตั้งค่า PHP
|-- backend/                Spring Boot API (WAR สำหรับ Tomcat 9)
|-- database/               สคริปต์ SQL แยกตามฐานข้อมูล
|-- infrastructure/iis/     ตัวอย่าง IIS/ARR configuration
|-- scripts/                สคริปต์ตรวจสอบและ deploy
|-- docs/                   เอกสารสถาปัตยกรรม/ติดตั้ง
`-- .env.example            รายการ environment variables
```

## เริ่มต้น

1. คัดลอก `.env.example` เป็น `.env` บนเครื่องพัฒนา และกำหนดค่าจริงผ่าน
   Environment Variables/secret store บน production
2. ติดตั้ง IIS, URL Rewrite, ARR และ PHP FastCGI ตาม `docs/DEPLOYMENT.md`
3. วาง ExtJS 3.4 ที่ `app/public/assets/extjs/` (ไม่รวมใน repository เพราะ license)
4. Build Java ด้วย `mvn clean package` ใน `backend/`
5. Deploy `backend/target/eis-docs.war` ไป Tomcat 9 ที่ context `/eis-docs`
6. ตั้ง IIS site root ไปที่ `app/public/` และใช้ `web.config` ที่อยู่ในโฟลเดอร์นั้น

## Health checks

- PHP: `http://localhost/eis-docs/health.php`
- Java: `http://localhost:8080/eis-docs/api/health`

## PDF Manager API

`layout.html` เรียก servlet ผ่าน `/eis-docs/api/pdf/*` โดยมี endpoint หลักดังนี้:

- `POST /eis-docs/api/pdf/generate` สร้างและบันทึก PDF
- `POST /eis-docs/api/pdf/uploadTemplate` อัปโหลด template
- `GET /eis-docs/api/pdf/template` อ่าน template ปัจจุบัน
- `GET /eis-docs/api/pdf/bookmarks` อ่าน bookmark
- `GET /eis-docs/api/pdf/serverFiles` แสดงรายการ PDF
- `POST /eis-docs/api/pdf/sign` ลงนามตาม user ID

ค่าเริ่มต้นจัดเก็บไฟล์ที่ `D:/EIS-DOCS/storage/pdf/` และเปลี่ยนได้ด้วย
environment variable `EIS_DOCS_PDF_STORAGE`

สามารถเรียกผ่าน friendly URL `/eis-docs/pdf/*` ได้เช่นกัน เช่น
`http://localhost/eis-docs/pdf/` และ `http://localhost/eis-docs/pdf/serverFiles`

## Login

- หน้าเข้าสู่ระบบ: `http://localhost/eis-docs/login.php`
- Java API: `http://localhost:8080/eis-docs/api/*`
- ตรวจบัญชีจาก `NMU_DATACENTER.dbo.dc_user`
- รับเฉพาะบัญชี `i_enable = 1` และ `i_delete = 2`
- รหัสผ่านรองรับ MD5 เดิมของระบบชั่วคราว ควรวางแผน migrate เป็น `password_hash()`


# Local
& D:\EIS-DOCS\scripts\Repair-WebSocketDelegation.ps1 -Location 'dev-eis.nmu.ac.th/eis-docs'

# Production — รันบน production server
& D:\EIS-DOCS\scripts\Repair-WebSocketDelegation.ps1 -Location 'doc-eis.nmu.ac.th/eis-docs'