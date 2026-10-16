# IIS / ARR

1. เปิดใช้ IIS, CGI/FastCGI, URL Rewrite และ Application Request Routing (ARR)
2. เปิด `Enable Proxy` ใน ARR Server Proxy Settings
3. สร้าง site โดยชี้ Physical Path ไปยัง `D:\EIS-DOCS\app\public`
4. ผูก PHP executable กับ `*.php` ผ่าน FastCGI
5. ให้ Tomcat ฟังเฉพาะ `127.0.0.1:8080` หากอยู่เครื่องเดียวกับ IIS
6. เปิด HTTPS ที่ IIS และไม่เปิดพอร์ต 8080 ออกสู่ภายนอก

กฎ reverse proxy อยู่ใน `app/public/web.config` และส่ง `/api/*` ไป Tomcat

