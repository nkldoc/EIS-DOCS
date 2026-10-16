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

try {
    $connection = Database::connect('docs');
    $memberTransaction = false;
    // Keep persisted positions contiguous; moving one signer shifts the others.
    $reorderMembers = static function (string $name, ?string $moveUser = null, ?int $position = null) use ($connection): void {
        $stmt = sqlsrv_query($connection, 'SELECT user_id,dc_emp_id FROM dbo.WF_USER_DTL WITH (UPDLOCK,HOLDLOCK) WHERE c_name=? ORDER BY wf_order,dc_emp_id,user_id', [$name]);
        if (!$stmt) throw new RuntimeException('อ่านลำดับผู้ลงนามไม่สำเร็จ');
        $members = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $members[] = $row;
        sqlsrv_free_stmt($stmt);
        if ($moveUser !== null) {
            foreach ($members as $index => $member) {
                if ((string) $member['user_id'] !== $moveUser) continue;
                array_splice($members, $index, 1);
                array_splice($members, max(0, min(count($members), ($position ?? 1) - 1)), 0, [$member]);
                break;
            }
        }
        foreach ($members as $index => $member) {
            $stmt = sqlsrv_query($connection, 'UPDATE dbo.WF_USER_DTL SET wf_order=? WHERE c_name=? AND user_id=? AND dc_emp_id=?', [$index + 1, $name, $member['user_id'], $member['dc_emp_id']]);
            if (!$stmt || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('จัดลำดับผู้ลงนามไม่สำเร็จ');
            sqlsrv_free_stmt($stmt);
        }
    };
    $loginUser = Auth::user() ?? [];
    $loginUserId = (string) ($loginUser['user_id'] ?? '');
    $loginCostId = max(0, (int) ($loginUser['cost_id'] ?? 0));
    // dc_user: 2 = Administrator; 3 = department user, not Administrator.
    $isGroupAdministrator = (int) ($loginUser['user_type'] ?? 0) === 2;
    $visibilitySql = "(?=1 OR h.owner_user_id=? OR h.visibility='all')";
    $visibilityParams = [$isGroupAdministrator ? 1 : 0, $loginUserId];
    $requireVisibleGroup = static function (string $name, bool $editing = false) use ($connection, $visibilitySql, $visibilityParams, $isGroupAdministrator, $loginUserId): array {
        $stmt = sqlsrv_query($connection, 'SELECT h.visibility,h.owner_user_id,h.owner_cost_id FROM dbo.WF_USER_HDR h WHERE h.c_name=? AND ' . $visibilitySql, [$name, ...$visibilityParams]);
        if (!$stmt) throw new RuntimeException('ตรวจสอบสิทธิ์กลุ่มไม่สำเร็จ');
        $group = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        if (!$group) { http_response_code(403); throw new RuntimeException('ไม่พบกลุ่มหรือคุณไม่มีสิทธิ์เข้าถึงกลุ่มนี้'); }
        $group['can_edit'] = $isGroupAdministrator || (string) $group['owner_user_id'] === $loginUserId;
        if ($editing && !$group['can_edit']) { http_response_code(403); throw new RuntimeException('คุณดูหรือคัดลอกกลุ่มนี้ได้ แต่ไม่มีสิทธิ์แก้ไขหรือลบกลุ่มต้นฉบับ'); }
        return $group;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!isset($_SESSION['app_csrf']) || !safeCmp((string) $_SESSION['app_csrf'], $csrf)) {
            throw new RuntimeException('CSRF token ไม่ถูกต้อง');
        }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('ข้อมูล JSON ไม่ถูกต้อง');
        }
        $action = (string) ($payload['action'] ?? '');
        $groupName = trim((string) ($payload['group_name'] ?? ''));
        if ($groupName === '' || mb_strlen($groupName, 'UTF-8') > 255) {
            throw new RuntimeException('กรุณาระบุชื่อกลุ่มไม่เกิน 255 ตัวอักษร');
        }
        $newVisibility = (string) ($payload['visibility'] ?? 'private');
        if (in_array($action, ['copy_group', 'create_group'], true)) {
            if (!in_array($newVisibility, ['private', 'department', 'all'], true)) throw new RuntimeException('ระดับการมองเห็นไม่ถูกต้อง');
            if ($newVisibility === 'department' && $loginCostId === 0) throw new RuntimeException('ข้อมูล Login ไม่มีหน่วยงาน กรุณาเลือกเฉพาะตัวหรือทั้งหมด');
        }
        if (!in_array($action, ['create_group', 'copy_group', 'rename_group'], true)) $requireVisibleGroup($groupName, true);
        if (in_array($action, ['add_member', 'remove_member', 'update_order'], true)) {
            if (!sqlsrv_begin_transaction($connection)) throw new RuntimeException('เริ่มบันทึกลำดับไม่สำเร็จ');
            $memberTransaction = true;
            $reorderMembers($groupName);
        }

        if ($action === 'delete_group') {
            if (!sqlsrv_begin_transaction($connection)) throw new RuntimeException('เริ่มลบกลุ่มไม่สำเร็จ');
            try {
                $stmt = sqlsrv_query($connection, 'SELECT c_name FROM dbo.WF_USER_HDR WITH (UPDLOCK,HOLDLOCK) WHERE c_name=?', [$groupName]);
                if (!$stmt || !sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) throw new RuntimeException('ไม่พบกลุ่มที่ต้องการลบ');
                if (!sqlsrv_query($connection, 'DELETE FROM dbo.WF_USER_DTL WHERE c_name=?', [$groupName])) throw new RuntimeException('ลบสมาชิกกลุ่มไม่สำเร็จ');
                $stmt = sqlsrv_query($connection, 'DELETE FROM dbo.WF_USER_HDR WHERE c_name=?', [$groupName]);
                if (!$stmt || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('ลบกลุ่มไม่สำเร็จ');
                if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันการลบกลุ่มไม่สำเร็จ');
            } catch (Throwable $error) { sqlsrv_rollback($connection); throw $error; }
            echo json_encode(['success'=>true, 'message'=>'ลบกลุ่มแล้ว'], JSON_UNESCAPED_UNICODE); exit;
        }

        if (in_array($action, ['rename_group','copy_group'], true)) {
            $source = trim((string)($payload['source_name'] ?? ''));
            if ($source === '') throw new RuntimeException('ไม่พบกลุ่มต้นฉบับ');
            $requireVisibleGroup($source, $action === 'rename_group');
            if (!sqlsrv_begin_transaction($connection)) throw new RuntimeException('เริ่มบันทึกไม่สำเร็จ');
            try {
                $stmt=sqlsrv_query($connection,'SELECT c_name FROM dbo.WF_USER_HDR WITH (UPDLOCK,HOLDLOCK) WHERE c_name=?',[$source]);
                if (!$stmt || !sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) throw new RuntimeException('ไม่พบกลุ่มต้นฉบับ กรุณาโหลดกลุ่มใหม่');
                if ($source !== $groupName || $action === 'copy_group') {
                    $stmt=sqlsrv_query($connection,'SELECT c_name FROM dbo.WF_USER_HDR WITH (UPDLOCK,HOLDLOCK) WHERE c_name=?',[$groupName]);
                    if (!$stmt) throw new RuntimeException('ตรวจชื่อกลุ่มไม่สำเร็จ');
                    if (sqlsrv_fetch_array($stmt,SQLSRV_FETCH_ASSOC)) throw new RuntimeException('มีชื่อกลุ่มนี้แล้ว กรุณาใช้ชื่อใหม่');
                    if ($action === 'copy_group') {
                        if (!sqlsrv_query($connection,'INSERT dbo.WF_USER_HDR(c_name,visibility,owner_user_id,owner_cost_id) VALUES (?,?,?,?)',[$groupName,$newVisibility,$loginUserId,$loginCostId ?: null])) throw new RuntimeException('สร้างกลุ่มไม่สำเร็จ');
                        if (!sqlsrv_query($connection,'INSERT dbo.WF_USER_DTL(user_id,c_name,dc_emp_id,wf_order,review_action,sign_action,approve_action) SELECT user_id,?,dc_emp_id,wf_order,review_action,sign_action,approve_action FROM dbo.WF_USER_DTL WHERE c_name=?',[$groupName,$source])) throw new RuntimeException('คัดลอกสมาชิกไม่สำเร็จ');
                    } else {
                        if (!sqlsrv_query($connection,'INSERT dbo.WF_USER_HDR(c_name,visibility,owner_user_id,owner_cost_id) SELECT ?,visibility,owner_user_id,owner_cost_id FROM dbo.WF_USER_HDR WHERE c_name=?',[$groupName,$source])) throw new RuntimeException('แก้ไขชื่อกลุ่มไม่สำเร็จ');
                        if (!sqlsrv_query($connection,'UPDATE dbo.WF_USER_DTL SET c_name=? WHERE c_name=?',[$groupName,$source])) throw new RuntimeException('แก้ไขกลุ่มสมาชิกไม่สำเร็จ');
                        if (!sqlsrv_query($connection,'DELETE FROM dbo.WF_USER_HDR WHERE c_name=?',[$source])) throw new RuntimeException('แก้ไขชื่อกลุ่มไม่สำเร็จ');
                    }
                }
                $replacements = $payload['replacements'] ?? [];
                if (!is_array($replacements) || count($replacements) > 999) throw new RuntimeException('ข้อมูลผู้ใช้ที่แก้ไขไม่ถูกต้อง');
                foreach ($replacements as $replacement) {
                    if (!is_array($replacement)) throw new RuntimeException('ข้อมูลผู้ใช้ที่แก้ไขไม่ถูกต้อง');
                    $oldUser = (string) ($replacement['old_user_id'] ?? '');
                    $oldEmployee = (string) ($replacement['old_dc_emp_id'] ?? '');
                    $newUser = (string) ($replacement['user_id'] ?? '');
                    $newEmployee = (string) ($replacement['dc_emp_id'] ?? '');
                    if ($oldUser === '' || $newUser === '' || !ctype_digit($oldEmployee) || !ctype_digit($newEmployee)) throw new RuntimeException('กรุณาเลือกผู้ใช้ให้ถูกต้อง');
                    $stmt = sqlsrv_query($connection, 'SELECT u.dc_user_id FROM NMU_DATACENTER.dbo.dc_user u INNER JOIN NMU_DATACENTER.dbo.dc_emp e ON e.dc_emp_id=u.dc_emp_id WHERE u.dc_user_id=? AND e.dc_emp_id=? AND u.i_enable=1 AND u.i_delete=2 AND e.i_enable=1 AND e.i_delete=2', [$newUser, (int) $newEmployee]);
                    if (!$stmt || !sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) throw new RuntimeException('ผู้ใช้ที่เลือกไม่พร้อมใช้งาน กรุณาค้นหาใหม่');
                    $stmt = sqlsrv_query($connection, 'SELECT user_id FROM dbo.WF_USER_DTL WITH (UPDLOCK,HOLDLOCK) WHERE c_name=? AND user_id=? AND NOT (user_id=? AND dc_emp_id=?)', [$groupName, $newUser, $oldUser, (int) $oldEmployee]);
                    if (!$stmt) throw new RuntimeException('ตรวจสอบสมาชิกซ้ำไม่สำเร็จ');
                    if (sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) throw new RuntimeException('ผู้ใช้นี้อยู่ในกลุ่มแล้ว');
                    $stmt = sqlsrv_query($connection, 'SELECT wf_order,review_action,sign_action,approve_action FROM dbo.WF_USER_DTL WITH (UPDLOCK,HOLDLOCK) WHERE c_name=? AND user_id=? AND dc_emp_id=?', [$groupName, $oldUser, (int) $oldEmployee]);
                    $existing = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
                    if (!$existing) throw new RuntimeException('สมาชิกเดิมเปลี่ยนแปลงแล้ว กรุณาโหลดกลุ่มใหม่');
                    $order = filter_var($replacement['wf_order'] ?? $existing['wf_order'], FILTER_VALIDATE_INT);
                    if ($order === false || $order < 1 || $order > 999) throw new RuntimeException('ลำดับ WF ต้องอยู่ระหว่าง 1–999');
                    $actions = [];
                    foreach (['review_action', 'sign_action', 'approve_action'] as $key) {
                        $value = $replacement[$key] ?? $existing[$key];
                        if (!in_array($value, [true, false, 0, 1], true)) throw new RuntimeException('ข้อมูลสิทธิ์ไม่ถูกต้อง');
                        $actions[] = (int) $value;
                    }
                    if (array_sum($actions) === 0) throw new RuntimeException('กรุณาเลือกสิทธิ์อย่างน้อย 1 รายการ');
                    $stmt = sqlsrv_query($connection, 'UPDATE dbo.WF_USER_DTL SET user_id=?, dc_emp_id=?, wf_order=?, review_action=?, sign_action=?, approve_action=? WHERE c_name=? AND user_id=? AND dc_emp_id=?', [$newUser, (int) $newEmployee, $order, ...$actions, $groupName, $oldUser, (int) $oldEmployee]);
                    if (!$stmt || sqlsrv_rows_affected($stmt) !== 1) throw new RuntimeException('สมาชิกเดิมเปลี่ยนแปลงแล้ว กรุณาโหลดกลุ่มใหม่');
                    if (array_key_exists('wf_order', $replacement) && $order !== (int) $existing['wf_order']) $reorderMembers($groupName, $newUser, $order);
                }
                $reorderMembers($groupName);
                if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันบันทึกไม่สำเร็จ');
            } catch (Throwable $e) {sqlsrv_rollback($connection);throw $e;}
            echo json_encode(['success'=>true,'message'=>'บันทึกกลุ่มแล้ว'],JSON_UNESCAPED_UNICODE);exit;
        }

        if ($action === 'create_group') {
            $currentUser = Auth::user() ?? [];
            $currentUserId = (string) ($currentUser['user_id'] ?? '');
            $currentEmployeeId = filter_var($currentUser['employee_id'] ?? null, FILTER_VALIDATE_INT);
            if ($currentUserId === '' || !$currentEmployeeId) throw new RuntimeException('ไม่พบข้อมูลผู้ใช้ที่ล็อกอินสำหรับกำหนด WF1');
            if (!sqlsrv_begin_transaction($connection)) throw new RuntimeException('เริ่มสร้างกลุ่มไม่สำเร็จ');
            try {
                $statement = sqlsrv_query($connection, 'INSERT INTO dbo.WF_USER_HDR (c_name,visibility,owner_user_id,owner_cost_id) VALUES (?,?,?,?)', [$groupName,$newVisibility,$loginUserId,$loginCostId ?: null]);
                if ($statement === false) {
                    $errors = sqlsrv_errors();
                    if (isset($errors[0]['code']) && in_array((int) $errors[0]['code'], [2601, 2627], true)) throw new RuntimeException('มีชื่อกลุ่มนี้อยู่แล้ว');
                    throw new RuntimeException('สร้างกลุ่มผู้ลงนามไม่สำเร็จ');
                }
                $statement = sqlsrv_query($connection, <<<'SQL'
INSERT INTO dbo.WF_USER_DTL
    (user_id, c_name, dc_emp_id, wf_order, review_action, sign_action, approve_action)
VALUES (?, ?, ?, 1, 1, 1, 0)
SQL, [$currentUserId, $groupName, $currentEmployeeId]);
                if ($statement === false) throw new RuntimeException('เพิ่มผู้ใช้ที่ล็อกอินเป็น WF1 ไม่สำเร็จ');
                if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันการสร้างกลุ่มไม่สำเร็จ');
            } catch (Throwable $error) {
                sqlsrv_rollback($connection);
                throw $error;
            }
            sqlsrv_close($connection);
            echo json_encode(['success' => true, 'message' => 'สร้างกลุ่มและกำหนดผู้ใช้ปัจจุบันเป็น WF1 แล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'set_current_user_wf1') {
            $currentUser = Auth::user() ?? [];
            $currentUserId = (string) ($currentUser['user_id'] ?? '');
            $currentEmployeeId = filter_var($currentUser['employee_id'] ?? null, FILTER_VALIDATE_INT);
            if ($currentUserId === '' || !$currentEmployeeId) throw new RuntimeException('ไม่พบข้อมูลผู้ใช้ที่ล็อกอินสำหรับกำหนด WF1');
            if (!sqlsrv_begin_transaction($connection)) throw new RuntimeException('เริ่มตั้งค่า WF1 ไม่สำเร็จ');
            try {
                $existsStmt = sqlsrv_query($connection, 'SELECT COUNT(*) AS member_count FROM dbo.WF_USER_DTL WITH (UPDLOCK, HOLDLOCK) WHERE c_name = ? AND user_id = ?', [$groupName, $currentUserId]);
                if ($existsStmt === false) throw new RuntimeException('ตรวจสอบสมาชิกกลุ่มไม่สำเร็จ');
                $exists = (int) (sqlsrv_fetch_array($existsStmt, SQLSRV_FETCH_ASSOC)['member_count'] ?? 0) > 0;
                sqlsrv_free_stmt($existsStmt);
                if ($exists) {
                    $statement = sqlsrv_query($connection, 'UPDATE dbo.WF_USER_DTL SET wf_order = 0 WHERE c_name = ? AND user_id = ?', [$groupName, $currentUserId]);
                    if ($statement === false) throw new RuntimeException('เตรียมเปลี่ยนลำดับ WF1 ไม่สำเร็จ');
                }
                $statement = sqlsrv_query($connection, 'UPDATE dbo.WF_USER_DTL SET wf_order = wf_order + 1 WHERE c_name = ? AND user_id <> ?', [$groupName, $currentUserId]);
                if ($statement === false) throw new RuntimeException('เลื่อนลำดับ Workflow เดิมไม่สำเร็จ');
                if ($exists) {
                    $statement = sqlsrv_query($connection, 'UPDATE dbo.WF_USER_DTL SET wf_order = 1, review_action = 1, sign_action = 1 WHERE c_name = ? AND user_id = ?', [$groupName, $currentUserId]);
                } else {
                    $statement = sqlsrv_query($connection, 'INSERT INTO dbo.WF_USER_DTL (user_id, c_name, dc_emp_id, wf_order, review_action, sign_action, approve_action) VALUES (?, ?, ?, 1, 1, 1, 0)', [$currentUserId, $groupName, $currentEmployeeId]);
                }
                if ($statement === false) throw new RuntimeException('กำหนดผู้ใช้ปัจจุบันเป็น WF1 ไม่สำเร็จ');
                $reorderMembers($groupName, $currentUserId, 1);
                if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันการตั้งค่า WF1 ไม่สำเร็จ');
            } catch (Throwable $error) {
                sqlsrv_rollback($connection);
                throw $error;
            }
            sqlsrv_close($connection);
            echo json_encode(['success' => true, 'message' => 'กำหนดผู้ใช้ปัจจุบันเป็น WF1 แล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'add_member') {
            $userId = trim((string) ($payload['user_id'] ?? ''));
            $employeeId = trim((string) ($payload['dc_emp_id'] ?? ''));
            $wfOrder = (int) ($payload['wf_order'] ?? 0);
            if ($userId === '' || !ctype_digit($employeeId)) {
                throw new RuntimeException('กรุณาเลือกผู้ใช้งานให้ถูกต้อง');
            }
            if ($wfOrder < 1 || $wfOrder > 999) {
                throw new RuntimeException('ลำดับ WF ต้องอยู่ระหว่าง 1–999');
            }
            $countStmt = sqlsrv_query($connection, 'SELECT COUNT(*) member_count FROM dbo.WF_USER_DTL WHERE c_name=?', [$groupName]);
            if ($countStmt === false) throw new RuntimeException('ตรวจสอบลำดับสมาชิกไม่สำเร็จ');
            $memberCount = (int) (sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC)['member_count'] ?? 0);
            sqlsrv_free_stmt($countStmt);
            // สมาชิกคนแรกคือเจ้าหน้าที่ผู้จัดทำและต้องเป็น WF1; สมาชิกถัดไปเริ่ม WF2
            $wfOrder = $memberCount === 0 ? 1 : max(2, $wfOrder);
            $params = [
                $userId, $groupName, (int) $employeeId,
                $wfOrder,
                !empty($payload['review_action']) ? 1 : 0,
                !empty($payload['sign_action']) ? 1 : 0,
                !empty($payload['approve_action']) ? 1 : 0,
            ];
            if ($params[4] + $params[5] + $params[6] === 0) {
                throw new RuntimeException('กรุณาเลือก Action อย่างน้อย 1 รายการ');
            }
            $statement = sqlsrv_query($connection, <<<'SQL'
INSERT INTO dbo.WF_USER_DTL
    (user_id, c_name, dc_emp_id, wf_order, review_action, sign_action, approve_action)
VALUES (?, ?, ?, ?, ?, ?, ?)
SQL, $params);
            if ($statement === false) {
                $errors = sqlsrv_errors();
                if (isset($errors[0]['code']) && in_array((int) $errors[0]['code'], [2601, 2627], true)) {
                    throw new RuntimeException('ผู้ใช้นี้อยู่ในกลุ่มแล้ว');
                }
                throw new RuntimeException('เพิ่มผู้ใช้เข้ากลุ่มไม่สำเร็จ');
            }
            $reorderMembers($groupName, $userId, $wfOrder);
            if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันลำดับไม่สำเร็จ');
            $memberTransaction = false;
            sqlsrv_close($connection);
            echo json_encode(['success' => true, 'message' => 'เพิ่มผู้ใช้แล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'remove_member') {
            $userId = trim((string) ($payload['user_id'] ?? ''));
            $employeeId = trim((string) ($payload['dc_emp_id'] ?? ''));
            $statement = sqlsrv_query($connection,
                'DELETE FROM dbo.WF_USER_DTL WHERE c_name = ? AND user_id = ? AND dc_emp_id = ?',
                [$groupName, $userId, (int) $employeeId]);
            if ($statement === false) throw new RuntimeException('ลบผู้ใช้ออกจากกลุ่มไม่สำเร็จ');
            $reorderMembers($groupName);
            if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันลำดับไม่สำเร็จ');
            $memberTransaction = false;
            sqlsrv_close($connection);
            echo json_encode(['success' => true, 'message' => 'ลบผู้ใช้แล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'update_order') {
            $userId = trim((string) ($payload['user_id'] ?? ''));
            $employeeId = trim((string) ($payload['dc_emp_id'] ?? ''));
            $wfOrder = (int) ($payload['wf_order'] ?? 0);
            if ($userId === '' || !ctype_digit($employeeId) || $wfOrder < 1 || $wfOrder > 999) {
                throw new RuntimeException('ข้อมูลลำดับ Workflow ไม่ถูกต้อง');
            }
            $firstStmt = sqlsrv_query($connection, <<<'SQL'
SELECT TOP (1) CAST(user_id AS nvarchar(100)) user_id, dc_emp_id
FROM dbo.WF_USER_DTL WHERE c_name=? ORDER BY wf_order, dc_emp_id
SQL, [$groupName]);
            if ($firstStmt === false) throw new RuntimeException('ตรวจสอบ WF1 ไม่สำเร็จ');
            $first = sqlsrv_fetch_array($firstStmt, SQLSRV_FETCH_ASSOC) ?: null;
            sqlsrv_free_stmt($firstStmt);
            $isOfficer = $first && (string)$first['user_id'] === $userId && (string)$first['dc_emp_id'] === $employeeId;
            $statement = sqlsrv_query($connection, <<<'SQL'
UPDATE dbo.WF_USER_DTL
SET wf_order = ?
WHERE c_name = ? AND user_id = ? AND dc_emp_id = ?
SQL, [$wfOrder, $groupName, $userId, (int) $employeeId]);
            if ($statement === false) throw new RuntimeException('แก้ไขลำดับ Workflow ไม่สำเร็จ');
            $reorderMembers($groupName, $userId, $wfOrder);
            if (!sqlsrv_commit($connection)) throw new RuntimeException('ยืนยันลำดับไม่สำเร็จ');
            $memberTransaction = false;
            sqlsrv_close($connection);
            echo json_encode(['success' => true, 'message' => 'แก้ไขลำดับ Workflow แล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('ไม่รู้จักคำสั่งที่ส่งมา');
    }

    $employeeQuery = trim((string) ($_GET['employee_q'] ?? ''));
    if (array_key_exists('employee_q', $_GET)) {
        $like = '%' . $employeeQuery . '%';
        $statement = sqlsrv_query($connection, <<<'SQL'
SELECT TOP (50)
    CAST(u.dc_user_id AS nvarchar(100)) AS user_id,
    u.c_user_name,
    e.dc_emp_id,
    e.c_name
FROM NMU_DATACENTER.dbo.dc_emp AS e
INNER JOIN NMU_DATACENTER.dbo.dc_user AS u ON u.dc_emp_id = e.dc_emp_id
WHERE u.i_enable = 1 AND u.i_delete = 2
  AND e.i_enable = 1 AND e.i_delete = 2
  AND (? = N'' OR e.c_name LIKE ? OR u.c_user_name LIKE ?)
ORDER BY e.c_name
SQL, [$employeeQuery, $like, $like]);
        if ($statement === false) throw new RuntimeException('ค้นหาผู้ใช้งานไม่สำเร็จ');
        $employees = [];
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $employees[] = [
                'user_id' => (string) $row['user_id'],
                'c_user_name' => (string) $row['c_user_name'],
                'dc_emp_id' => (string) $row['dc_emp_id'],
                'c_name' => (string) $row['c_name'],
            ];
        }
        sqlsrv_free_stmt($statement);
        sqlsrv_close($connection);
        echo json_encode(['success' => true, 'employees' => $employees], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $groupId = trim((string) ($_GET['group_id'] ?? ''));

    if ($groupId !== '') {
        $groupAccess = $requireVisibleGroup($groupId);
        $sql = <<<'SQL'
SELECT
    h.c_name AS group_id,
    h.c_name AS group_name,
    CAST(d.user_id AS nvarchar(100)) AS user_id,
    COALESCE(e.c_name, N'พนักงาน ' + TRY_CAST(d.dc_emp_id AS nvarchar(100))) AS member_name,
    d.dc_emp_id,
    d.wf_order,
    d.review_action,
    d.sign_action,
    d.approve_action
FROM dbo.WF_USER_HDR AS h
LEFT JOIN dbo.WF_USER_DTL AS d ON CAST(d.c_name AS nvarchar(255)) = CAST(h.c_name AS nvarchar(255))
LEFT JOIN NMU_DATACENTER.dbo.dc_emp AS e ON TRY_CAST(e.dc_emp_id AS nvarchar(50)) = TRY_CAST(d.dc_emp_id AS nvarchar(50))
WHERE CAST(h.c_name AS nvarchar(255)) = ?
ORDER BY d.wf_order, d.dc_emp_id
SQL;
        $statement = sqlsrv_query($connection, $sql, [$groupId]);
        if ($statement === false) {
            $sqlErrors = sqlsrv_errors();
            $isPermissionDenied = false;
            foreach ($sqlErrors ?: [] as $sqlError) {
                if ((int) ($sqlError['code'] ?? 0) === 229) {
                    $isPermissionDenied = true;
                    break;
                }
            }
            if ($isPermissionDenied) {
                // สิทธิ์ SELECT บน NMU_DATACENTER.dbo.dc_emp ยังไม่ถูกอนุมัติ —
                // fallback: อ่านเฉพาะ WF_USER_HDR/WF_USER_DTL โดยไม่ join ชื่อพนักงานจริง
                error_log('WF groups: dc_emp SELECT permission denied, falling back to placeholder names');
                $fallbackSql = <<<'SQL'
SELECT
    h.c_name AS group_id,
    h.c_name AS group_name,
    CAST(d.user_id AS nvarchar(100)) AS user_id,
    N'พนักงาน ' + TRY_CAST(d.dc_emp_id AS nvarchar(100)) AS member_name,
    d.dc_emp_id,
    d.wf_order,
    d.review_action,
    d.sign_action,
    d.approve_action
FROM dbo.WF_USER_HDR AS h
LEFT JOIN dbo.WF_USER_DTL AS d ON CAST(d.c_name AS nvarchar(255)) = CAST(h.c_name AS nvarchar(255))
WHERE CAST(h.c_name AS nvarchar(255)) = ?
ORDER BY d.wf_order, d.dc_emp_id
SQL;
                $statement = sqlsrv_query($connection, $fallbackSql, [$groupId]);
            }
            if ($statement === false) {
                error_log('WF groups read-members query failed: ' . json_encode($sqlErrors, JSON_UNESCAPED_UNICODE));
                throw new RuntimeException('ไม่สามารถอ่านสมาชิกกลุ่มผู้ลงนามได้: ' . (string) ($sqlErrors[0]['message'] ?? ''));
            }
        }
        $members = [];
        $groupName = '';
        while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
            $groupName = (string) $row['group_name'];
            if ($row['user_id'] === null) {
                continue;
            }
            $members[] = [
                'user_id' => (string) $row['user_id'],
                'c_name' => (string) $row['member_name'],
                'dc_emp_id' => (string) $row['dc_emp_id'],
                'wf_order' => (int) $row['wf_order'],
                'review_action' => (bool) $row['review_action'],
                'sign_action' => (bool) $row['sign_action'],
                'approve_action' => (bool) $row['approve_action'],
            ];
        }
        sqlsrv_free_stmt($statement);
        sqlsrv_close($connection);
        echo json_encode(['success' => true, 'group' => [
            'id' => $groupId,
            'name' => $groupName,
            'visibility' => $groupAccess['visibility'],
            'can_edit' => $groupAccess['can_edit'],
            'members' => $members,
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $like = '%' . $search . '%';
    $sql = <<<'SQL'
SELECT TOP (100)
    h.c_name AS id,
    h.c_name AS name,
    h.visibility,
    COUNT(d.dc_emp_id) AS member_count
FROM dbo.WF_USER_HDR AS h
LEFT JOIN dbo.WF_USER_DTL AS d ON d.c_name = h.c_name
WHERE (? = N'' OR h.c_name LIKE ?)
  AND __VISIBILITY_FILTER__
GROUP BY h.c_name,h.visibility
ORDER BY h.c_name
SQL;
    $sql = str_replace('__VISIBILITY_FILTER__', $visibilitySql, $sql);
    $statement = sqlsrv_query($connection, $sql, [$search, $like, ...$visibilityParams]);
    if ($statement === false) {
        throw new RuntimeException('ไม่สามารถค้นหากลุ่มผู้ลงนามได้');
    }
    $groups = [];
    while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) {
        $groups[] = [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'visibility' => (string) $row['visibility'],
            'member_count' => (int) $row['member_count'],
        ];
    }
    sqlsrv_free_stmt($statement);
    sqlsrv_close($connection);
    echo json_encode(['success' => true, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (!empty($memberTransaction) && isset($connection) && function_exists('sqlsrv_rollback')) sqlsrv_rollback($connection);
    $sqlErrors = function_exists('sqlsrv_errors') ? (sqlsrv_errors() ?: []) : [];
    error_log('WF groups API failed: ' . $error->getMessage() . ' ' . json_encode($sqlErrors, JSON_UNESCAPED_UNICODE));
    $message = $error->getMessage();
    foreach ($sqlErrors as $sqlError) {
        if ((int) ($sqlError['code'] ?? 0) === 207 && preg_match('/visibility|owner_user_id|owner_cost_id/i', (string) ($sqlError['message'] ?? ''))) {
            $message = 'ฐานข้อมูลกลุ่มผู้ลงนามยังไม่มีคอลัมน์สิทธิ์ กรุณารัน database/EIS_DOCS/011_wf_group_visibility.sql บนฐานข้อมูล EIS_DOCS';
            break;
        }
        if ((int) ($sqlError['code'] ?? 0) === 229) {
            $message = 'บัญชีฐานข้อมูลไม่มีสิทธิ์อ่านหรือแก้ไขข้อมูลกลุ่มผู้ลงนาม กรุณาตรวจสิทธิ์ SQL Server';
            break;
        }
    }
    if (http_response_code() !== 403) http_response_code(500);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
}
