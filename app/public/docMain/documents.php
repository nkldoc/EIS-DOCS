<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/notification-service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $connection = Database::connect('docs');
    $loginUserId = (string) (Auth::user()['user_id'] ?? '');
    $isAdmin = DocumentAccess::isAdministrator();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!isset($_SESSION['app_csrf']) || !safeCmp((string) $_SESSION['app_csrf'], $csrf)) {
            throw new RuntimeException('CSRF token ไม่ถูกต้อง');
        }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload) || ($payload['action'] ?? '') !== 'mark_signed') {
            throw new InvalidArgumentException('คำขออัปเดตสถานะไม่ถูกต้อง');
        }

        $docsId = filter_var($payload['docs_id'] ?? null, FILTER_VALIDATE_INT);
        $userId = trim((string) ($payload['user_id'] ?? ''));
        $loginUserId = (string) (Auth::user()['user_id'] ?? '');
        $decision = strtoupper(trim((string) ($payload['decision'] ?? 'SIGNED')));
        $rejectionReason = trim((string) ($payload['rejection_reason'] ?? ''));
        $reviewMarks = $decision === 'REJECT' ? ($payload['review_marks'] ?? []) : [];
        if (!$docsId || $userId === '' || !in_array($decision, ['SIGNED', 'PASS', 'REJECT'], true)) {
            throw new InvalidArgumentException('ข้อมูลเอกสาร ผู้ดำเนินการ หรือผลดำเนินการไม่ถูกต้อง');
        }
        if ($decision === 'REJECT' && $rejectionReason === '') {
            throw new InvalidArgumentException('กรุณาระบุเหตุผลที่ไม่ผ่าน');
        }
        if (!is_array($reviewMarks) || count($reviewMarks) > 100) throw new InvalidArgumentException('รายการหน้าที่ต้องแก้ไขไม่ถูกต้อง');
        $cleanMarks = [];
        foreach ($reviewMarks as $mark) {
            if (!is_array($mark)) continue;
            $page = filter_var($mark['page'] ?? null, FILTER_VALIDATE_INT);
            $description = trim((string) ($mark['description'] ?? ''));
            if (!$page || $page < 1 || $description === '') continue;
            if (mb_strlen($description, 'UTF-8') > 500) throw new InvalidArgumentException('คำอธิบายต้องไม่เกิน 500 ตัวอักษร');
            $image = $mark['image'] ?? null;
            if ($image !== null && $image !== '') {
                if (!is_string($image) || strlen($image) > 2000000 || !preg_match('/^data:image\/jpeg;base64,([A-Za-z0-9+\/=]+)$/D', $image, $imageMatch)) {
                    throw new InvalidArgumentException('รูปจุดแก้ไขต้องเป็น JPEG ขนาดไม่เกิน 2 MB');
                }
                $imageBytes = base64_decode($imageMatch[1], true);
                $imageInfo = $imageBytes === false ? false : @getimagesizefromstring($imageBytes);
                if (!$imageInfo || $imageInfo[2] !== IMAGETYPE_JPEG) throw new InvalidArgumentException('รูปจุดแก้ไขไม่ถูกต้อง');
            }
            $cleanMarks[] = ['page' => $page, 'bookmark' => trim((string) ($mark['bookmark'] ?? '')), 'description' => $description, 'image' => $image];
        }
        $reviewMarksJson = $cleanMarks ? json_encode($cleanMarks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        if (function_exists('mb_strlen') ? mb_strlen($rejectionReason, 'UTF-8') > 1000 : strlen($rejectionReason) > 3000) {
            throw new InvalidArgumentException('เหตุผลที่ไม่ผ่านต้องไม่เกิน 1,000 ตัวอักษร');
        }
        if ($loginUserId === '' || !safeCmp($loginUserId, $userId)) {
            http_response_code(403);
            throw new RuntimeException('บัญชีที่เข้าสู่ระบบไม่ใช่ผู้ดำเนินการ WF ปัจจุบัน');
        }

        $pinGrant = $_SESSION['pin_sign_grants'][$docsId] ?? null;
        if (!$pinGrant || $pinGrant['user_id'] !== $loginUserId || $pinGrant['expires'] < time()) {
            http_response_code(403);
            throw new RuntimeException('กรุณายืนยัน PIN ก่อนลงนามหรือบันทึกผลตรวจสอบ');
        }
        unset($_SESSION['pin_sign_grants'][$docsId]);

        if (!sqlsrv_begin_transaction($connection)) {
            throw new RuntimeException('ไม่สามารถเริ่มบันทึกสถานะได้');
        }
        $notificationsToPublish = [];
        try {
            $pinState = sqlsrv_query($connection, 'SELECT pin_version FROM dbo.DOCS_USER_PIN WITH (HOLDLOCK) WHERE user_id=? AND reset_allowed=0 AND pin_version=?', [$loginUserId,(int)($pinGrant['pin_version']??0)]);
            if (!$pinState) throw new RuntimeException('ตรวจสอบสถานะ PIN ไม่สำเร็จ');
            $validPin = sqlsrv_fetch_array($pinState,SQLSRV_FETCH_ASSOC); sqlsrv_free_stmt($pinState);
            if (!$validPin) {http_response_code(403); throw new RuntimeException('PIN ถูกปลดล็อกหรือเปลี่ยนแล้ว กรุณายืนยันรหัสใหม่');}
            $active = sqlsrv_query($connection, 'SELECT docs_id FROM dbo.DOCS_HDR WITH (UPDLOCK,HOLDLOCK) WHERE docs_id=? AND is_enabled=1', [$docsId]);
            if (!$active) throw new RuntimeException('ตรวจสอบการใช้งานเอกสารไม่สำเร็จ');
            $enabled = sqlsrv_fetch_array($active, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($active);
            if (!$enabled) { http_response_code(403); throw new RuntimeException('เอกสารนี้ถูกยกเลิกการใช้งานหรือถูกลบแล้ว'); }
            $updateSql = <<<'SQL'
UPDATE dbo.DOCS_DTL
SET sign_status = 'DONE', decision = ?, rejection_reason = ?, review_marks_json = ?, signed_at = SYSDATETIME()
WHERE docs_id = ? AND user_id = ? AND sign_status = 'PENDING'
  AND wf_order = (
      SELECT MIN(p.wf_order) FROM dbo.DOCS_DTL p
      WHERE p.docs_id = ? AND p.sign_status = 'PENDING'
  )
SQL;
            $statement = sqlsrv_query($connection, $updateSql, [$decision, $decision === 'REJECT' ? $rejectionReason : null, $reviewMarksJson, $docsId, $userId, $docsId]);
            if ($statement === false) throw new RuntimeException('อัปเดตลำดับผู้ลงนามไม่สำเร็จ');
            $changed = sqlsrv_rows_affected($statement);
            sqlsrv_free_stmt($statement);
            if ($changed !== 1) {
                throw new RuntimeException('ไม่พบสิทธิ์ดำเนินการในลำดับปัจจุบัน หรือรายการนี้ดำเนินการแล้ว');
            }

            $headerStatus = $decision === 'REJECT' ? 'REJECTED' : 'PENDING';
            if ($decision === 'REJECT') {
                // เปิด WF1 ให้แก้ไขเอกสาร แต่รักษาผู้ที่ผ่านแล้วและผู้ทักท้วงไว้
                // เมื่อ WF1 ส่งคืน ลำดับ PENDING ถัดไปจึงเป็นผู้ทักท้วงโดยตรง
                $resetSql = <<<'SQL'
UPDATE dbo.DOCS_DTL
SET sign_status = CASE WHEN wf_order = 1 OR decision = 'REJECT' THEN 'PENDING' ELSE sign_status END,
    decision = CASE WHEN wf_order = 1 AND decision <> 'REJECT' THEN NULL ELSE decision END,
    rejection_reason = CASE WHEN wf_order = 1 AND decision <> 'REJECT' THEN NULL ELSE rejection_reason END,
    review_marks_json = CASE WHEN wf_order = 1 AND decision <> 'REJECT' THEN NULL ELSE review_marks_json END,
    signed_at = CASE WHEN wf_order = 1 AND decision <> 'REJECT' THEN NULL ELSE signed_at END
WHERE docs_id = ?
SQL;
                $statement = sqlsrv_query($connection, $resetSql, [$docsId]);
                if ($statement === false) throw new RuntimeException('ส่งรายการกลับไป WF1 ไม่สำเร็จ');
                sqlsrv_free_stmt($statement);
            } else {
                $pendingSql = 'SELECT COUNT(*) AS pending_count FROM dbo.DOCS_DTL WHERE docs_id = ? AND sign_status = \'PENDING\'';
                $statement = sqlsrv_query($connection, $pendingSql, [$docsId]);
                if ($statement === false) throw new RuntimeException('ตรวจสอบสถานะเอกสารไม่สำเร็จ');
                $pending = (int) (sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)['pending_count'] ?? 0);
                sqlsrv_free_stmt($statement);
                $headerStatus = $pending === 0 ? 'COMPLETED' : 'PENDING';
            }
            $statement = sqlsrv_query(
                $connection,
                'UPDATE dbo.DOCS_HDR SET status = ?, updated_at = SYSDATETIME() WHERE docs_id = ?',
                [$headerStatus, $docsId]
            );
            if ($statement === false) throw new RuntimeException('อัปเดตสถานะเอกสารไม่สำเร็จ');
            sqlsrv_free_stmt($statement);
            $docStatement = sqlsrv_query($connection, 'SELECT doc_no FROM dbo.DOCS_HDR WHERE docs_id = ?', [$docsId]);
            $docNo = (string) (sqlsrv_fetch_array($docStatement, SQLSRV_FETCH_ASSOC)['doc_no'] ?? $docsId);
            sqlsrv_free_stmt($docStatement);
            $nextSql = 'SELECT TOP (1) user_id, wf_order FROM dbo.DOCS_DTL WHERE docs_id = ? AND sign_status = \'PENDING\' ORDER BY wf_order, docs_dtl_id';
            $nextStatement = sqlsrv_query($connection, $nextSql, [$docsId]);
            $next = $nextStatement === false ? null : (sqlsrv_fetch_array($nextStatement, SQLSRV_FETCH_ASSOC) ?: null);
            if ($nextStatement !== false) sqlsrv_free_stmt($nextStatement);
            if ($next) {
                $returned = $decision === 'REJECT';
                try {
                    $notification = createWorkflowNotification($connection, (string) $next['user_id'], $docsId, (int) $next['wf_order'], $returned ? 'RETURNED_TO_WF1' : 'WORKFLOW_ASSIGNED', $docNo, $returned ? "เอกสาร {$docNo} ไม่ผ่านการตรวจสอบ กรุณาแก้ไขและส่งกลับเข้ากระบวนการ" : "เอกสาร {$docNo} มาถึง WF {$next['wf_order']} กรุณาดำเนินการ");
                    if ($notification) $notificationsToPublish[] = $notification;
                } catch (Throwable $notificationError) {
                    // การแจ้งเตือนต้องไม่ทำให้ผลตรวจสอบหรือการลงนามของเอกสารล้มเหลว
                    error_log('Workflow notification skipped: ' . $notificationError->getMessage());
                }
            }
            if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันการบันทึกสถานะไม่สำเร็จ');
        } catch (Throwable $transactionError) {
            sqlsrv_rollback($connection);
            throw $transactionError;
        }

        foreach ($notificationsToPublish as $notification) publishWorkflowNotification($notification);

        sqlsrv_close($connection);
        echo json_encode([
            'success' => true,
            'docs_id' => $docsId,
            'status' => $headerStatus,
            'returned_to_wf' => $decision === 'REJECT' ? 1 : null,
            'message' => $decision === 'REJECT'
                ? 'ตรวจสอบไม่ผ่านและส่งรายการกลับไป WF1 แล้ว'
                : 'อัปเดตสถานะลำดับงานแล้ว',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $docsId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($docsId) {
        $headerSql = <<<'SQL'
SELECT h.docs_id, h.doc_no, h.doc_type, h.doc_type_name, h.department, h.requester_name,
       CONVERT(varchar(10), h.doc_date, 23) AS doc_date, COALESCE(t.f_total_amt, h.amount) amount,
       h.pdf_doc_id, h.file_path, h.template_name, h.description, h.status,
       CONVERT(varchar(19), h.created_at, 120) AS created_at
FROM dbo.DOCS_HDR h
OUTER APPLY (SELECT TOP (1) s.f_total_amt FROM NMU_ERP.dbo.sp_tor s WHERE s.tor_id=h.pr_tor_id OR LTRIM(RTRIM(s.c_code))=LTRIM(RTRIM(h.doc_no)) ORDER BY CASE WHEN s.tor_id=h.pr_tor_id THEN 0 ELSE 1 END) t
WHERE h.docs_id = ? AND h.is_enabled=1
  AND (? = 1 OR EXISTS (SELECT 1 FROM dbo.DOCS_DTL access_dtl WHERE access_dtl.docs_id = h.docs_id AND access_dtl.user_id = ?))
SQL;
        $statement = sqlsrv_query($connection, $headerSql, [$docsId, $isAdmin ? 1 : 0, $loginUserId]);
        if ($statement === false) throw new RuntimeException('โหลดรายละเอียดเอกสารไม่สำเร็จ');
        $document = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC) ?: null;
        sqlsrv_free_stmt($statement);
        if (!$document) {
            sqlsrv_close($connection);
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'ไม่พบเอกสาร'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $detailSql = <<<'SQL'
SELECT docs_dtl_id, wf_order, user_id, dc_emp_id, signer_name,
       review_action, sign_action, approve_action, sign_status, decision, rejection_reason, review_marks_json,
       CONVERT(varchar(19), signed_at, 120) AS signed_at
FROM dbo.DOCS_DTL WHERE docs_id = ? ORDER BY wf_order, docs_dtl_id
SQL;
        $statement = sqlsrv_query($connection, $detailSql, [$docsId]);
        if ($statement === false) throw new RuntimeException('โหลดเส้นทางลงนามไม่สำเร็จ');
        $route = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $row['docs_dtl_id'] = (int) $row['docs_dtl_id'];
            $row['wf_order'] = (int) $row['wf_order'];
            $row['review_action'] = (bool) $row['review_action'];
            $row['sign_action'] = (bool) $row['sign_action'];
            $row['approve_action'] = (bool) $row['approve_action'];
            $row['review_marks'] = [];
            if (!empty($row['review_marks_json'])) {
                $decodedMarks = json_decode((string) $row['review_marks_json'], true);
                if (is_array($decodedMarks)) $row['review_marks'] = $decodedMarks;
            }
            unset($row['review_marks_json']);
            $route[] = $row;
        }
        sqlsrv_free_stmt($statement);
        sqlsrv_close($connection);
        $document['docs_id'] = (int) $document['docs_id'];
        $document['amount'] = (float) $document['amount'];
        $document['route'] = $route;
        echo json_encode(['success' => true, 'document' => $document], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $sql = <<<'SQL'
SELECT h.docs_id, h.doc_no, h.doc_type, h.doc_type_name, h.department,
       h.requester_name, CONVERT(varchar(10), h.doc_date, 23) AS doc_date,
       COALESCE(t.f_total_amt, h.amount) amount, h.pdf_doc_id, h.file_path, h.template_name, h.description, h.status,
       COUNT(d.docs_dtl_id) AS signer_count,
       SUM(CASE WHEN d.sign_status = 'DONE' THEN 1 ELSE 0 END) AS signed_count,
       (SELECT TOP (1) f1.signer_name FROM dbo.DOCS_DTL f1 WHERE f1.docs_id=h.docs_id AND f1.wf_order=1 ORDER BY f1.docs_dtl_id) AS wf1_signer_name,
       current_signer.user_id AS current_user_id,
       current_signer.signer_name AS current_signer,
       current_signer.wf_order AS current_wf_order,
       current_signer.review_action AS current_review_action,
       current_signer.sign_action AS current_sign_action
FROM dbo.DOCS_HDR h
OUTER APPLY (SELECT TOP (1) s.f_total_amt FROM NMU_ERP.dbo.sp_tor s WHERE s.tor_id=h.pr_tor_id OR LTRIM(RTRIM(s.c_code))=LTRIM(RTRIM(h.doc_no)) ORDER BY CASE WHEN s.tor_id=h.pr_tor_id THEN 0 ELSE 1 END) t
LEFT JOIN dbo.DOCS_DTL d ON d.docs_id = h.docs_id
OUTER APPLY (
    SELECT TOP (1) x.user_id, x.signer_name, x.wf_order, x.review_action, x.sign_action
    FROM dbo.DOCS_DTL x
    WHERE x.docs_id = h.docs_id AND x.sign_status = 'PENDING'
    ORDER BY x.wf_order, x.docs_dtl_id
) current_signer
WHERE h.is_enabled=1 AND (? = 1 OR EXISTS (SELECT 1 FROM dbo.DOCS_DTL access_dtl WHERE access_dtl.docs_id = h.docs_id AND access_dtl.user_id = ?))
GROUP BY h.docs_id, h.doc_no, h.doc_type, h.doc_type_name, h.department,
         h.requester_name, h.doc_date, t.f_total_amt, h.amount, h.pdf_doc_id, h.file_path, h.template_name, h.description,
         h.status, current_signer.user_id, current_signer.signer_name, current_signer.wf_order, current_signer.review_action, current_signer.sign_action
ORDER BY h.doc_date DESC, h.docs_id DESC
SQL;
    $statement = sqlsrv_query($connection, $sql, [$isAdmin ? 1 : 0, $loginUserId]);
    if ($statement === false) throw new RuntimeException('โหลดรายการเอกสารไม่สำเร็จ');
    $documents = [];
    while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
        $row['docs_id'] = (int) $row['docs_id'];
        $row['amount'] = (float) $row['amount'];
        $row['signer_count'] = (int) $row['signer_count'];
        $row['signed_count'] = (int) $row['signed_count'];
        $row['current_wf_order'] = $row['current_wf_order'] === null ? null : (int) $row['current_wf_order'];
        $row['current_review_action'] = (bool) ($row['current_review_action'] ?? false);
        $row['current_sign_action'] = (bool) ($row['current_sign_action'] ?? false);
        $documents[] = $row;
    }
    sqlsrv_free_stmt($statement);
    sqlsrv_close($connection);
    echo json_encode(['success' => true, 'documents' => $documents], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    error_log('Documents API failed: ' . $error->getMessage() . ' ' . json_encode(sqlsrv_errors(), JSON_UNESCAPED_UNICODE));
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
