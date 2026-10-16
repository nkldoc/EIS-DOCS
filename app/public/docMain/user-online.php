<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
if (!Auth::check()) $_SESSION['docs_login_return'] = 'docMain/user-online.php';
Auth::requireLogin();
if (!DocumentAccess::isAdministrator() || Auth::impersonating()) { http_response_code(403); exit('ไม่มีสิทธิ์เข้าถึงเมนูผู้ดูแลระบบ'); }
if (!Presence::enabled()) { http_response_code(503); exit('ยังไม่ได้เปิดใช้งาน Presence สำหรับระบบนี้'); }
header('Cache-Control: no-store, private');
$_SESSION['app_csrf'] ??= bin2hex(random_bytes(32));
$presenceCsrf = $_SESSION['app_csrf'];
session_write_close();
?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ผู้ใช้ออนไลน์ · EIS-DOCS / Supplies</title><link rel="stylesheet" href="user-online.css?v=20261013-nmu-eis"></head>
<body><?php require __DIR__ . '/portal-navigation.php'; ?><header><a href="./" style="color:inherit">← กลับ EIS-DOCS</a><h1>ผู้ใช้งานออนไลน์ · EIS-DOCS / Supplies</h1><span>ติดตามการเข้าใช้งาน EIS-DOCS, Supplies, Procure และ NMU-EIS</span></header>
<main><div id="connection" class="notice" role="status">กำลังเชื่อมต่อ WebSocket…</div>
<section class="eis-chat-admin"><strong>💬 สนทนาและแจ้งข้อความ</strong><p>ส่งข้อความถึงห้องรวม หรือเลือก “แชทส่วนตัว” ในแถวผู้ใช้ออนไลน์ · ผู้ใช้จะเห็น dialog และแนบรูปหน้าจอกลับมาได้</p><button type="button" onclick="window.EISChat && EISChat.all()">เปิดห้องรวม / ส่งประกาศ</button> <a href="message-inbox.php">กล่องงาน · ตอบย้อนหลัง / ปิดงาน / ตอบหลายผู้ใช้ → <strong id="inbox-count"></strong></a></section>
<div class="cards"><div class="card">EIS-DOCS ออนไลน์ขณะนี้<strong id="docs-online">—</strong></div><div class="card">Supplies ออนไลน์ขณะนี้<strong id="supplies-online">—</strong></div><div class="card">ออนไลน์ตามระบบที่เลือก<strong id="online">—</strong></div><div class="card">บัญชี / ระบบในช่วงวันที่<strong id="users">—</strong></div><div class="card">รอบเข้าใช้งาน<strong id="rounds">—</strong></div><div class="card">เวลารวมตามระบบที่เลือก<strong id="total">—</strong></div></div>
<section class="panel"><form id="filters" class="filters"><label>ระบบ<select id="system"><option value="">ทุกระบบ</option><option value="EIS-DOCS">EIS-DOCS</option><option value="SUPPLIES">Supplies</option><option value="PROCURE">Procure</option><option value="NMU-EIS">NMU-EIS</option></select></label><label>วันที่เริ่ม<input id="start" type="date" required></label><label>วันที่สิ้นสุด<input id="end" type="date" required></label><label>ค้นหาผู้ใช้<input id="search" placeholder="ชื่อ หรือรหัสผู้ใช้"></label><label>หน่วยงาน<select id="department"><option value="">ทุกหน่วยงาน</option></select></label><label>สถานะ<select id="status"><option value="">ทั้งหมด</option><option value="1">ออนไลน์</option><option value="0">ออฟไลน์</option></select></label><button class="active" type="submit">แสดงข้อมูล</button></form><p class="hint">หนึ่งแถวต่อบัญชีในแต่ละระบบ · ผู้ใช้ที่เปิดทั้งสองระบบแสดงแยกสองแถว · หลายแท็บในระบบเดียวกันนับเวลาครั้งเดียว · ชื่อและสถานะกรองรายการ ไม่เปลี่ยนการ์ดสรุป</p><div id="filter-error" role="alert"></div></section>
<section class="panel"><div class="tabs"><button class="active" data-tab="users">ผู้ใช้งาน</button><button data-tab="history">ประวัติเข้า–ออก</button></div><div class="meta"><span id="range">—</span><span>ข้อมูลล่าสุด <span id="updated">—</span> · Asia/Bangkok</span></div><div class="scroll"><table><thead id="head"></thead><tbody id="rows"></tbody></table></div><div id="empty" hidden>ไม่พบข้อมูลตามตัวกรอง</div></section>
<footer>ออนไลน์ = มีหน้าของระบบนั้นเชื่อมต่ออยู่ · เวลารวมคือผลรวมเวลาแยกตามระบบ ไม่ใช่เวลาทำงานจริง · หน้านี้นับเป็นการใช้งาน EIS-DOCS · เริ่มเก็บประวัติตั้งแต่เปิดใช้การติดตาม</footer>
<section class="panel" id="presence-control" data-csrf="<?= htmlspecialchars($presenceCsrf, ENT_QUOTES, 'UTF-8') ?>">
<strong>WebSocket service</strong>
<p id="presence-service-status" role="status">กำลังตรวจสอบบริการ…</p>
<button type="button" id="presence-restart" disabled>รีสตาร์ท WebSocket</button>
<button type="button" id="presence-reconnect">เชื่อมต่อใหม่</button>
<button type="button" id="presence-refresh">โหลด log ล่าสุด</button>
<p>รีสตาร์ทจะตัดการเชื่อมต่อ Messenger ทุกระบบชั่วคราวและเชื่อมต่อกลับอัตโนมัติ · คำขอทำงานภายในประมาณ 1 นาที</p>
<details open><summary>Service log</summary><pre id="presence-service-log" style="max-height:280px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere"></pre></details>
<details><summary>Connection log (เบราว์เซอร์นี้)</summary><pre id="presence-browser-log" style="max-height:280px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere"></pre></details>
</section></main>
<?= Presence::script() ?><script src="user-online.js?v=20261013-nmu-eis"></script><script src="presence-control.js?v=20261015"></script></body></html>
