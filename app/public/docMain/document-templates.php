<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonBody(): array
{
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) throw new InvalidArgumentException('รูปแบบข้อมูลไม่ถูกต้อง');
    return $payload;
}

function requireCsrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!isset($_SESSION['app_csrf']) || !safeCmp((string) $_SESSION['app_csrf'], $token)) {
        throw new RuntimeException('CSRF token ไม่ถูกต้อง');
    }
}

try {
    $db = Database::connect('docs');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $sql = <<<'SQL'
SELECT docs_id, doc_no, doc_type, doc_type_name, pr_tor_id, department, requester_name,
       CONVERT(varchar(10), doc_date, 23) doc_date, amount, pdf_doc_id, file_path,
       template_name, description, status, config_json,
       CONVERT(varchar(19), created_at, 120) created_at,
       CONVERT(varchar(19), updated_at, 120) updated_at
FROM dbo.DOCS_HDR
WHERE is_enabled=1 AND (?=0 OR docs_id=?) AND (?=1 OR EXISTS (SELECT 1 FROM dbo.DOCS_DTL access_dtl WHERE access_dtl.docs_id=dbo.DOCS_HDR.docs_id AND access_dtl.wf_order=1 AND access_dtl.user_id=?))
ORDER BY updated_at DESC, docs_id DESC
SQL;
        $stmt = sqlsrv_query($db, $sql, [$id ?: 0, $id ?: 0, ...DocumentAccess::params()]);
        if ($stmt === false) throw new RuntimeException('โหลดข้อมูลเอกสารไม่สำเร็จ');
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $row['docs_id'] = (int) $row['docs_id'];
            $row['pr_tor_id'] = $row['pr_tor_id'] === null ? null : (int) $row['pr_tor_id'];
            $row['amount'] = (float) $row['amount'];
            $row['config'] = $row['config_json'] ? json_decode((string) $row['config_json'], true) : null;
            unset($row['config_json']);
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($db);
        echo json_encode(['success' => true, 'documents' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    requireCsrf();
    $payload = jsonBody();
    if (!empty($payload['docs_id'])) DocumentAccess::requireOwner($db, (int) $payload['docs_id']);

    if ($method === 'POST' && ($payload['action'] ?? '') === 'publish_pdf') {
        $id = filter_var($payload['docs_id'] ?? null, FILTER_VALIDATE_INT);
        $pdfDocId = trim((string) ($payload['pdf_doc_id'] ?? ''));
        $filePath = trim((string) ($payload['file_path'] ?? ''));
        if (!$id || ($pdfDocId === '' && $filePath === '')) throw new InvalidArgumentException('ข้อมูล PDF ที่สร้างไม่ครบถ้วน');
        if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่มรอบลงนามไม่สำเร็จ');
        try {
        DocumentAccess::requireUnsignedWf1($db, $id);
        $reset = sqlsrv_query($db, "UPDATE dbo.DOCS_DTL SET sign_status='PENDING', decision=NULL, rejection_reason=NULL, review_marks_json=NULL, signed_at=NULL WHERE docs_id=?", [$id]);
        if ($reset === false) throw new RuntimeException('เริ่มลำดับลงนามใหม่ไม่สำเร็จ');
        sqlsrv_free_stmt($reset);
        $stmt = sqlsrv_query($db, <<<'SQL'
UPDATE dbo.DOCS_HDR
SET pdf_doc_id=?, file_path=COALESCE(NULLIF(?, ''), file_path), status='PENDING', updated_at=SYSDATETIME()
WHERE docs_id=? AND EXISTS (SELECT 1 FROM dbo.DOCS_DTL d WHERE d.docs_id=dbo.DOCS_HDR.docs_id)
SQL, [$pdfDocId ?: $filePath, $filePath, $id]);
        if ($stmt === false || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('ไม่พบเอกสารหรือเส้นทาง Workflow กรุณาบันทึกข้อมูลก่อนสร้าง PDF');
        sqlsrv_free_stmt($stmt);
        if (!sqlsrv_commit($db)) throw new RuntimeException('บันทึกรอบลงนามไม่สำเร็จ');
        } catch (Throwable $error) {
            sqlsrv_rollback($db);
            throw $error;
        }
        sqlsrv_close($db);
        echo json_encode(['success' => true, 'docs_id' => $id, 'status' => 'PENDING', 'workflow_reset' => true, 'message' => 'สร้างรอบลงนามใหม่ เริ่มที่ WF1 ตามลำดับที่กำหนดแล้ว'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        $id = filter_var($payload['docs_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) throw new InvalidArgumentException('ไม่พบรายการที่ต้องการลบ');
        if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่มลบข้อมูลไม่สำเร็จ');
        try {
            $stmt = sqlsrv_query($db, 'DELETE FROM dbo.DOCS_DTL WHERE docs_id = ?', [$id]);
            if ($stmt === false) throw new RuntimeException('ลบเส้นทางผู้ลงนามไม่สำเร็จ');
            sqlsrv_free_stmt($stmt);
            $stmt = sqlsrv_query($db, 'DELETE FROM dbo.DOCS_HDR WHERE docs_id = ?', [$id]);
            if ($stmt === false || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('ไม่พบข้อมูลที่ต้องการลบ');
            sqlsrv_free_stmt($stmt);
            sqlsrv_commit($db);
        } catch (Throwable $e) {
            sqlsrv_rollback($db);
            throw $e;
        }
        sqlsrv_close($db);
        echo json_encode(['success' => true, 'message' => 'ลบข้อมูลแล้ว'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') throw new InvalidArgumentException('Method ไม่ถูกต้อง');

    $id = filter_var($payload['docs_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $isUpdate = $id !== null;
    if (!$isUpdate) DocumentCreationAccess::requireAllowed($db);
    $docNo = trim((string) ($payload['pr_code'] ?? ''));
    $docTypeName = trim((string) ($payload['doc_type'] ?? ''));
    $templateName = trim((string) ($payload['template_name'] ?? ''));
    if ($docNo === '' || $docTypeName === '' || $templateName === '') {
        throw new InvalidArgumentException('กรุณาระบุชนิดเอกสาร เอกสาร PR และชื่อเอกสารลงนาม');
    }
    $docType = strtoupper(trim(strtok($docTypeName, ' -') ?: 'PR'));
    $statusInput = strtoupper(trim((string) ($payload['status'] ?? 'DRAFT')));
    $status = in_array($statusInput, ['DRAFT', 'ACTIVE', 'INACTIVE', 'PENDING', 'COMPLETED'], true) ? $statusInput : 'DRAFT';
    $user = Auth::user();
    if (!empty($payload['pr_id'])) {
        $prId = filter_var($payload['pr_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $pr = sqlsrv_query($db, 'SELECT tor_id FROM NMU_ERP.dbo.sp_tor WHERE tor_id=? AND LTRIM(RTRIM(c_code))=?', [$prId, $docNo]);
        if (!$pr || !sqlsrv_fetch_array($pr, SQLSRV_FETCH_ASSOC)) throw new InvalidArgumentException('ไม่พบ PR ที่เลือกหรือเลข PR ไม่ตรงกัน');
        sqlsrv_free_stmt($pr);
    }
    $values = [
        $docNo, $docType, $docTypeName, filter_var($payload['pr_id'] ?? null, FILTER_VALIDATE_INT) ?: null,
        trim((string) ($payload['department'] ?? '')) ?: '-',
        trim((string) ($user['full_name'] ?? '')) ?: 'ผู้ใช้งาน',
        trim((string) ($payload['doc_date'] ?? '')) ?: date('Y-m-d'),
        (float) ($payload['amount'] ?? 0), trim((string) ($payload['pdf_doc_id'] ?? '')) ?: null,
        trim((string) ($payload['file_path'] ?? '')) ?: null, $templateName,
        trim((string) ($payload['description'] ?? '')) ?: null, $status,
        json_encode($payload['config'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $route = is_array($payload['route'] ?? null) ? array_values($payload['route']) : [];
    if (!$route) throw new InvalidArgumentException('กรุณาเลือกกลุ่มผู้ลงนามก่อนบันทึก');
    if (!sqlsrv_begin_transaction($db)) throw new RuntimeException('เริ่มบันทึกข้อมูลไม่สำเร็จ');
    try {
    $fileName = basename(str_replace('\\', '/', trim((string)($payload['file_path'] ?? ''))));
    if ($fileName !== '') {
        $duplicate = sqlsrv_query($db, "SELECT TOP 1 docs_id FROM dbo.DOCS_HDR WITH (UPDLOCK,HOLDLOCK) WHERE docs_id<>? AND ((doc_no=? AND template_name=?) OR file_path=? OR RIGHT(REPLACE(file_path,CHAR(92),'/'),LEN(?)+1)='/'+?)", [$id ?? 0,$docNo,$templateName,$fileName,$fileName,$fileName]);
        if (!$duplicate) throw new RuntimeException('ตรวจชื่อเอกสารซ้ำไม่สำเร็จ');
        $existingFile = sqlsrv_fetch_array($duplicate, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($duplicate);
        if ($existingFile) {
            http_response_code(409);
            throw new RuntimeException('มีเอกสารหรือชื่อไฟล์ PDF นี้อยู่แล้ว หากต้องการแก้ไข ให้เลือกเอกสารเดิมในช่องข้อมูลที่บันทึกใน EIS_DOCS ก่อน หากต้องการสร้างใหม่ ให้เปลี่ยนชื่อเอกสารลงนาม');
        }
    }
    if ($id) {
        DocumentAccess::requireUnsignedWf1($db, $id);
        if (!empty($payload['preserve_workflow'])) {
            $existing = sqlsrv_query($db, 'SELECT user_id,dc_emp_id,signer_name,review_action,sign_action,approve_action FROM dbo.DOCS_DTL WHERE docs_id=? ORDER BY wf_order,docs_dtl_id', [$id]);
            if ($existing === false) throw new RuntimeException('โหลด WF เดิมไม่สำเร็จ');
            $route = [];
            while ($member = sqlsrv_fetch_array($existing, SQLSRV_FETCH_ASSOC)) $route[] = $member;
            sqlsrv_free_stmt($existing);
            if (!$route) throw new RuntimeException('ไม่พบ WF เดิม');
        }
        $sql = <<<'SQL'
UPDATE dbo.DOCS_HDR SET doc_no=?, doc_type=?, doc_type_name=?, pr_tor_id=?, department=?, requester_name=?,
 doc_date=?, amount=?, pdf_doc_id=?, file_path=?, template_name=?, description=?, status=?, config_json=?, updated_at=SYSDATETIME()
WHERE docs_id=?
SQL;
        $stmt = sqlsrv_query($db, $sql, [...$values, $id]);
        if ($stmt === false || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('แก้ไขข้อมูลไม่สำเร็จ');
    } else {
        $sql = <<<'SQL'
INSERT dbo.DOCS_HDR(doc_no,doc_type,doc_type_name,pr_tor_id,department,requester_name,doc_date,amount,pdf_doc_id,file_path,template_name,description,status,config_json,created_by)
OUTPUT INSERTED.docs_id VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL;
        $stmt = sqlsrv_query($db, $sql, [...$values, DocumentAccess::userId()]);
        if ($stmt === false) throw new RuntimeException('บันทึกข้อมูลไม่สำเร็จ (PR และชื่อเอกสารลงนามนี้อาจมีอยู่แล้ว)');
        $id = (int) (sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)['docs_id'] ?? 0);
    }
    sqlsrv_free_stmt($stmt);

    // การบันทึกแก้ไขเอกสารเดิมถือเป็นเอกสารฉบับใหม่ของ Workflow:
    // ล้างผลเดิมทั้งหมดและสร้างเส้นทาง PENDING ตั้งแต่ WF1
    $stmt = sqlsrv_query($db, 'DELETE FROM dbo.DOCS_DTL WHERE docs_id=?', [$id]);
    if ($stmt === false) throw new RuntimeException('ปรับปรุงเส้นทาง Workflow ไม่สำเร็จ');
    sqlsrv_free_stmt($stmt);

    $pending = 0;
    foreach ($route as $index => $member) {
        $userId = trim((string) ($member['user_id'] ?? ''));
        $signerName = trim((string) ($member['signer_name'] ?? ''));
        $empId = filter_var($member['dc_emp_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($userId === '' || $signerName === '') throw new InvalidArgumentException('ข้อมูลผู้ลงนามไม่ครบถ้วน');
        $signStatus = 'PENDING';
        $pending++;
        $params = [$id, $index + 1, $userId, $empId, $signerName,
            !empty($member['review_action']) ? 1 : 0, !empty($member['sign_action']) ? 1 : 0,
            !empty($member['approve_action']) ? 1 : 0, $signStatus,
            null, null, null];
        $stmt = sqlsrv_query($db, <<<'SQL'
INSERT dbo.DOCS_DTL(docs_id,wf_order,user_id,dc_emp_id,signer_name,review_action,sign_action,approve_action,sign_status,decision,rejection_reason,signed_at)
VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
SQL, $params);
        if ($stmt === false) throw new RuntimeException('บันทึกลำดับ Workflow ไม่สำเร็จ');
        sqlsrv_free_stmt($stmt);
    }
    // ยังไม่ส่งให้ WF1 จนกว่าจะสร้าง PDF ฉบับแก้ไขสำเร็จ
    $finalStatus = $isUpdate ? 'DRAFT' : ($pending === 0 ? 'COMPLETED' : ($status === 'COMPLETED' ? 'PENDING' : $status));
    $stmt = sqlsrv_query($db, 'UPDATE dbo.DOCS_HDR SET status=?, updated_at=SYSDATETIME() WHERE docs_id=?', [$finalStatus, $id]);
    if ($stmt === false) throw new RuntimeException('บันทึกความคืบหน้าไม่สำเร็จ');
    sqlsrv_free_stmt($stmt);
    if (!sqlsrv_commit($db)) throw new RuntimeException('ยืนยันการบันทึกไม่สำเร็จ');
    } catch (Throwable $transactionError) {
        sqlsrv_rollback($db);
        throw $transactionError;
    }
    sqlsrv_close($db);
    echo json_encode([
        'success' => true,
        'docs_id' => $id,
        'workflow_reset' => $isUpdate,
        'message' => $isUpdate
            ? 'บันทึกฉบับแก้ไขและเริ่ม Workflow ใหม่แล้ว กรุณาสร้าง PDF เพื่อส่งให้ WF1'
            : 'บันทึกข้อมูลใน EIS_DOCS แล้ว',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Document template API failed: ' . $error->getMessage() . ' ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE));
    if (!in_array(http_response_code(), [403,409], true)) http_response_code($error instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
