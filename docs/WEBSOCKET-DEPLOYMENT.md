# WebSocket on IIS application /eis-docs

หน้าเว็บ: https://dev-eis.nmu.ac.th/eis-docs/docMain/
WebSocket: wss://dev-eis.nmu.ac.th/eis-docs/ws/notifications
IIS upstream: http://127.0.0.1:8080/eis-docs/ws/notifications

docMain เป็นหน้าจอ ส่วน WebSocket อยู่ใต้ราก application /eis-docs/ws
ไม่ใช้ /eis-docs/docMain/ws และไม่เรียกพอร์ต 8080 จาก browser บน HTTPS

## Deploy hotfix

1. สำรองไฟล์แอปที่จะเปลี่ยนและ WAR เดิมไว้นอก public directory
2. คัดลอกไฟล์ app/ ใน hotfix ไปยัง D:\EIS-DOCS\app โดยเก็บ database.local.php และ database.production.local.php เดิมไว้
3. Deploy backend/target/eis-docs.war ไป Tomcat context /eis-docs ด้วยขั้นตอนเดิมของเซิร์ฟเวอร์
   ต้อง reload/redeploy context เพื่อใช้ allowed origins ใหม่ (dev-eis และ doc-eis)
4. หากตั้ง EIS_DOCS_WS_URL ใน IIS ไว้ ให้เปลี่ยนเป็น /eis-docs/ws/notifications
   หรือลบตัวแปรนี้เพื่อใช้ค่า default ของ Production profile
5. ตั้ง EIS_DOCS_WS_SECRET ค่าเดียวกันใน PHP/FastCGI และ Tomcat
   หากไม่ตั้ง ระบบเดิมใช้ค่าที่ derive จาก DB_PASSWORD ซึ่งต้องตรงกันทั้งสองฝั่ง
   ห้ามใส่ secret ใน JavaScript, HTML หรือ URL
6. IIS ต้องเปิด WebSocket Protocol และ ARR proxy; web.config ต้องอยู่ที่ application root D:\EIS-DOCS\app\public
   กฎ Notification WebSocket match ^ws(?:/(.*))?$ และ proxy ไป Tomcat ตามข้างต้น
7. ถ้าเปลี่ยน environment variables ให้ recycle เฉพาะ App Pool/บริการที่เกี่ยวข้อง จากนั้นรีเฟรชหน้าเว็บ

## Verify

### IIS 500.19 / 0x80070021 / WebSocketModule

ถ้า IIS ล็อก section `system.webServer/webSocket` ห้ามประกาศ `<webSocket>` ใน `app/public/web.config`
ให้ตั้งค่าที่ `applicationHost.config` แทน โดยเปิด PowerShell แบบ Run as Administrator บน IIS server แล้วรันเฉพาะ site ที่ติดตั้ง:

```powershell
# Local
& D:\EIS-DOCS\scripts\Repair-WebSocketDelegation.ps1 -Location 'dev-eis.nmu.ac.th/eis-docs'
# Production (รันบน production server)
& D:\EIS-DOCS\scripts\Repair-WebSocketDelegation.ps1 -Location 'doc-eis.nmu.ac.th/eis-docs'
```

สคริปต์สำรอง IIS และ web.config ก่อนแก้ เปิด WebSocket เฉพาะ application ที่ระบุ
แล้ว recycle เฉพาะ App Pool ของ application นั้น ไม่ปลดล็อกทั้งเซิร์ฟเวอร์

- Login แล้วเปิด Network > WS: URL ต้องเป็น wss://dev-eis.nmu.ac.th/eis-docs/ws/notifications?token=...
- Handshake ต้องได้ 101 และ connection ต้องเปิดค้าง ไม่ปิดทันที
- ทำรายการทดสอบ Workflow ที่มีผู้รับแจ้งเตือน แล้วตรวจว่าระฆังของผู้รับอัปเดต
- 403: ตรวจว่า deploy WAR ใหม่แล้ว และ Origin ตรงกับ https://dev-eis.nmu.ac.th
- 1003 หลัง 101: ตรวจ secret ของ PHP/Tomcat และเวลาบนเซิร์ฟเวอร์
- 502/404: ตรวจ Tomcat context, ARR และ application path
- Client จะ reconnect โดยเพิ่มระยะห่างสูงสุด 60 วินาที และอ่านรายการแจ้งเตือนทุก 30 วินาทีระหว่าง socket ขาดการเชื่อมต่อ

เครื่องมือนี้ build/test hotfix ใน workspace แล้ว แต่ยังไม่ได้ deploy ไป dev-eis
อ้างอิง IIS ARR: https://learn.microsoft.com/en-us/iis/extensions/url-rewrite-module/reverse-proxy-with-url-rewrite-v2-and-application-request-routing
