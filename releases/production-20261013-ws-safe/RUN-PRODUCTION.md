# คำสั่งรัน production

เปิด PowerShell แบบ **Run as Administrator บนเครื่อง production IIS / Node** หลังคัดลอก payload และ merge config ตาม README แล้ว

## แสดงคำสั่ง โดยไม่แก้ระบบ

```powershell
cd D:\EIS-DOCS\releases\production-20261013-ws-safe
.\VERIFY.ps1
.\ops\SHOW-RUN.ps1
```

## ตรวจ service และ public WebSocket

```powershell
.\ops\SHOW-RUN.ps1 -Mode Check
```

## ซ่อมการเชื่อมต่อในภาพและเริ่ม service

```powershell
.\ops\SHOW-RUN.ps1 -Mode Repair -InstallDependencies -CreateNewAppKeys
```

Repair ใช้ directory จาก Scheduled Task จริง ตรวจ runtime ลง dependencies จาก lockfile สร้างเฉพาะ procure.secret / nmu-eis.secret ที่ยังไม่มี สำรอง Node config เปลี่ยน backend เป็น HTTP localhost:4003 restart/create Scheduled Task แล้วซ่อม IIS ARR/WebSocket/rewrite และตรวจ public handshake

ต้องมี server.config.json ที่ชี้ **private storage เดิมของ production** พร้อม eis-docs.secret / supplies.secret เดิมก่อนรัน ไม่ใช้ directory หรือ key ของเครื่องพัฒนาแทนข้อมูล production

สคริปต์คง DB config, key เดิมและ chat/history ไว้ หากมี environment overrides จะหยุดพร้อมชื่อ variable เพื่อไม่แก้ผิด config หรือเปิด Node ซ้ำ

ถ้า Node, IIS ARR หรือ WebSocket Protocol ยังไม่ได้ติดตั้ง จะหยุดพร้อมข้อความ ไม่ติดตั้ง Windows feature ให้อัตโนมัติ ถ้า Scheduled Task ใหม่ใช้ SYSTEM ต้องตรวจสิทธิ์อ่าน/เขียน private storage และ share; task เดิมคง identity เดิมไว้

หากชื่อ IIS application ต่างจากค่า default ใช้ `-ApplicationName 'ชื่อ-site/eis-docs'` ตามรายการ `appcmd list app`

## อัปเดต code ครั้งต่อไป เมื่อ proxy ถูกต้องแล้ว

```powershell
.\ops\SHOW-RUN.ps1 -Mode Restart
```

ผลที่ต้องเห็น: local HTTP health=ok, build=20261013-ws-safe และ public WebSocket handshake ผ่าน (101) จากนั้น Ctrl+F5 และทดสอบหลังล็อกอินจริง

ถ้า PowerShell บล็อกการรัน script ให้ใช้วิธีอนุญาต script ตามนโยบายขององค์กรก่อน ไม่จำเป็นต้องเปลี่ยน ExecutionPolicy ทั้งเครื่อง

ภาพที่ socket ปิดก่อนเชื่อมต่อสำเร็จยังไม่ชี้สาเหตุแน่นอน ต้องตรวจ backend และ proxy ตามขั้นตอนนี้ สคริปต์ผ่านการตรวจ syntax และโหมด Show แล้ว ยังไม่ได้รัน Repair บน production
