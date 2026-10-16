# Architecture

## Responsibilities

- **IIS**: TLS termination, static files, PHP FastCGI และ reverse proxy
- **PHP**: หน้าเว็บเดิม/utility endpoint และ helper ที่จำเป็น
- **Java**: REST API ใหม่ภายใต้ `/api/*` และ business logic
- **SQL Server**: ข้อมูลใน `NMU_EIS`, `NMU_ERP` และ `NMU_DATACENTER`

## Boundary rules

- Browser เรียกผ่าน IIS เท่านั้น
- Java API ไม่เปิดให้ภายนอกเข้าตรง
- ค่าเชื่อมต่อฐานข้อมูลมาจาก environment variables
- บัญชีฐานข้อมูลของ PHP และ Java ควรแยกกันใน production
- การเชื่อมต่อข้ามฐานควรผ่าน view/stored procedure ที่กำหนดสิทธิ์ชัดเจน

