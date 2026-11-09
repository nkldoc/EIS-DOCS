<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
Auth::requireLogin();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>คู่มือ EIS-DOCS</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;color:#203047;background:#f4f7fb;margin:0;line-height:1.75}header{background:#1766bd;color:white;padding:22px max(20px,calc((100vw - 960px)/2))}header h1{margin:0}main{max-width:960px;margin:24px auto;padding:0 18px 48px}nav,.card{background:white;border:1px solid #dce5ef;border-radius:12px;padding:20px;margin-bottom:18px}nav a{display:inline-block;margin:4px 18px 4px 0;color:#1260ad}h2{color:#145b9e;margin-top:0}a{color:#1260ad}li{margin:5px 0}.note{background:#eef5ff;padding:12px;border-radius:8px}.guide-image{display:block;width:100%;max-width:760px;height:auto;border:1px solid #dce5ef;border-radius:8px;margin:16px 0}.guide-caption{font-size:13px;color:#52657a}button{background:#1766bd;color:white;border:0;border-radius:7px;padding:9px 15px;cursor:pointer}@media print{header{background:white;color:#203047}nav,button{display:none}body{background:white}.card{border:0;break-inside:avoid}}
</style>
</head>
<body>
<header><h1>คู่มือ EIS-DOCS</h1><div>ระบบสนับสนุนเอกสารและลงนาม</div></header>
<main>
<nav aria-label="สารบัญ"><a href="#create">จัดทำเอกสาร</a><a href="#list">หน้าเอกสาร</a><a href="#pending">เอกสารที่รอเซ็น</a><a href="#pin">PIN และการติดตาม</a><button type="button" onclick="print()">พิมพ์คู่มือ</button></nav>
<section class="card" id="create">
<h2>1. จัดทำเอกสาร</h2>
<p>เลือกเมนู <strong>จัดทำเอกสาร</strong> ทางซ้าย หน้าจอ <strong>PR Document — Signature Template Setup</strong> แบ่งงานเป็น 4 ขั้น: ข้อมูลหลัก → กลุ่มผู้ลงนาม → วางตำแหน่ง → บันทึก ใช้ปุ่ม <strong>ถัดไป</strong> และ <strong>ย้อนกลับ</strong> ด้านล่างเพื่อเปลี่ยนขั้น</p>
<img class="guide-image" src="guide-steps.svg" alt="แถบขั้นตอนจัดทำเอกสาร หมายเลข 1 ข้อมูลหลัก หมายเลข 2 กลุ่มผู้ลงนาม หมายเลข 3 วางตำแหน่ง และหมายเลข 4 บันทึก">
<p class="guide-caption"><strong>1</strong> กรอกข้อมูลหลัก → <strong>2</strong> เลือกกลุ่มผู้ลงนาม → <strong>3</strong> วางตำแหน่งบน PDF → <strong>4</strong> ตรวจและบันทึก</p>
<h3>ขั้นที่ 1 ข้อมูลหลัก</h3>
<ol>
<li>ที่ <strong>ข้อมูลที่บันทึกใน EIS_DOCS</strong> เลือก <strong>สร้างรายการใหม่</strong> หรือกด <strong>＋ ใหม่</strong> หากต้องการเริ่มใหม่ หากต้องการแก้รายการเดิมให้เลือกรายการที่บันทึกไว้ก่อน</li>
<li>เลือก <strong>ชนิดเอกสาร</strong> เช่น PR ใบขอซื้อ/ขอจ้าง, PO ใบสั่งซื้อ, GR ใบรับสินค้า หรือ IV ใบแจ้งหนี้</li>
<li>ค้นหา <strong>เอกสาร PR</strong> ด้วยเลข PR หรือชื่อเรื่องแล้วเลือกจากผลค้นหา หากไม่พบให้ทำเครื่องหมาย <strong>ไม่พบ PR — ระบุเลขที่อ้างอิงและชื่อรายการเอง</strong> แล้วกรอกทั้งสองช่อง</li>
<li>กรอก <strong>ชื่อเอกสารลงนาม</strong> ซึ่งเป็นช่องบังคับ เพิ่ม <strong>รายละเอียด</strong> และ <strong>File on server</strong> เมื่อมีพาธ PDF บนเซิร์ฟเวอร์</li>
<li>เลือกสถานะ Template: <strong>Draft</strong>, <strong>Active</strong> หรือ <strong>Inactive</strong> ให้ตรงกับการใช้งาน</li>
<li>ในหัวข้อ <strong>การทำเอกสารจัดซื้อ</strong> เลือกหัวข้อเอกสารที่ต้องจัดทำ ตรวจจำนวนรายการและเลขหน้า หากมีชุดเดิมให้เลือก <strong>ชุด Template ของฉัน / หน่วยงาน</strong> แล้วตรวจหัวข้ออีกครั้ง สามารถบันทึกเป็นชุดใหม่หรืออัปเดตชุดเดิมตามสิทธิ์</li>
</ol>
<h3>ขั้นที่ 2 กลุ่มผู้ลงนาม</h3>
<ol>
<li>ค้นหาชื่อกลุ่มผู้ลงนาม เลือกจากรายการ แล้วกด <strong>+ เลือกกลุ่ม</strong> หรือกรอกชื่อเพื่อ <strong>+ สร้างกลุ่มใหม่</strong> โดยกำหนดการมองเห็นเฉพาะตัว หน่วยงาน หรือทั้งหมด</li>
<li>ตรวจสมาชิกแต่ละคน ลำดับ WF และสิทธิ์ตรวจสอบ ลงนาม หรืออนุมัติ ให้ตรงกับเส้นทางงานก่อนดำเนินการต่อ</li>
<li>หากงานใช้รูปแบบมาตรฐานที่แสดงบนหน้าจอ สามารถเลือก <strong>ใช้ข้อมูลเริ่มต้น WF1 / WF2</strong> แล้วตรวจชื่อและตำแหน่งทุกคนอีกครั้ง</li>
</ol>
<h3>ขั้นที่ 3 วางตำแหน่ง</h3>
<ol>
<li>กด <strong>อัปโหลด PDF ก่อนวาง</strong> เลือกไฟล์ต้นฉบับ แล้วตรวจจำนวนหน้าและทิศทางกระดาษ</li>
<li>โหลดกลุ่มผู้ลงนามจากขั้นที่ 2 หรือ Template ตำแหน่งที่เคยบันทึก ตรวจว่าชื่อสมาชิกตรงกับเอกสารฉบับนี้</li>
<li>วางและปรับขนาดกล่องลายเซ็นของแต่ละคนบนหน้าที่ถูกต้อง ตรวจชื่อ ตำแหน่ง หน่วยงาน และข้อความในกล่อง ใช้ปุ่ม <strong>แก้ไข</strong> เมื่อจำเป็น</li>
<li>หากมีหลายส่วนของเอกสาร ให้กำหนดจุดคั่นหน้า Bookmark และเลขหน้า ตรวจด้วย <strong>ดูตัวอย่างจริง</strong> ก่อนกดถัดไป</li>
</ol>
<h3>ขั้นที่ 4 บันทึก</h3>
<ol>
<li>ตรวจ <strong>ข้อมูล Template</strong>, จำนวนกล่องลายเซ็น และ <strong>กลุ่มผู้ลงนาม</strong> ในหน้าสรุป</li>
<li>เลือก <strong>วันที่ของการลงนาม</strong>: ไม่แสดงวันที่, ระบุวันที่, ใช้วันเซ็นจริง หรือใช้วันเซ็นจริงโดยจำกัดไม่ให้เกินวันที่ระบุ กรอกวันที่เมื่อเลือกแบบที่ต้องระบุ</li>
<li>หากต้องการเก็บค่าจัดวางไว้ใช้ซ้ำ ให้เลือก <strong>บันทึกลงเครื่อง</strong> หรือ <strong>บันทึกใน Memories</strong> แล้วกด <strong>บันทึกและสร้าง PDF</strong> รอข้อความยืนยันก่อนออกจากหน้า</li>
<li>เปิด <strong>หน้าเอกสาร</strong> เพื่อตรวจสถานะและ PDF ที่สร้าง หรือเปิด <strong>เอกสารที่รอเซ็น</strong> เพื่อตรวจงานของบัญชีตนเอง</li>
</ol>
<p class="note">เมนูจัดทำเอกสารแสดงตามสิทธิ์ของหน่วยงานหรือผู้ดูแลระบบ ควรตรวจ PDF ต้นฉบับ ลำดับผู้ลงนาม และตำแหน่งทุกครั้งก่อนบันทึก</p>
</section>
<section class="card" id="list"><h2>2. หน้าเอกสาร</h2><p>ใช้ค้นหาเอกสาร ดูสถานะ ผู้ดำเนินการปัจจุบัน และรายการที่เสร็จแล้ว กด <strong>ดูรายละเอียด</strong> เพื่อตรวจเส้นทางเอกสาร เมื่อดำเนินการครบแล้วให้ใช้ปุ่ม <strong>ดู PDF ที่ลงนาม</strong> หากปรากฏ</p></section>
<section class="card" id="pending"><h2>3. เอกสารที่รอเซ็น</h2><ol><li>เปิดแท็บ <strong>3.1 เอกสารที่รอเซ็น</strong> แล้วค้นหาด้วยเลขที่เอกสาร ชื่อรายการ หรือผู้ขอซื้อ</li><li>ใช้ตัวกรองชนิดเอกสาร และกด <strong>โหลดข้อมูลใหม่</strong> เมื่อรายการยังไม่ปรากฏ</li><li>เลือกรายการ เปิดรายละเอียดและ PDF แล้วตรวจเนื้อหาก่อนดำเนินการตามสิทธิ์ที่แสดง</li><li>หากข้อมูลไม่ถูกต้อง ให้ทักท้วงหรือส่งกลับพร้อมเหตุผลตามปุ่มบนหน้าจอ</li></ol><p>แท็บ <strong>3.2 เอกสารที่ต้องตรวจสอบ</strong> และ <strong>3.3 เซ็นเอกสาร</strong> จะเปิดใช้งานตามรายการและขั้นตอนที่เลือก</p></section>
<section class="card" id="pin"><h2>4. PIN และการติดตาม</h2><p>ก่อนลงนาม ให้ตั้ง PIN 6 หลักในเมนูโปรไฟล์และเก็บเป็นความลับ หากลืม PIN ให้ใช้ปุ่ม <strong>ลืม PIN / ขอปลดล็อก</strong> ในโปรไฟล์ หลังดำเนินการ ให้กลับไปที่หน้าเอกสารเพื่อตรวจสถานะล่าสุด</p></section>
<p><a href="index.php">← กลับไป EIS-DOCS</a></p>
</main>
</body>
</html>
