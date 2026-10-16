# ฐานข้อมูล EIS-DOCS และข้อมูลตั้งต้น — 2 ตุลาคม 2569

## ฐานข้อมูลและตาราง

| ฐานข้อมูล | ตาราง | หน้าที่ | สิ่งที่อยู่ในชุดนี้ |
|---|---|---|---|
| EIS_DOCS | WF_USER_HDR | กลุ่มผู้ลงนาม เจ้าของกลุ่ม ขอบเขต private/department/all | โครงสร้าง + 6 กลุ่ม |
| EIS_DOCS | WF_USER_DTL | สมาชิกกลุ่ม ลำดับ WF สิทธิ์ตรวจสอบ/ลงนาม/อนุมัติ | โครงสร้าง + 48 แถว |
| EIS_DOCS | DOCS_HDR | เอกสาร PR ที่จัดทำ ข้อมูล PDF และ config ผังลายเซ็น | โครงสร้าง |
| EIS_DOCS | DOCS_DTL | ผู้ลงนามของเอกสารแต่ละฉบับ ผล/เวลาลงนาม และข้อทักท้วง | โครงสร้าง |
| EIS_DOCS | DOCS_SET | ชุดเอกสาร และข้อมูลอ้างอิง AP/สัญญา | โครงสร้าง |
| EIS_DOCS | DOCS_NOTIFICATION | แจ้งเตือนรายผู้ใช้และสถานะอ่าน | โครงสร้าง |
| NMU_DATACENTER | dc_user, dc_emp | บัญชี Login และข้อมูลพนักงานที่ระบบเดิมมีอยู่ | ไม่ export ต้องใช้ฐานข้อมูลจริง |
| NMU_ERP | sp_tor, sp_check_period_hdr, sp_check_period_dtl, sp_tor_contract | PR, AP และสัญญาของระบบเดิม | ไม่ export ต้องใช้ฐานข้อมูลจริง |
| NMU_EIS | — | datasource เริ่มต้นของ Java ตั้งผ่าน DB_EIS | ไม่ใช่ที่เก็บกลุ่ม/Workflow ชุดนี้ และไม่ได้ export |

รายการลงนามที่เป็นข้อมูลตั้งต้นอยู่ใน WF_USER_DTL ส่วน DOCS_DTL เป็นผล/เส้นทางของเอกสารจริง ไม่ควรนำข้อมูลทดสอบไปเริ่ม Production
Snapshot Local มี DOCS_HDR 3, DOCS_DTL 24, DOCS_SET 1, DOCS_NOTIFICATION 19 แถว ซึ่งไม่ได้รวมเป็นข้อมูลตั้งต้น

คอลัมน์เพิ่มเติมที่รวมแล้ว: DOCS_HDR.pr_tor_id/template_name/file_path/config_json/created_by,
DOCS_DTL.review_marks_json, WF_USER_HDR.visibility/owner_user_id/owner_cost_id,
DOCS_SET.ap_id/ap_code/ap_name/ap_amount/contract_code พร้อม indexes และ constraints

## ลำดับนำเข้าใน SSMS บน SQL Server Production

1. สำรองฐานข้อมูลเดิม แล้วรัน 01_EIS_DOCS_schema.sql ด้วย DBA เพื่อสร้างตารางหรือเพิ่มคอลัมน์ที่ขาด
2. ถ้าใช้บัญชีแอป eis_docs_app ให้ตั้งรหัสผ่านใน 02_SQL_login_permissions.sql แล้วรัน เพื่อกำหนดสิทธิ์ (ถ้าใช้ SQL Login ที่มีสิทธิ์อยู่แล้ว ขั้นตอนนี้ไม่จำเป็น)
3. รัน 03_WF_groups_seed.sql เพื่อเพิ่มกลุ่มและสมาชิก กลุ่มที่มีชื่ออยู่แล้วจะข้ามทั้งกลุ่ม ไม่ทับหรือเพิ่มสมาชิกซ้ำ
4. ตรวจผล IMPORTED/SKIPPED_EXISTING_GROUP เทียบกับ SIGNERS.md

Seed ตรวจ user_id และ dc_emp_id กับ NMU_DATACENTER ก่อนนำเข้า ถ้าไม่ตรงจะหยุดและ rollback ต้องตรวจ mapping บัญชีจริงก่อน ไม่แก้เลข ID แบบเดาสุ่ม
เก็บขอบเขตการมองเห็น เจ้าของ สิทธิ์ และเลข WF ตามต้นทางทั้งหมด รวมถึงเลข WF ที่มีช่องว่าง โดยไม่เปลี่ยนลำดับของต้นทาง
ไม่มีรหัสผ่านจริง บัญชี Login ผู้ใช้ ไฟล์ PDF รูปลายเซ็น หรือ Template ใน localStorage ในชุด export นี้
ไฟล์ PDF ที่ต้องย้ายเป็นข้อมูลการใช้งานจริงอยู่แยกจาก SQL ต้องย้าย storage ของระบบหากมีการย้ายเอกสารเดิม

## ผลตรวจสอบ

ทดสอบ schema และ seed ในฐานข้อมูลแยกบน Local: ได้ 6 กลุ่ม 48 สมาชิกตรงกับต้นทาง และรัน seed ซ้ำแล้วไม่เพิ่มข้อมูลซ้ำ
ยังไม่ได้รันบน Production
