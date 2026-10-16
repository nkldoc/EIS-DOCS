<?php
require_once __DIR__ . '/../conf/config.php';
require_once __DIR__ . '/../lib/database/DatabaseServer.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
try {
    $ticket = (string)($_GET['ticket'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) throw new RuntimeException('สิทธิ์เข้าสู่ระบบไม่ถูกต้อง');
    $db = new DatabaseServer();
    $stmt = sqlsrv_query($db->conn, "UPDATE " . DB_CENTER . "DOCS_SUPPLIES_IMPERSONATION SET used_at=SYSUTCDATETIME() OUTPUT inserted.admin_user_id,inserted.target_user_id WHERE ticket_hash=? AND used_at IS NULL AND expires_at>SYSUTCDATETIME()", [hash('sha256',$ticket)]);
    if (!$stmt) throw new RuntimeException('ตรวจสอบสิทธิ์ไม่สำเร็จ');
    $grant = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$grant) throw new RuntimeException('สิทธิ์เข้าสู่ระบบหมดอายุหรือถูกใช้แล้ว กรุณา Login Supplies จาก EIS-DOCS อีกครั้ง');
    $stmt = sqlsrv_query($db->conn, "SELECT t.c_user_name FROM " . DB_CENTER . "dc_user t WHERE t.dc_user_id=? AND t.dc_cost_id=38 AND t.i_enable=1 AND t.i_delete=2 AND EXISTS (SELECT 1 FROM " . DB_CENTER . "dc_user a WHERE a.dc_user_id=? AND a.i_type_user=2 AND a.i_enable=1 AND a.i_delete=2)", [$grant['target_user_id'],$grant['admin_user_id']]);
    if (!$stmt) throw new RuntimeException('ตรวจสอบบัญชีไม่สำเร็จ');
    $target = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$target) throw new RuntimeException('ไม่มีสิทธิ์สวมบัญชีนี้ หรือผู้ใช้ไม่ได้อยู่หน่วยงานพัสดุ (dc_cost_id = 38)');
$sqlMain = "select a.dc_user_id, a.dc_cost_id, b.dc_emp_id, a.c_full_name
                , isnull((select top 1 c_name from dbo.dc_cost where dc_cost_id=a.dc_cost_id),'ไม่พบ') as cost_name
                , isnull((select top 1 c_code from dbo.dc_cost where dc_cost_id=a.dc_cost_id),'ไม่พบ') as cost_code
                ,(select top 1 dc_area_id from dbo.dc_cost where dc_cost_id=a.dc_cost_id) as dc_area_id
                , a.i_type_user
                , isnull((select top 1  bb.dc_cost_acc_id from dbo.dc_cost bb where bb.dc_cost_id=a.dc_cost_id),0) as dc_cost_acc_id
                , isnull(c.c_name,'') as 'c_sp_emp'
                , isnull(e.c_name,'') as 'c_department_type'
                , 'สายงาน '+d.c_name as 'c_department'
                , CASE
                WHEN c.i_level = 1 THEN 'หัวหน้าหน่วยงาน'
                WHEN c.i_level = 2 THEN 'หัวหน้าสายงาน'
                ELSE 'ผู้ปฎิบัติงาน' END AS 'c_position'
                , isnull(c.dc_emp_id,0) as dc_emp_id
                 , isnull(c.sp_emp_id,0) as sp_emp_id
                , isnull(c.dc_department_id,0) as dc_department_id
                , isnull(d.dc_department_type_id,0) as dc_department_type_id
                , isnull(d.i_seq,0) as i_seq
                , isnull(c.i_level,0) as i_level
                , isnull((select top 1 dc_user_id from " . DB_CENTER . "dc_user where c_user_name = a.c_user_name),0) as dc_center_user
            from dbo.dc_user a
                left join dbo.dc_emp b on b.dc_emp_id = a.dc_emp_id
                left join dbo.sp_emp c on c.dc_emp_id = b.dc_emp_id
                left join dbo.sp_department d on d.dc_department_id = c.dc_department_id
                left join dbo.sp_department_type e on e.dc_department_type_id = d.dc_department_type_id
    WHERE a.c_user_name = ? AND a.dc_cost_id=38 AND a.i_enable=1 AND a.i_delete=2";

    $stmt = sqlsrv_query($db->conn, $sqlMain, [$target['c_user_name']]);
    if (!$stmt) throw new RuntimeException('ตรวจสอบบัญชี Supplies ไม่สำเร็จ');
    $data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    if (!$data) throw new RuntimeException('ไม่พบบัญชี Supplies ที่เปิดใช้งานในหน่วยงานพัสดุ');
    // Do not carry any authenticated fields from the previous user.
    $instance = SuppliesLogin::begin($data, ['impersonation_admin_center_id'=>(int)$grant['admin_user_id']], true);
    require_once dirname(__DIR__) . '/conf/presence/Presence.php';
    Presence::revoke('SUPPLIES', (string)($_SESSION['user_id'] ?? ''), (string)($_SESSION['last_login'] ?? ''));
    $_SESSION = array();
    session_regenerate_id(true);
    $_SESSION["DOMAIN"] = DOMAIN['en']; //id ผู้ใช้งาน 
    $_SESSION["DOMAIN_NAME"] = DOMAIN['th']; //id ผู้ใช้งาน  
    $_SESSION["menu"] = 'sp/dashBard.php'; //id ผู้ใช้งาน  
    
    $_SESSION["user_id"] = intval($data["dc_user_id"]); //id ผู้ใช้งาน
     $_SESSION["user_name"] = $data["c_full_name"]; //ชื่อผู้ใช้งาน
     $_SESSION["dc_emp_id"] = intval($data["dc_emp_id"]); //พนักงานผู้รับผิดชอบ
    // c_sp_emp c_department_type c_department c_position sp_emp_id dc_department_id dc_department_type_id i_seq i_level
     $_SESSION["sp_emp_id"] = intval($data["sp_emp_id"]) ?? null; //
     $_SESSION["dc_department_id"] = intval($data["dc_department_id"]) ?? null; //
     $_SESSION["dc_department_type_id"] = intval($data["dc_department_type_id"]) ?? null; //
     $_SESSION["i_seq"] = intval($data["i_seq"]) ?? null; //
     $_SESSION["i_level"] = intval($data["i_level"]) ?? null; //

     $_SESSION["c_sp_emp"] = $data["c_sp_emp"] ?? null; //
     $_SESSION["c_department_type"] = $data["c_department_type"] ?? null; //
     $_SESSION["c_department"] = $data["c_department"] ?? null; //
     $_SESSION["c_position"] = $data["c_position"] ?? null; //
     $_SESSION["dc_center_user"] = intval($data["dc_center_user"]) ?? null; // 

     /* dc_department_type_id department_type_name dc_department_id department_name */
     $_SESSION["dc_cost_id"] = intval($data["dc_cost_id"]); //id หน่วยงาน
     $_SESSION["cost_name"] = $data["cost_name"]; //หน่วยงาน
     $_SESSION["cost_code"] = $data["cost_code"]; //รหัสหน่วยงาน
     $_SESSION["dc_area_id"] = intval($data["dc_area_id"]); //id หน่วยธุรกิจ
     $_SESSION["i_type_user"] = intval($data["i_type_user"]); //ประเภทผู้ใช้งาน
     $_SESSION["dc_cost_acc_id"] = intval($data["dc_cost_acc_id"]); //id ศูนย์ต้นทุนทางบัญชีของหน่วยงานที่ log in
     $_SESSION["last_login"] = date("Y-m-d H:i:s");
    $_SESSION['login_instance'] = $instance;
    $_SESSION['last_activity'] = time();
     $_SESSION['created'] = time();

    $_SESSION['DOMAIN_LOGIN'] = 'SUPPLIES';
    $_SESSION['impersonation_admin_center_id'] = (int)$grant['admin_user_id'];
    SuppliesLogin::validate();
    // The consumed ticket records the administrator, target, and used_at in Datacenter.
    header('Location: ../index.php'); exit;
} catch (Throwable $error) {
    http_response_code(403);
    echo '<h1>ไม่สามารถ Login Supplies ได้</h1><p role="alert">'.htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8').'</p><a href="/eis-docs/docMain/impersonate.php">กลับไปหน้าสวมสิทธิ์</a>';
}
