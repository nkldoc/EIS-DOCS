# Deployment checklist

## Windows Server

- IIS + CGI/FastCGI
- IIS URL Rewrite
- Application Request Routing และเปิด Proxy
- PHP รุ่นที่ระบบรองรับ พร้อม Microsoft Drivers for PHP for SQL Server
- Java 11
- Tomcat 9
- SQL Server network access และ certificate ที่เชื่อถือได้

## Release flow

การเลือก config Local/Production และการตั้งบัญชีแยกเครื่อง: [ENVIRONMENTS.md](ENVIRONMENTS.md)

1. สำรอง configuration และฐานข้อมูลก่อน deploy
2. Build `backend/` ด้วย Maven
3. หยุดเฉพาะ Tomcat application แล้วเปลี่ยน `eis-docs.war`
4. Sync `app/` โดยไม่เขียนทับ secret/config ของเครื่อง
5. เริ่ม Tomcat และตรวจ `/api/health`
6. ตรวจ `/health.php` ผ่าน IIS
7. ทดสอบ login, สิทธิ์, CRUD เอกสาร และการเชื่อมต่อทั้งสามฐาน

## Local URLs

- PHP/IIS: `http://localhost/eis-docs/`
- Java/Tomcat: `http://localhost:8080/eis-docs/`
- Java health: `http://localhost:8080/eis-docs/api/health`

รัน `scripts/Deploy-Local.ps1` ด้วย PowerShell แบบ **Run as administrator** เพื่อสร้าง
IIS application `/eis-docs`, deploy WAR และ restart services ที่เกี่ยวข้อง
