<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $helperPath = dirname(__DIR__, 2) . '/src/ProcurementTemplateStore.php';
    if (!is_file($helperPath)) {
        http_response_code(503);
        throw new InvalidArgumentException('ติดตั้งไฟล์ไม่ครบ: กรุณาอัปโหลด app/src/ProcurementTemplateStore.php พร้อม procurement-templates.php');
    }
    require_once $helperPath;
    require_once dirname(__DIR__, 2) . '/src/ProcurementLibrary.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $body = null;
    if ($method === 'POST') {
        if (!function_exists('mb_strlen') || !function_exists('mb_strtolower')) {
            http_response_code(503);
            throw new InvalidArgumentException('PHP ของ production ยังไม่มี mbstring กรุณาเปิด extension=mbstring ใน php.ini ของ PHP ที่ IIS ใช้งาน');
        }
        if (empty($_SESSION['app_csrf']) || !safeCmp((string) $_SESSION['app_csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            http_response_code(403);
            throw new InvalidArgumentException('กรุณาเข้าสู่ระบบใหม่');
        }
        $raw = file_get_contents('php://input', false, null, 0, 2000001);
        if ($raw === false || strlen($raw) > 2000000) throw new InvalidArgumentException('ข้อมูล Template มีขนาดใหญ่เกินไป');
        $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($body)) throw new InvalidArgumentException('รูปแบบข้อมูลไม่ถูกต้อง');
    } elseif ($method !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new InvalidArgumentException('ไม่รองรับคำสั่งนี้');
    }
    $store = new ProcurementTemplateStore(dirname(__DIR__, 3) . '/storage/procurement-templates');
    $library = new ProcurementLibrary($store);
    echo json_encode($library->handle((string) Auth::user()['user_id'], DocumentAccess::isAdministrator(), $body, (int)(Auth::user()['cost_id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if ($error instanceof DomainException) http_response_code(403);
    if (http_response_code() < 400) http_response_code($error instanceof InvalidArgumentException ? 400 : 500);
    $message = $error instanceof InvalidArgumentException || $error instanceof DomainException ? $error->getMessage() : 'บันทึกหรือโหลด Template ไม่สำเร็จ กรุณาตรวจ storage/logs/php-error.log';
    if ($error instanceof RuntimeException && preg_match('/สร้างพื้นที่|เปิดพื้นที่|ล็อกข้อมูล|บันทึก Template/u', $error->getMessage())) {
        $message = $error->getMessage() . ' กรุณาตรวจสิทธิ์เขียน storage/procurement-templates ของบัญชี PHP/IIS';
    } elseif ($error instanceof RuntimeException && strpos($error->getMessage(), 'Template') !== false) {
        $message = $error->getMessage() . ' กรุณาตรวจไฟล์ของผู้ใช้ใน storage/procurement-templates และ storage/logs/php-error.log';
    }
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    if (!($error instanceof InvalidArgumentException)) error_log('Procurement templates: ' . $error->getMessage());
}
