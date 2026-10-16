<?php
require_once('../conf/config.php');
require_once(__DIR__ . '/presence_common.php');
if (!isset($_SESSION['user_id'])) { header('Location: ./signin.php'); exit; }
if (!presence_admin()) { http_response_code(403); echo 'ไม่มีสิทธิ์เข้าถึงเมนูผู้ดูแลระบบ'; exit; }
header('Cache-Control: no-store');
session_write_close();
?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin · ผู้ใช้งานออนไลน์</title><link rel="stylesheet" href="../css/user-online.css"></head>
<body><header><div>NMU EIS / ผู้ดูแลระบบ</div><h1>ตรวจสอบผู้ใช้งานออนไลน์</h1><span>เวลาเข้า–ออก และระยะเวลาที่เชื่อมต่อระบบ</span></header>
<main><div id="connection" class="notice" role="status">กำลังเชื่อมต่อ WebSocket…</div>
<div class="cards"><div class="card">ออนไลน์ขณะนี้<strong id="online">—</strong></div><div class="card">ผู้ใช้ในช่วงวันที่<strong id="users">—</strong></div><div class="card">รอบเข้าใช้งาน<strong id="rounds">—</strong></div><div class="card">เวลารวมในช่วงวันที่<strong id="total">—</strong></div></div>
<section class="panel"><form id="filters" class="filters"><label>วันที่เริ่ม<input id="start" type="date" required></label><label>วันที่สิ้นสุด<input id="end" type="date" required></label><label>ค้นหาผู้ใช้<input id="search" placeholder="ชื่อ หรือรหัสผู้ใช้"></label><label>หน่วยงาน<select id="department"><option value="">ทุกหน่วยงาน</option></select></label><label>สถานะ<select id="status"><option value="">ทั้งหมด</option><option value="1">ออนไลน์</option><option value="0">ออฟไลน์</option></select></label><button class="active" type="submit">แสดงข้อมูล</button></form><p class="hint">ค้นหาและสถานะกรองรายการด้านล่าง · การ์ดสรุปแสดงทุกผู้ใช้ในช่วงวันที่ · เลือกช่วงได้สูงสุด 367 วัน</p><div id="filter-error" role="alert"></div></section>
<section class="panel"><div class="tabs"><button class="active" data-tab="users">ผู้ใช้งาน</button><button data-tab="history">ประวัติเข้า–ออก</button></div><div class="meta"><span id="range">—</span><span>ข้อมูลล่าสุด <span id="updated">—</span> · Asia/Bangkok</span></div><div class="scroll"><table><thead id="head"></thead><tbody id="rows"></tbody></table></div><div id="empty" hidden>ไม่พบข้อมูลตามตัวกรอง</div></section>
<footer>ออนไลน์ = มีแท็บ EIS เชื่อมต่ออย่างน้อยหนึ่งแท็บ · หลายแท็บ/อุปกรณ์นับเวลาครั้งเดียว · หน้า Admin นี้ใช้ดูข้อมูลและไม่เพิ่มเวลาออนไลน์ · เริ่มเก็บประวัติตั้งแต่เปิดใช้ระบบนี้</footer></main>
<script>window.EISPresenceConfig={tokenUrl:'./presence_token.php',observer:true};</script><script src="../js/presence-client.js"></script><script src="../js/user-online.js"></script></body></html>
