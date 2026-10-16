# SQL Backup → ZIP → ดาวน์โหลดอัตโนมัติ

เลือกฐานข้อมูลใน SQL Manager → ใส่โฟลเดอร์ย่อยและชื่อ Backup (เว้นว่างได้) → กด **สร้าง .bak → ZIP → ดาวน์โหลด**

ระบบสร้างโฟลเดอร์ → ทำ full backup แบบ `COPY_ONLY, CHECKSUM` → เก็บ `.bak` → สร้าง ZIP → ดาวน์โหลดแบบ stream พร้อมลิงก์ดาวน์โหลดซ้ำอายุ 1 ชั่วโมงและผูกกับเซสชัน Admin ลิงก์หมดอายุไม่ได้ลบไฟล์บนเซิร์ฟเวอร์

เมื่อเลือก Database ระบบเติมโฟลเดอร์ `backup-ชื่อฐานข้อมูล` และชื่อไฟล์ทันที (เช่น chatdb → backup-chatdb) เช่น `EIS_DOCS` → โฟลเดอร์ `backup-EIS-DOCS` และชื่อไฟล์ `EIS-DOC-20261014-153000` เวลาใช้ Asia/Bangkok แก้ชื่อเองได้ หากยังใช้ชื่ออัตโนมัติระบบอัปเดตเวลาอีกครั้งเมื่อกด Backup ชื่อที่กรอกใช้เป็นชื่อไฟล์จริงโดยไม่เติมเวลาอีกชุด; เว้นว่างเพื่อใช้ค่าอัตโนมัติ แต่ละครั้งมีโฟลเดอร์แยกพร้อมรหัสสุ่ม ไม่ทับ Backup ครั้งก่อน ชื่อที่กรอกใช้ A-Z, a-z, 0-9, _ หรือ - สูงสุด 80 ตัวอักษร ไม่ใส่นามสกุลหรือ path เต็มในหน้าเว็บ

ตัวอย่างไฟล์ที่สร้าง:

```text
D:\EIS-SQL-Backups\backup-EIS-DOCS\production-20261014-153000-a1b2c3d4e5f6\EIS-DOC-20261014-153000.bak
D:\EIS-SQL-Backups\backup-EIS-DOCS\production-20261014-153000-a1b2c3d4e5f6\EIS-DOC-20261014-153000.zip
```

## ติดตั้งไฟล์

สำรองไฟล์เดิมก่อน แล้วคัดลอกไฟล์ใน `app/` ของชุดติดตั้งไปยัง EIS-DOCS app จริง โดยรักษาโครงสร้างโฟลเดอร์:

- `src/SqlBackup.php`
- `public/docMain/sql-manager.php`
- `public/docMain/sql-manager.js`
- `public/docMain/sql-manager.css`
- `public/docMain/sql-manager-api.php`
- `public/docMain/sql-manager-download.php`

คัดลอก `scripts/Setup-SqlBackup.ps1` ไปทั้งเครื่อง SQL และเว็บ ต้องเปิด PHP extension `zip` และใช้ PHP 8 ตามแอปเดิม

## ตั้งค่าครั้งเดียว: SQL และเว็บคนละเครื่อง

### Workgroup: บัญชี Backup พร้อมชื่อ ไม่ต้องมี AD

เมื่อ Get-SqlBackupShareCommand.ps1 แจ้งว่า doc-eis ไม่อยู่ AD ให้ใช้บัญชีเฉพาะ `eis_backup` ชื่อและรหัสผ่านเดียวกันทั้งสองเครื่อง คัดลอก scripts จากชุดล่าสุดไปที่ `D:\EIS-DOCS\scripts` ทั้งสองเครื่อง และเปิด Administrator PowerShell

บนเครื่อง SQL `192.168.201.3`:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackupAccount.ps1' -Mode SqlServer -AccountName 'eis_backup' -UpdateExistingAccount
```

บนเครื่องเว็บ `doc-eis`:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackupAccount.ps1' -Mode WebServer -AccountName 'eis_backup' -UpdateExistingAccount
```

ทั้งสองคำสั่งถามรหัสแบบซ่อนค่า ใช้รหัสเดียวกัน ไม่ต้องใส่รหัสในคำสั่งหรือไฟล์ โหมด SQL ตรวจหาบัญชี SQL service แล้วให้บัญชี Backup เข้าถึง share `EISSqlBackup$` และ NTFS ของ `D:\EIS-SQL-Backups` บัญชีนี้ไม่ได้เพิ่มเข้า Administrators

โหมดเว็บให้สิทธิ์อ่านแอป/เขียน storage และ PHP session directory, ตรวจ UNC `\\192.168.201.3\EISSqlBackup$` ด้วย credentials ของบัญชี Backup จริง ก่อนบันทึก config และตั้ง pool เป็น SpecificUser พร้อม recycle pool รหัสเข้าระบบ IIS ผ่าน API ไม่ส่งเป็น command-line หรือเขียนใน config ของแอป มี IIS rollback backup ก่อนเปลี่ยน pool สคริปต์ปฏิเสธการเปลี่ยน pool ที่ใช้ร่วมกับแอปอื่น

หากแจ้ง `This pool also runs another application` ให้ใช้รุ่นล่าสุดและรัน:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackupAccount.ps1' `
  -Mode WebServer `
  -AccountName 'eis_backup' `
  -UpdateExistingAccount `
  -CreateDedicatedPool
```

ตัวเลือกนี้สร้าง pool ใหม่ `EIS-DOCS-Backup`, คัดลอกการตั้งค่า pool เดิม (รวม runtime/32-bit/recycling/environment variables), ตั้งบัญชี Backup เฉพาะ pool ใหม่ และย้ายเฉพาะแอปที่ชี้ EIS-DOCS public เดิม Pool/แอปอื่นคงอยู่เดิม ตรวจ share ก่อน commit และเก็บ IIS rollback backup ชื่อที่แสดงโดยสคริปต์ รหัสให้กรอกแบบซ่อนค่าตามเดิม การใส่รหัสตรงในคำสั่งไม่ได้แก้ข้อผิดพลาดเรื่อง shared pool

หาก pool ใหม่ชื่อนี้มีอยู่แล้วแต่ยังไม่ใช่ pool ของ EIS-DOCS สคริปต์จะหยุด สามารถระบุชื่อใหม่ด้วย `-DedicatedPoolName 'ชื่อใหม่'` ได้ ไม่เขียนทับ pool ที่มีอยู่

หากหา `Microsoft.Web.Administration.dll` ไม่พบ ให้รัน `hostname` และ `[Environment]::Is64BitProcess` บนหน้าต่างที่เกิด error โหมด WebServer ต้องอยู่ doc-eis/10.10.10.6 และใช้ PowerShell 64-bit รุ่นล่าสุดตรวจสิ่งนี้ก่อนถามรหัสและค้นหา DLL ใน System32 หรือ GAC หากเป็น NMU-EIS-DB/10.10.10.3 ให้สลับเครื่อง หากเป็น 32-bit ให้เปิด PowerShell 64-bit แบบ Administrator ก่อน ไม่ติดตั้ง IIS เพิ่มบนเครื่องฐานข้อมูลเพื่อแก้ error นี้

หาก PowerShell ISE หยุดด้วย `Module mysqli is already loaded` ขณะอ่าน session path ให้ใช้สคริปต์รุ่นล่าสุดพร้อม `Get-SqlBackupPhpSessionDirectory.ps1` รุ่นนี้เรียก PHP ด้วย Process API แยก stdout/stderr ตรวจ exit code และ JSON จึงไม่เอา startup warning มาปนกับ path หรือยกเป็น NativeCommandError ข้อผิดพลาด PHP จริงยังหยุดงาน มี timeout 15 วินาที ขั้นอ่าน session เกิดก่อน commit IIS ดังนั้นข้อความ `Prepared dedicated pool` ก่อน error ยังไม่ได้แปลว่าสร้าง pool บน IIS สำเร็จ

หากบันทึก identity/config แล้วแต่ `recycle` ล้มเหลวด้วย `800710d8` ให้ตรวจ `appcmd list apppool "EIS-DOCS-Backup"` หาก Stopped ใช้ `appcmd start apppool /apppool.name:"EIS-DOCS-Backup"` ไม่ต้องตั้งบัญชี/รหัสซ้ำ รุ่นล่าสุดตรวจ state ก่อนและเลือก start สำหรับ Stopped, recycle สำหรับ Started

### Login แสดงคำขอหมดอายุหลังเปลี่ยน pool

ข้อความนี้เกิดจาก login CSRF ไม่ตรงกับ PHP session ก่อนตรวจรหัสผู้ใช้ อาจเป็นหน้าเก่าหรือสิทธิ์/ตำแหน่งเก็บ session ของ process ใหม่ ให้ติดตั้ง `app/src/bootstrap.php`, `app/public/login.php` และไฟล์ใน `scripts` รุ่นล่าสุดก่อน บนเครื่องเว็บ 6 เปิด Administrator PowerShell:

```powershell
& 'D:\EIS-DOCS\scripts\Repair-SqlBackupLogin.ps1' -Connection production
```

สคริปต์ค้นหา pool ของ EIS-DOCS จริง, สร้าง private session directory `D:\EIS-DOCS\storage\php-sessions`, ให้ Modify แก่บัญชี pool/SID, สำรอง IIS และ config เดิม, สร้าง `app/config/session.production.php`, และตั้ง Anonymous Authentication ของ EIS-DOCS ที่เปิดใช้อยู่ให้ใช้ pool identity (เพื่อรองรับ PHP FastCGI impersonation) จากนั้น recycle เฉพาะ pool นี้ ไม่เปลี่ยน authentication ของแอปอื่น ไม่ลบ session เดิม และไม่แก้รหัสผู้ใช้

ปิดแท็บ login เดิม เปิด InPrivate/Incognito แล้ว login ด้วยบัญชีผู้ใช้แอปเดิม ผู้ใช้ที่เคยมี session อาจต้อง login ใหม่เพราะเปลี่ยนโฟลเดอร์ session หากใช้ environment `EIS_DOCS_SESSION_DIR` อยู่ ต้องชี้ไปโฟลเดอร์เดียวกันหรือเอาค่า override เก่าออก เพราะ environment มีลำดับก่อน config

ทดสอบการคงค่า login-CSRF ข้ามสอง PHP process และการแจ้งข้อผิดพลาด storage ไม่พร้อมผ่านแล้ว ยังไม่ได้ยืนยัน login บน production หลังรัน repair

### UNC share: บัญชีเว็บต้องอ่านและเขียนโฟลเดอร์ Backup ได้

ข้อความเดิมมาจาก directory metadata `is_readable/is_writable` หลังสร้างโฟลเดอร์ request รุ่นล่าสุดเปลี่ยนเป็นสร้างไฟล์ชั่วคราวแบบ exclusive, เขียน/flush/อ่านกลับ แล้วลบจริงใน request เดียวกับ PHP เพื่อไม่ใช้ผลตรวจ permission metadata เป็นข้อสรุปอย่างเดียว การเปิดอ่าน `.bak` และ download ก็ตรวจด้วยการเปิดไฟล์จริง ข้อผิดพลาดแยกขั้นสร้าง/อ่านเขียน/ลบ และลง `storage/logs/php-error.log` โดยไม่เก็บ credentials

ติดตั้ง `app/src/SqlBackup.php` และ `app/public/docMain/sql-manager-download.php` รุ่นล่าสุด แล้วลอง Backup ใหม่ ไม่ต้องตั้งบัญชีหรือ recycle ทุก IIS pool หาก actual probe ยังล้มเหลว ให้ตรวจสิทธิ์บนเครื่อง SQL:

```powershell
icacls 'D:\EIS-SQL-Backups' /grant 'NMU-EIS-DB\eis_backup:(OI)(CI)M'
Grant-SmbShareAccess -Name 'EISSqlBackup$' -AccountName 'NMU-EIS-DB\eis_backup' -AccessRight Change -Force
```

รันบน NMU-EIS-DB/เครื่อง 3 แบบ Administrator คำสั่งนี้ไม่เปลี่ยนรหัส หากยังไม่ผ่าน ส่งเฉพาะ log ที่ขึ้น `SQL backup storage` จากเครื่องเว็บ 6 เพื่อตรวจจริงต่อ

โหมด WebServer ค้นหา pool จาก root virtual directory ของแอปอัตโนมัติ โดยไม่สมมติชื่อ EIS-DOCS หากต้องระบุเองให้ใช้ชื่อที่ `appcmd list app` แสดง หาก PHP อยู่คนละตำแหน่งให้ระบุ `-PhpExecutable 'path\php.exe'` หรือ `-SessionDirectory 'path\sessions'` สำหรับ session directory จริง

`-UpdateExistingAccount` ใช้ซ้ำ/ตั้งรหัสใหม่ได้เฉพาะบัญชีที่สคริปต์นี้สร้างและติด description ไว้ ไม่เขียนทับบัญชีอื่น หลังรันทดสอบเลือก Production → Backup ZIP รวมถึง login/เอกสาร/Messenger เพราะเว็บเปลี่ยน process identity สคริปต์ยังต้องให้ Windows อนุญาต SMB authentication ของ local accounts และ TCP 445; ไม่เปลี่ยนนโยบาย domain/NTLM/Guest อัตโนมัติ

ผลทดสอบ: PowerShell parse และทดสอบบัญชี/credentials/IIS แบบ mock ผ่าน ยังไม่ได้สร้างบัญชี เปลี่ยน IIS หรือทดสอบ share บน production จริง

### ใช้บัญชีเครื่องเว็บ โดยไม่ตั้งรหัสผ่านในโปรแกรม

หาก doc-eis และเครื่อง SQL ใช้ AD domain/trust และ IIS App Pool เป็น ApplicationPoolIdentity หรือ NetworkService ให้รันบน **doc-eis**:

```powershell
& 'D:\EIS-DOCS\scripts\Get-SqlBackupShareCommand.ps1'
```

สคริปต์อ่านชื่อ domain และชื่อเครื่องเว็บจริง แล้วแสดงคำสั่ง SqlServer และ WebServer ที่ใส่ชื่อบัญชีและ share ครบแล้ว คัดลอกคำสั่งแรกไปรันบน SQL 192.168.201.3 และคำสั่งที่สองรันบน doc-eis ชื่อ share คือ `EISSqlBackup$`, SQL path คือ `D:\EIS-SQL-Backups`, web path คือ `\\192.168.201.3\EISSqlBackup$` วิธีนี้คงบัญชี IIS เดิม ไม่ต้องใช้ Setup-SqlBackupAccount.ps1

ถ้าเครื่องเว็บเป็น Workgroup สคริปต์จะหยุด เพราะบัญชีเครื่องแบบ AD ใช้ไม่ได้ หาก IIS ใช้ SpecificUser ให้ใช้บัญชี domain service ที่ตั้งไว้จริงแทนบัญชีเครื่อง

บน **เครื่อง SQL Server 192.168.201.3** เปิด Administrator PowerShell รันสคริปต์นี้ เปลี่ยนชื่อบัญชีตัวอย่างเป็นบัญชีจริงก่อน:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackup.ps1' `
  -Mode SqlServer `
  -SqlDirectory 'D:\EIS-SQL-Backups' `
  -ShareName 'EISSqlBackup$' `
  -SqlServiceAccount 'NT SERVICE\MSSQLSERVER' `
  -WebServiceAccount 'DOMAIN\eis-web-service'
```

สคริปต์สร้างโฟลเดอร์, ให้ NTFS Modify กับ SQL service และบัญชีเว็บ, สร้าง share และให้ Change เฉพาะบัญชีเว็บที่ระบุ ถ้า share ชื่อเดิมชี้โฟลเดอร์อื่นจะหยุด ไม่เปลี่ยนปลายทางเดิม

SQL service account ดูใน SQL Server Configuration Manager หรือ `Get-CimInstance Win32_Service` บัญชีเว็บต้องเป็นตัวตนที่ IIS ใช้เชื่อมต่อ share จริง (บัญชี domain service หรือ machine account ที่ตั้งค่าไว้) ไม่ใช่แค่ชื่อ app pool ท้องถิ่นจากอีกเครื่อง ไม่มีการเก็บรหัสผ่านใน config

บน **เครื่องเว็บ doc-eis.nmu.ac.th** เปิด Administrator PowerShell:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackup.ps1' `
  -Mode WebServer `
  -Connection production `
  -AppDirectory 'D:\EIS-DOCS\app' `
  -SqlDirectory 'D:\EIS-SQL-Backups' `
  -WebDirectory '\\192.168.201.3\EISSqlBackup$'
```

`AppDirectory` ต้องเป็นโฟลเดอร์ app ที่ใช้งานจริงใน IIS `SqlDirectory` คือ path บนเครื่อง SQL ส่วน `WebDirectory` ต้องชี้โฟลเดอร์เดียวกันผ่าน UNC จากเครื่องเว็บ สคริปต์ตรวจอ่าน/เขียนโดยบัญชีที่รันสคริปต์ แล้วสร้าง `app/config/backup.production.php` อัตโนมัติ พร้อมสำรอง config เดิมถ้ามี ทดสอบกด Backup ในหน้าเว็บอีกครั้งเพื่อตรวจสิทธิ์บัญชี IIS จริง

บัญชี SQL service ต้องเขียนปลายทางได้ตาม [ข้อกำหนด BACKUP ของ Microsoft](https://learn.microsoft.com/en-us/sql/t-sql/statements/backup-transact-sql?view=sql-server-ver17) สิทธิ์บัญชี SQL login และสิทธิ์ filesystem ของ service เป็นคนละส่วน

## SQL และเว็บเครื่องเดียวกัน / Local

บนเครื่อง Local เปิด Administrator PowerShell แล้วใช้คำสั่งเดียวเพื่อสร้างโฟลเดอร์บน D:, ตรวจหาบัญชี SQL/IIS, ให้สิทธิ์ และสร้าง config:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackup.ps1' -Mode SameServer -Connection local
```

สำหรับ Production ที่ SQL และเว็บอยู่เครื่องเดียวกัน เปลี่ยนเป็น `-Connection production` (รับ `product` เป็น alias ด้วย) หากตรวจ IIS pool อัตโนมัติไม่ได้ ระบุ `-ApplicationPool 'ชื่อ Pool จริง'` หรือ `-WebServiceAccount 'บัญชีเว็บจริง'` หากเป็น named SQL instance ระบุ `-SqlInstanceService 'MSSQL$ชื่อInstance'`

ถ้าพบ `Cannot identify one EIS-DOCS IIS pool` ให้ดูชื่อ pool จริงด้วย `& "$env:windir\System32\inetsrv\appcmd.exe" list app` แล้วรันคำสั่งเดิมเพิ่ม `-ApplicationPool 'ชื่อ pool'` สคริปต์ติดตั้ง Local ในโปรเจกต์ใช้ชื่อ `EIS-DOCS` ตัวอย่าง:

```powershell
& 'D:\EIS-DOCS\scripts\Setup-SqlBackup.ps1' -Mode SameServer -Connection local -SqlDirectory 'D:\EIS-SQL-Backups' -AppDirectory 'D:\EIS-DOCS\app' -ApplicationPool 'EIS-DOCS'
```

สคริปต์เพิ่มสิทธิ์รุ่นนี้มี fallback อ่าน PhysicalPath จาก root virtual directory ของ IIS โดยตรง เมื่อ WebAdministration ไม่ส่ง path ในรายการ applications

ไฟล์ `Grant-SqlBackupPermissions.ps1` ให้สิทธิ์ Modify กับโฟลเดอร์ `D:\EIS-SQL-Backups` และสืบทอดไปโฟลเดอร์/ไฟล์ใหม่ สิทธิ์เดิมยังอยู่ ก่อนเปลี่ยนจะเก็บ SDDL เดิมใน `%ProgramData%\EIS-SqlBackup\acl-backups` ไม่แก้ ACL ของไดรฟ์ D: ทั้งไดรฟ์หรือโฟลเดอร์ลูกเก่าที่ปิด inheritance และไม่ต้องสร้าง SMB share สำหรับ SameServer

ถ้าต้องการเฉพาะเพิ่มสิทธิ์ โดยไม่เปลี่ยน config:

```powershell
& 'D:\EIS-DOCS\scripts\Grant-SqlBackupPermissions.ps1' -Connection local -Role SameServer
```

Production ของภาพใช้ SQL 192.168.201.3 แยกจากเว็บ จึงต้องรันการเพิ่มสิทธิ์บน **เครื่อง SQL** และใช้บัญชีเว็บที่เข้าถึง share ได้จริง:

```powershell
& 'D:\EIS-DOCS\scripts\Grant-SqlBackupPermissions.ps1' `
  -Connection production -Role SqlServer `
  -WebServiceAccount 'DOMAIN\eis-web-service'
```

จากนั้นตั้ง SMB share และ config ตามส่วนเครื่องแยกด้านบน การให้ NTFS อย่างเดียวไม่แทนสิทธิ์ share สคริปต์แต่ละโหมดรันเฉพาะเครื่องที่เปิด PowerShell ไม่เปลี่ยนเครื่องอื่นผ่านเครือข่าย

ใช้ปลายทางเดียวกันทั้ง SQL และเว็บ เช่น `D:\EIS-SQL-Backups` รัน WebServer mode โดยตั้ง `-WebDirectory 'D:\EIS-SQL-Backups'` และเลือก `-Connection local` สำหรับ Local (หรือ production สำหรับ production บนเครื่องเดียวกัน) ต้องให้ SQL service และ IIS identity อ่าน/เขียนโฟลเดอร์นี้ด้วย สคริปต์ WebServer mode รับ `-WebServiceAccount` เพื่อให้ NTFS Modify กับบัญชีเว็บได้ ส่วน SQL service ใช้โหมด SqlServer หรือกำหนด NTFS บนโฟลเดอร์โดยตรง

## Config และตรวจข้อผิดพลาด

แยก config Local/Production เป็น `backup.local.php` และ `backup.production.php` environment variables `SQL_BACKUP_LOCAL_SQL_DIR`, `SQL_BACKUP_LOCAL_WEB_DIR`, `SQL_BACKUP_PRODUCTION_SQL_DIR`, `SQL_BACKUP_PRODUCTION_WEB_DIR` มีลำดับก่อน config หากตั้งอยู่ต้องตรวจว่าไม่ได้ชี้ค่าเก่า ส่วน `SQL_BACKUP_SQL_DIR`/`SQL_BACKUP_WEB_DIR` เดิมใช้ได้เฉพาะ connection ที่ตรงกับ environment ของเว็บ

หากสำรองไม่สำเร็จ ตรวจ `storage/logs/php-error.log` และ SQL Server log; audit การเริ่ม/สำเร็จอยู่ใน `storage/logs/sql-console.jsonl` หาก ZIP ล้มเหลว ระบบเก็บ `.bak` ไว้ให้แก้ไขต่อ ไฟล์สำรองอยู่ภายนอก public และดาวน์โหลดผ่านการตรวจ Admin/เซสชันเท่านั้น

## ผลทดสอบ

แก้ข้อผิดพลาดที่พบจริงวันที่ 14 ตุลาคม: log เดิมมี SQLSTATE `01000`, code `4035` (Processed pages) แต่ `sqlsrv_query` คืนผลล้มเหลวและหน้าจอแสดงเรื่องสิทธิ์แบบรวม ทั้งที่ SQL login มี `BACKUP DATABASE` และ NTFS ถูกต้อง เปลี่ยนเป็น prepared statement + execute, ปิด `WarningsReturnAsErrors` เฉพาะช่วง Backup, รับทุก result จนครบ แล้วคืนค่าเดิมก่อน ZIP ข้อผิดพลาดจริงยังหยุดงาน และแยกข้อความ SQL permission / OS error 3, 5, 112 พร้อม log

อ้างอิงการตั้งค่าไดรเวอร์: [Microsoft SQLSRV error/warning handling](https://learn.microsoft.com/en-us/sql/connect/php/how-to-configure-error-and-warning-handling-using-the-sqlsrv-driver)

ทดสอบ Backup `EIS_DOCS` บน Local จริงผ่าน: `.bak` 12,673,536 ไบต์ → ZIP 1,361,580 ไบต์ และเปรียบเทียบ SHA-256 ของไฟล์ใน ZIP กับ `.bak` ตรงกัน ค่า warning handling ถูกคืนค่าเดิม ยังไม่ได้ทดสอบ production

- PHP 8.2 lint ผ่านทุกไฟล์ที่เปลี่ยน และ PowerShell parse ผ่าน
- ทดสอบ helper จริง: path local/UNC, ชื่อไม่ถูกต้อง, โฟลเดอร์อัตโนมัติ, ชื่อซ้ำไม่ทับ, ZIP เปิดอ่านกลับได้ตรงทุกไบต์, ไม่อนุญาตปลายทางใน public
- ทดสอบ UI: ส่งโฟลเดอร์/ชื่อ, เริ่มดาวน์โหลดอัตโนมัติ, เก็บลิงก์เดิมเมื่อเปลี่ยนฐานข้อมูล/Backup ล้มเหลว, ปฏิเสธ path traversal
- ยังไม่ได้รัน Backup จริงบน production หรือเปลี่ยนสิทธิ์/share บนเซิร์ฟเวอร์
- ทดสอบสคริปต์สิทธิ์แบบ mock ผ่าน: ตรวจหาบัญชี SQL/IIS, Local/Product, เก็บ ACL เดิม, สืบทอด Modify และปฏิเสธ drive root/public/ไดรฟ์อื่น เซสชันปัจจุบันไม่มี Administrator จึงยังไม่ได้เปลี่ยน ACL จริง
