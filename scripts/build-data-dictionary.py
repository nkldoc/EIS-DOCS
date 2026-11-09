from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
schema = (root / 'database/production/01_EIS_DOCS_schema.sql').read_text(encoding='utf-8-sig')
descriptions = {
 'WF_USER_HDR': {'c_name':'ชื่อกลุ่มลำดับงาน','visibility':'ขอบเขตการมองเห็นกลุ่ม: all, department หรือ private','owner_user_id':'รหัสผู้ใช้เจ้าของกลุ่ม','owner_cost_id':'รหัสหน่วยงานเจ้าของกลุ่ม'},
 'WF_USER_DTL': {'user_id':'รหัสบัญชีผู้ใช้ในกลุ่ม','c_name':'ชื่อกลุ่มลำดับงาน อ้างอิง WF_USER_HDR','dc_emp_id':'รหัสบุคลากรใน NMU_DATACENTER','review_action':'อนุญาตให้ตรวจทานเอกสาร','sign_action':'อนุญาตให้ลงนาม','approve_action':'อนุญาตให้อนุมัติ','wf_order':'ลำดับขั้นตอนในกลุ่ม','signature_text_json':'ข้อความประกอบลายเซ็นในรูป JSON'},
 'DOCS_HDR': {'docs_id':'รหัสเอกสารหลัก','doc_no':'เลขที่เอกสาร','doc_type':'รหัสประเภทเอกสาร','doc_type_name':'ชื่อประเภทเอกสารที่แสดง','department':'ชื่อหน่วยงานผู้ขอ','requester_name':'ชื่อผู้ขอเอกสาร','doc_date':'วันที่เอกสาร','amount':'มูลค่าเอกสาร','pdf_doc_id':'รหัสอ้างอิงไฟล์ PDF','description':'รายละเอียดหรือหมายเหตุเอกสาร','status':'สถานะเอกสารในกระบวนงาน','created_at':'วันเวลาสร้างรายการ','updated_at':'วันเวลาแก้ไขล่าสุด','pr_tor_id':'รหัสรายการ PR/TOR ที่เชื่อมโยง','template_name':'ชื่อแม่แบบเอกสาร','file_path':'ตำแหน่งไฟล์เอกสาร','config_json':'ค่ากำหนดการสร้างเอกสารในรูป JSON','created_by':'รหัสผู้ใช้ที่สร้างเอกสาร','is_enabled':'สถานะเปิดใช้งานเอกสาร: 1 เปิด, 0 ปิด'},
 'DOCS_DTL': {'docs_dtl_id':'รหัสรายการขั้นตอนเอกสาร','docs_id':'รหัสเอกสารหลัก อ้างอิง DOCS_HDR','wf_order':'ลำดับขั้นตอน','user_id':'รหัสผู้ปฏิบัติในขั้นตอน','dc_emp_id':'รหัสบุคลากรผู้ปฏิบัติ','signer_name':'ชื่อผู้ลงนามที่แสดง','review_action':'ขั้นตอนนี้ต้องตรวจทาน','sign_action':'ขั้นตอนนี้ต้องลงนาม','approve_action':'ขั้นตอนนี้ต้องอนุมัติ','sign_status':'สถานะการดำเนินการของขั้นตอน','decision':'ผลการตัดสินใจ เช่น ยอมรับหรือปฏิเสธ','signed_at':'วันเวลาที่ดำเนินการลงนาม/ตัดสินใจ','rejection_reason':'เหตุผลที่ปฏิเสธ','review_marks_json':'ตำแหน่งหรือเครื่องหมายตรวจทานในรูป JSON'},
 'DOCS_SET': {'set_id':'รหัสชุดเอกสาร','set_name':'ชื่อชุดเอกสาร','document_ids':'รายการรหัสเอกสารในชุดในรูป JSON','created_by':'รหัสผู้ใช้ที่สร้างชุด','created_at':'วันเวลาสร้างชุด','ap_id':'รหัสรายการ AP ที่เชื่อมโยง','ap_code':'เลขที่หรือรหัส AP','ap_name':'ชื่อรายการ AP','ap_amount':'มูลค่า AP','contract_code':'เลขที่สัญญา'},
 'DOCS_NOTIFICATION': {'notification_id':'รหัสการแจ้งเตือน','user_id':'รหัสผู้รับการแจ้งเตือน','docs_id':'รหัสเอกสารที่เกี่ยวข้อง; ว่างได้สำหรับแจ้งเตือน PIN','notification_type':'ประเภทการแจ้งเตือน','wf_order':'ลำดับขั้นตอนที่เกี่ยวข้อง','title':'หัวข้อการแจ้งเตือน','message':'ข้อความแจ้งเตือน','created_at':'วันเวลาสร้างการแจ้งเตือน','read_at':'วันเวลาที่อ่านแล้ว','pin_request_id':'รหัสคำขอรีเซ็ต PIN ที่เกี่ยวข้อง'},
 'DOCS_DEPARTMENT_PERMISSION': {'dc_cost_id':'รหัสหน่วยงานที่ได้รับสิทธิ์สร้างเอกสาร','granted_by':'รหัสผู้ใช้ที่ให้สิทธิ์','granted_at':'วันเวลาให้สิทธิ์'},
 'DOCS_USER_PIN': {'user_id':'รหัสผู้ใช้เจ้าของ PIN','pin_hash':'ค่าแฮชของ PIN','failed_attempts':'จำนวนครั้งที่กรอก PIN ผิด','locked_until':'วันเวลาสิ้นสุดการล็อก','updated_at':'วันเวลาเปลี่ยน PIN หรือข้อมูลล่าสุด','reset_allowed':'อนุญาตให้ตั้ง PIN ใหม่','pin_version':'รุ่นของ PIN สำหรับตรวจสอบการเปลี่ยนแปลง'},
 'DOCS_PIN_RESET_REQUEST': {'request_id':'รหัสคำขอรีเซ็ต PIN','user_id':'รหัสผู้ใช้ที่ขอรีเซ็ต','status':'สถานะคำขอ: PENDING, APPROVED หรือ CONSUMED','requested_at':'วันเวลายื่นคำขอ','approved_by':'รหัสผู้อนุมัติ','approved_at':'วันเวลาอนุมัติ','consumed_at':'วันเวลาที่ใช้สิทธิ์รีเซ็ตแล้ว'},
}
extra = {
 'WF_USER_DTL': [('signature_text_json','nvarchar(max)','NULL')],
 'DOCS_HDR': [('is_enabled','bit','NOT NULL')],
 'DOCS_NOTIFICATION': [('pin_request_id','bigint','NULL')],
 'DOCS_DEPARTMENT_PERMISSION': [('dc_cost_id','int','NOT NULL'),('granted_by','nvarchar(100)','NOT NULL'),('granted_at','datetime2','NOT NULL')],
 'DOCS_USER_PIN': [('user_id','bigint','NOT NULL'),('pin_hash','varchar(255)','NOT NULL'),('failed_attempts','int','NOT NULL'),('locked_until','datetime2','NULL'),('updated_at','datetime2','NOT NULL'),('reset_allowed','bit','NOT NULL'),('pin_version','int','NOT NULL')],
 'DOCS_PIN_RESET_REQUEST': [('request_id','bigint IDENTITY','NOT NULL'),('user_id','bigint','NOT NULL'),('status','varchar(20)','NOT NULL'),('requested_at','datetime2','NOT NULL'),('approved_by','bigint','NULL'),('approved_at','datetime2','NULL'),('consumed_at','datetime2','NULL')],
}
lines = ['# Data Dictionary — EIS_DOCS', '', 'แหล่งข้อมูล: `database/production/01_EIS_DOCS_schema.sql` และ migration `database/EIS_DOCS/013`, `015`, `017`–`020` ณ 9 พฤศจิกายน 2569', '', 'คอลัมน์ “รายละเอียด” อธิบายความหมายตามชื่อฟิลด์และการใช้งานในโค้ด; ค่ารหัสบางชุดควรตรวจสอบกับข้อมูลจริงก่อนใช้เป็นข้อกำหนดทางธุรกิจ', '']
for table, meaning in descriptions.items():
    lines += [f'## {table}', '', '| ชื่อฟิลด์ | ชนิดข้อมูล | ค่าว่าง | รายละเอียด (ฟิลด์) |', '|---|---|---|---|']
    match = re.search(r'CREATE TABLE \[dbo\]\.\[' + table + r'\]\((.*?)\n CONSTRAINT|CREATE TABLE \[dbo\]\.\[' + table + r'\]\((.*?)\nPRIMARY KEY', schema, re.S)
    fields = []
    if match:
        block = next(g for g in match.groups() if g is not None)
        for line in block.splitlines():
            m = re.match(r'\s*\[([^]]+)\] \[([^]]+)\](?:\(([^)]*)\))?.*?\b(NOT NULL|NULL)\s*,?$', line)
            if m:
                name, typ, size, null = m.groups()
                fields.append((name, typ + (f'({size})' if size else ''), null))
    fields += extra.get(table, [])
    for name, typ, null in fields:
        lines.append(f'| `{name}` | `{typ}` | {"ไม่" if null == "NOT NULL" else "ได้"} | {meaning[name]} |')
    lines.append('')
(root / 'docs/DATA-DICTIONARY.md').write_text('\n'.join(lines), encoding='utf-8')
