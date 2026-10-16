<?php
require_once __DIR__ . '/conf/config.php';
require_once __DIR__ . '/lib/database/DatabaseServer.php';
header('Cache-Control: no-store');
$system = (string)($_GET['system'] ?? '');
$targets = ['supplies'=>['database'=>DB_NMU_ERP,'permission'=>'i_permit_supplies'], 'procure'=>['database'=>DB_PROCURE,'permission'=>'i_permit_procure']];
if (!isset($targets[$system])) { http_response_code(400); exit('ไม่พบระบบที่เลือก'); }
if (empty($_SESSION['dc_user_id']) || !isset($_SESSION['i_type_user'])) { header('Location: /NMU_permission/'); exit; }
if ((int)($_SESSION[$targets[$system]['permission']] ?? 0) <= 0) { http_response_code(403); exit('บัญชีนี้ไม่มีสิทธิ์เข้าใช้งานระบบที่เลือก'); }
$db = new DatabaseServer();
$stmt = $db->QueryParam('SELECT TOP 1 target.dc_user_id FROM ' . $targets[$system]['database'] . 'dc_user target INNER JOIN ' . DB_CENTER . 'dc_user center_user ON center_user.c_user_name=target.c_user_name WHERE center_user.dc_user_id=? AND center_user.i_enable=1 AND center_user.i_delete=2 AND target.i_enable=1 AND target.i_delete=2 ORDER BY target.dc_user_id', [(int)$_SESSION['dc_user_id']]);
$row = $stmt ? $db->Fetch($stmt) : null;
if (!$row) { http_response_code(403); exit('ไม่พบบัญชีที่เปิดใช้งานในระบบที่เลือก กรุณาติดต่อผู้ดูแลระบบ'); }
session_write_close();
header('Location: https://' . MAIN_HOST_NAME . '/' . $system . '/access/login_1.php?' . http_build_query(['ss_user_id'=>(int)$row['dc_user_id'],'portal_entry'=>1]), true, 303);
exit;
