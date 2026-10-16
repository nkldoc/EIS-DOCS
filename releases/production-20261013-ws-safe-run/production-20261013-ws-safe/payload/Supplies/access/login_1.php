<?php

require_once __DIR__ . "/../conf/config.php";
require_once __DIR__ . "/../lib/database/DatabaseServer.php";

header("Content-Type: application/json; charset=utf-8");

$jsonInput = json_decode((string) file_get_contents("php://input"), true);
$requestUserId = $_REQUEST["ss_user_id"] ?? ($jsonInput["ss_user_id"] ?? null);
$requestUserId = filter_var($requestUserId, FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);

if ($requestUserId === false || $requestUserId === null) {
    if (isset($jsonInput['userf'], $jsonInput['passwordf'])) {
        $_POST['userf'] = $jsonInput['userf']; $_POST['passwordf'] = $jsonInput['passwordf'];
        require __DIR__ . '/login.php'; exit;
    }
    http_response_code(400);
    echo json_encode([
        "reval" => 1,
        "success" => "Error",
        "msg" => "Missing or invalid ss_user_id",
    ]);
    exit;
}

$db        = new DatabaseServer();
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
    WHERE a.dc_user_id = ? AND a.i_enable=1 AND a.i_delete=2";
$arrParam = [$requestUserId];
$re = [
    "reval" => 1,
    "success" => "Error",
    "msg" => "Supplies user was not found",
];
$stmt = $db->QueryParam($sqlMain, $arrParam);
while ($data = $db->Fetch($stmt)) {
    if (Presence::enabled() && !SuppliesLogin::ssoAllowed($data)) {
        http_response_code(401); echo json_encode(['reval'=>1, 'success'=>false, 'msg'=>'กรุณาเข้าสู่ระบบผ่าน NMU_permission']); exit;
    }
    // Portal re-entry preserves the valid login and named operator.
    if ((string)($_SESSION['user_id'] ?? '') === (string)$data['dc_user_id']
        && !isset($_SESSION['impersonation_admin_center_id'])
        && (int)($_SESSION['i_type_user'] ?? 0) === (int)$data['i_type_user']
        && SuppliesLogin::validate()) {
        $_SESSION['last_activity'] = time();
        $re = ['reval'=>0, 'success'=>'Success', 'msg'=>'Login Success'];
        break;
    }
    $instance = SuppliesLogin::begin($data, ['menu'=>'sp/dashBard.php'], !empty($_GET['portal_entry']));
        SuppliesLogin::activate($data, $instance, ['menu'=>'sp/dashBard.php']);
     $_SESSION["last_activity"] = time();
     $_SESSION['created'] = time();
    //Java Session
//    require_once("../java/Java.inc");
//    $session = java_session();
//    $session->put("DOMAIN", $_SESSION["DOMAIN"]);
//    $session->put("DOMAIN_NAME", $_SESSION["DOMAIN_NAME"]);
//    $session->put("user_id", $_SESSION["user_id"]); 
//    $session->put("user_name", $_SESSION["user_name"]);
//    $session->put("dc_emp_id", $_SESSION["dc_emp_id"]);
//    $session->put("sp_emp_id", $_SESSION["sp_emp_id"]);
//    $session->put("dc_department_id", $_SESSION["dc_department_id"]);
//    $session->put("dc_department_type_id", $_SESSION["dc_department_type_id"]);
//    $session->put("i_seq", $_SESSION["i_seq"]);
//    $session->put("i_level", $_SESSION["i_level"]);
//    $session->put("dc_center_user", intval($data["dc_center_user"]));
//    $session->put("c_sp_emp", $_SESSION["c_sp_emp"]);
//    $session->put("c_department_type", $_SESSION["c_department_type"]);
//    $session->put("c_department", $_SESSION["c_department"]);
//    $session->put("c_position", $_SESSION["c_position"]);
//    $session->put("dc_cost_id", $_SESSION["dc_cost_id"]);
//    $session->put("cost_name", $_SESSION["cost_name"]);
//    $session->put("cost_code", $_SESSION["cost_code"]);
//    $session->put("i_type_user", $_SESSION["i_type_user"]);
//    $session->put("dc_cost_acc_id", $_SESSION["dc_cost_acc_id"]);
//    $session->put("last_login", $_SESSION["last_login"]);       
//    $session->put("created", $_SESSION["created"]);       
     
    $re = array("reval" => 0, "success" => "Success", "msg" => "Login Success");
}

session_write_close();
if (!empty($_GET['portal_entry']) && $re['reval'] === 0) { header('Location: /supplies/', true, 303); exit; }
echo json_encode($re);
exit;


