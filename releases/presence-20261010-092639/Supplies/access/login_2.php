<?PHP 
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: access");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");


if($_SERVER["REQUEST_METHOD"] != "POST"){
 die('Page Not Found!');
}else{
    $data = json_decode(file_get_contents("php://input"));
 
    $_POST["userf"] = $data->userf;
    $_POST["passwordf"] = $data->passwordf;
} 
require_once("../conf/config.php");
require_once("../lib/database/DatabaseServer.php");
if (isset($_POST["userf"]) && isset($_POST["passwordf"])) {
    $db = new DatabaseServer();
    // เพิ่ม ระดับ แผนกและสายงาน
    //dc_department_type_id department_type_name dc_department_id department_name

    $sql = "select a.dc_user_id, a.c_password, a.dc_cost_id, b.dc_emp_id, a.c_full_name
                , IIF(c.sp_emp_id is null, 0, IIF( a.dc_user_id = 1 , 2, 1)) as i_set_type_emp
                --, isnull(i_type_emp,0) as i_type_emp
                , isnull((select top 1 c_name from NMU_DATACENTER.dbo.dc_cost where dc_cost_id=a.dc_cost_id),'ไม่พบ') as cost_name
                , isnull((select top 1 c_code from NMU_DATACENTER.dbo.dc_cost where dc_cost_id=a.dc_cost_id),'ไม่พบ') as cost_code
                ,(select top 1 dc_area_id from NMU_DATACENTER.dbo.dc_cost where dc_cost_id=a.dc_cost_id) as dc_area_id
                , a.i_type_user
               -- , a.super_user
                , isnull((select top 1  bb.dc_cost_acc_id from NMU_DATACENTER.dbo.dc_cost bb where bb.dc_cost_id=a.dc_cost_id),0) as dc_cost_acc_id
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
                , isnull((select top 1 dc_user_id from " . DB_CENTER . "dc_user where dc_emp_id = bb.dc_emp_id),0) as dc_center_user
            from dbo.dc_user a
                left join " . DB_CENTER . "dc_emp bb on bb.c_email = a.c_user_name
                left join dbo.dc_emp b on b.dc_emp_id = a.dc_emp_id
                left join dbo.sp_emp c on c.dc_emp_id = b.dc_emp_id
                left join dbo.sp_department d on d.dc_department_id = c.dc_department_id
                left join dbo.sp_department_type e on e.dc_department_type_id = d.dc_department_type_id
            where a.i_enable = ? and a.i_delete = ? and a.c_user_name = ? and a.c_password = ?";

    $stmt = $db->QueryParam($sql, array(STATUS_ENABLE, DELETE_FALSE, $_POST["userf"], md5($_POST["passwordf"])));
    
    $re = array("reval" => 1, "success" => "Error", "msg" => "Login False");
    while ($data = $db->Fetch($stmt)) {
        $instance = SuppliesLogin::begin($data, ['menu'=>'sp/dashBard.php']);
        SuppliesLogin::activate($data, $instance, ['menu'=>'sp/dashBard.php']);
        $_SESSION["created"]  = time();;

//Java Session
//        require_once("../java/Java.inc");
//        $session = java_session();
//        $session->put("DOMAIN_LOGIN", "PROCURE");
//        $session->put("DOMAIN", DOMAIN['en']);
//        $session->put("DOMAIN_NAME", DOMAIN['th']);
//        $session->put("user_id", intval($data["dc_user_id"])); 
//        $session->put("menu", "sp/dashBard.php");  
//        $session->put("user_name", $data["c_full_name"]);
//        $session->put("dc_emp_id", intval($data["dc_emp_id"])); 
//        $session->put("sp_emp_id", intval($data["sp_emp_id"]));
//        $session->put("dc_center_user", intval($data["dc_center_user"]));
////        $session->put("i_type_emp", $data["i_type_emp"]); // 1 ฝ่ายงานซื้อจ้าง , 2 admin , 0 หน่วยงานขอซื้อจ้าง , 3 ผู้บริหารเงิน , 4 ผู้บริหาร
//        $session->put("dc_department_id", intval($data["dc_department_id"]));
//        $session->put("dc_department_type_id", intval($data["dc_department_type_id"]));
//        $session->put("i_seq", $data["i_seq"]);
//        $session->put("i_level", $data["i_level"]); 
//        $session->put("c_sp_emp", $data["c_sp_emp"]);
//        $session->put("c_department_type", $data["c_department_type"]);
//        $session->put("c_department", $data["c_department"]);
//        $session->put("c_position", $data["c_position"]); 
//        $session->put("dc_cost_id", $data["dc_cost_id"]);
//        $session->put("cost_name", $data["cost_name"]);
//        $session->put("cost_code", $data["cost_code"]); 
//        $session->put("i_type_user", $data["i_type_user"]);
//        $session->put("super_user", $data["super_user"]);
//        $session->put("dc_cost_acc_id", $data["dc_cost_acc_id"]);
//        $session->put("last_login", date("Y-m-d H:i:s"));
//        $session->put("created", time());
 
        $re = array("reval" => 0, "success" => "Success", "msg" => "Login Success");
    }
     
}

echo json_encode($re);
exit;
/*
user domain session 
   
 * 
 *    
 *  */
