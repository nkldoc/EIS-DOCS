<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Cache-Control: no-store, private');
$db = null;
$transaction = false;
try {
    if (!Auth::check()) { http_response_code(401); throw new RuntimeException('กรุณาเข้าสู่ระบบ'); }
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) { http_response_code(400); throw new RuntimeException('เลขเอกสารไม่ถูกต้อง'); }
    $db = Database::connect('docs');
    DocumentAccess::requireOwner($db, $id);
    $directory = dirname(__DIR__, 3) . '/storage/document-sources';
    $path = $directory . '/' . $id . '.pdf';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!is_file($path)) { http_response_code(404); throw new RuntimeException('ยังไม่มี PDF ต้นฉบับ'); }
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Method not allowed'); }
    if (!isset($_SESSION['app_csrf']) || !safeCmp((string)$_SESSION['app_csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403); throw new RuntimeException('CSRF token ไม่ถูกต้อง');
    }
    $file = $_FILES['pdf'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        http_response_code(400); throw new RuntimeException('อัปโหลด PDF ต้นฉบับไม่สำเร็จ');
    }
    if (file_get_contents($file['tmp_name'], false, null, 0, 5) !== '%PDF-') {
        http_response_code(400); throw new RuntimeException('ไฟล์ไม่ใช่ PDF');
    }
    if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่มบันทึกไม่สำเร็จ');
    $transaction = true;
    DocumentAccess::requireUnsignedWf1($db, $id);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new RuntimeException('สร้างที่เก็บ PDF ไม่สำเร็จ');
    if (!move_uploaded_file($file['tmp_name'], $path)) throw new RuntimeException('บันทึก PDF ต้นฉบับไม่สำเร็จ');
    sqlsrv_commit($db);
    $transaction = false;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    if ($transaction) sqlsrv_rollback($db);
    if (http_response_code() < 400) http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} finally {
    if ($db) sqlsrv_close($db);
}
