<?php

require_once __DIR__ . "/../conf/config.php";
require_once __DIR__ . "/../lib/database/DatabaseServer.php";

header("Content-Type: application/json; charset=utf-8");
header('Cache-Control: no-store, private');
$portalEntry = (string)($_GET['portal_entry'] ?? '') === '1';

// Keep the portal verifier in this entry point so a partial deployment cannot
// remove an authentication dependency. Never trust the supplied user ID alone.
function procurePortalAllows(int $centerUserId): bool
{
    $cookie = (string)($_COOKIE['PHPSESSID'] ?? '');
    $path = getenv('EIS_PERMISSION_SESSION_DIR') ?: 'C:/xampp/htdocs/NMU_permission/tmp/sessions';
    if ($centerUserId <= 0 || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $cookie) || !is_file($path . '/sess_' . $cookie)) return false;
    $name = session_name(); $id = session_id(); $directory = session_save_path();
    $cookies = ini_get('session.use_cookies'); $snapshot = $_SESSION;
    session_write_close();
    $portal = [];
    try {
        session_name('PHPSESSID'); session_id($cookie); session_save_path($path);
        if (session_start(['use_cookies'=>false, 'read_and_close'=>true])) $portal = $_SESSION;
    } finally {
        session_name($name); session_id($id); session_save_path($directory);
        ini_set('session.use_cookies', $cookies);
        if (!session_start()) throw new RuntimeException('ไม่สามารถเปิดเซสชัน Procure ได้');
        $_SESSION = $snapshot;
    }
    return isset($portal['i_type_user']) && (int)($portal['dc_user_id'] ?? 0) === $centerUserId && (int)($portal['i_permit_procure'] ?? 0) > 0;
}

$jsonInput = json_decode((string) file_get_contents("php://input"), true);
// ss_user_id remains the Procure ID used by the existing SSO caller.
// The user selector supplies a Data Center ID explicitly; never guess by ID.
$centerUserId = $_REQUEST["ss_center_user_id"] ?? ($jsonInput["ss_center_user_id"] ?? null);
$requestUserId = $centerUserId ?? ($_REQUEST["ss_user_id"] ?? ($jsonInput["ss_user_id"] ?? null));
$requestUserId = filter_var($requestUserId, FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);

if ($requestUserId === false || $requestUserId === null) {
    http_response_code(400);
    echo json_encode([
        "reval" => 1,
        "success" => "Error",
        "msg" => "Missing or invalid ss_user_id",
    ]);
    exit;
}

$db        = new DatabaseServer();
$userFilter = $centerUserId !== null
    ? "EXISTS (SELECT 1 FROM " . DB_CENTER . "dc_user center_user
               WHERE center_user.dc_user_id = ?
                 AND center_user.c_user_name = a.c_user_name
                 AND center_user.i_enable = 1 AND center_user.i_delete = 2)"
    : "a.dc_user_id = ?";
$sqlMain = "select a.dc_user_id, a.c_user_name, cost.dc_cost_id, a.c_full_name
                , isnull(cost.c_name,'') as cost_name
                , isnull(cost.c_code,'') as cost_code
                ,cost.dc_area_id as dc_area_id
                , a.i_type_user
                , isnull(cost.dc_cost_acc_id,0) as dc_cost_acc_id
                , isnull(c.c_name,'') as 'c_sp_emp'
                , isnull(e.c_name,'') as 'c_department_type'
                , 'สายงาน '+d.c_name as 'c_department'
                , CASE
                WHEN c.i_level = 1 THEN 'หัวหน้าหน่วยงาน'
                WHEN c.i_level = 2 THEN 'หัวหน้าสายงาน'
                ELSE 'ผู้ปฎิบัติงาน' END AS 'c_position'
                , isnull(b.dc_emp_id,0) as dc_emp_id
                 , isnull(c.sp_emp_id,0) as sp_emp_id
                , isnull(c.dc_department_id,0) as dc_department_id
                , isnull(d.dc_department_type_id,0) as dc_department_type_id
                , isnull(d.i_seq,0) as i_seq
                , isnull(c.i_level,0) as i_level
                , isnull((select top 1 dc_user_id from " . DB_CENTER . "dc_user where c_user_name = a.c_user_name),0) as dc_center_user
            from dbo.dc_user a
                outer apply (
                    select top 1 cu.dc_emp_id, cu.dc_cost_id
                    from NMU_DATACENTER.dbo.dc_user cu
                    where cu.c_user_name = a.c_user_name
                    order by cu.dc_user_id
                ) center_user
                outer apply (
                    select top 1 ce.dc_cost_id
                    from NMU_DATACENTER.dbo.dc_emp ce
                    where ce.dc_emp_id = center_user.dc_emp_id
                       or ce.c_email = a.c_user_name
                    order by case when ce.dc_emp_id = center_user.dc_emp_id then 0 else 1 end,
                             ce.dc_emp_id
                ) center_emp
                left join NMU_DATACENTER.dbo.dc_cost cost
                    on cost.dc_cost_id = coalesce(nullif(center_emp.dc_cost_id,0),
                                                 nullif(center_user.dc_cost_id,0), a.dc_cost_id)
                left join dbo.dc_emp b on b.dc_emp_id = a.dc_emp_id
                left join dbo.sp_emp c on c.dc_emp_id = b.dc_emp_id
                left join dbo.sp_department d on d.dc_department_id = c.dc_department_id
                left join dbo.sp_department_type e on e.dc_department_type_id = d.dc_department_type_id
    WHERE {$userFilter} AND a.i_enable = 1 AND a.i_delete = 2";
$arrParam = [$requestUserId];
$re = [
    "reval" => 1,
    "success" => "Error",
    "msg" => "No active Procure user matches this account",
];
$stmt = $db->QueryParam($sqlMain, $arrParam);
while ($data = $db->Fetch($stmt)) {
    if ($portalEntry) {
        if (!procurePortalAllows((int)$data['dc_center_user'])) { http_response_code(401); echo json_encode(['reval'=>1,'success'=>false,'msg'=>'กรุณาเข้าสู่ระบบผ่าน NMU_permission']); exit; }
    }
    // Do not carry any authenticated fields from the previous user.
    require_once __DIR__ . '/../conf/presence/AdminIdentity.php';
        $verifiedAdminOperator = ProcureAdminIdentity::forLogin($data);
        ProcurePresence::revoke('PROCURE', (string)($_SESSION['user_id'] ?? ''), (string)($_SESSION['last_login'] ?? ''));
        $_SESSION = array();
    if (!session_regenerate_id(true)) { http_response_code(500); echo json_encode(['reval'=>1, 'success'=>false, 'msg'=>'ไม่สามารถสร้างเซสชันได้ กรุณาเข้าสู่ระบบใหม่'], JSON_UNESCAPED_UNICODE); exit; }
    $_SESSION["DOMAIN"] = DOMAIN['en']; //id ผู้ใช้งาน 
    $_SESSION["DOMAIN_NAME"] = DOMAIN['th']; //id ผู้ใช้งาน  
    $_SESSION["menu"] = 'sp/dashBard.php'; //id ผู้ใช้งาน  
    
    $_SESSION["c_user_name"] = $data["c_user_name"];
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
     $_SESSION["i_type_user"] = intval($data["i_type_user"]);
        $_SESSION["admin_operator"] = $verifiedAdminOperator; //ประเภทผู้ใช้งาน
     $_SESSION["dc_cost_acc_id"] = intval($data["dc_cost_acc_id"]); //id ศูนย์ต้นทุนทางบัญชีของหน่วยงานที่ log in
     $_SESSION["last_login"] = date("Y-m-d H:i:s");
     $_SESSION["last_activity"] = time();
     $_SESSION['created'] = time();
     
    $re = array("reval" => 0, "success" => "Success", "msg" => "Login Success");
}

session_write_close();
if ($portalEntry && $re['reval'] === 0) { header('Content-Type: text/html; charset=utf-8'); header('Location: /procure/', true, 303); exit; }
echo json_encode($re);
exit;


