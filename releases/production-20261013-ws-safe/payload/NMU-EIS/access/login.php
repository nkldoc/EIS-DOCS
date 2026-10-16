<?PHP
require_once("../conf/config.php");
require_once("../lib/database/DatabaseServer.php");

if (isset($_POST["userf"]) && isset($_POST["passwordf"])) {
	$db = new DatabaseServer();
	$DB_DC_NAME = DB_CENTER;
	$sql = "select a.dc_user_id, a.dc_cost_id, a.dc_emp_id, a.c_full_name
					, isnull((select top 1 ww.c_name from " . $DB_DC_NAME . "dc_cost ww where ww.dc_cost_id=a.dc_cost_id),'ไม่พบ') as cost_name
					, isnull((select top 1 gg.c_code from " . $DB_DC_NAME . "dc_cost gg where gg.dc_cost_id=a.dc_cost_id),'ไม่พบ') as cost_code
					,(select top 1 jj.dc_area_id from " . $DB_DC_NAME . "dc_cost jj where jj.dc_cost_id=a.dc_cost_id) as dc_area_id
					,a.i_type_user
					,isnull((select top 1  bb.dc_cost_acc_id from " . $DB_DC_NAME . "dc_cost bb where bb.dc_cost_id=a.dc_cost_id),0) as dc_cost_acc_id
					,isnull(b.dc_cost_id_default,0) as dc_cost_id_default
					,b.sql_cost_show
					,b.sql_cost_post
					,b.sql_process_show
					,b.sql_rep_br_show
			from " . $DB_DC_NAME . "dc_user a
				LEFT OUTER JOIN " . $DB_DC_NAME . "dc_user_right b ON a.dc_user_id=b.dc_user_id
			where a.i_enable = ? and a.i_delete = ? and a.c_user_name = ? and a.c_password = ?
				and b.i_enable = ? and b.i_delete = ?";


	$stmt = $db->QueryParam($sql, array(STATUS_ENABLE, DELETE_FALSE, $_POST["userf"], md5($_POST["passwordf"]), STATUS_ENABLE, DELETE_FALSE));

	$re = array("reval" => 1, "success" => "Error", "msg" => "Login False");
	while ($data = $db->Fetch($stmt)) {
		require_once __DIR__ . '/../conf/messenger/AdminIdentity.php';
        $verifiedAdminOperator = NMUEISAdminIdentity::forLogin($data);
		NMUEISMessenger::revoke('NMU-EIS', (string)($_SESSION['user_id'] ?? ''), (string)($_SESSION['last_login'] ?? ''));
		unset($_SESSION['messenger_login_nonce']);
		$_SESSION["user_id"]				= $data["dc_user_id"]; //id ผู้ใช้งาน
		$_SESSION["user_name"]				= $data["c_full_name"]; //ชื่อผู้ใช้งาน
		$_SESSION["dc_emp_id"]				= $data["dc_emp_id"]; //พนักงานผู้รับผิดชอบ
		$_SESSION["dc_cost_id"]				= $data["dc_cost_id"]; //id หน่วยงาน
		$_SESSION["cost_name"]				= $data["cost_name"]; //หน่วยงาน 
		$_SESSION["cost_code"]				= $data["cost_code"]; //รหัสหน่วยงาน 
		$_SESSION["dc_area_id"]				= $data["dc_area_id"]; //id หน่วยธุรกิจ
		$_SESSION["i_type_user"]			= $data["i_type_user"];
        $_SESSION["admin_operator"] = $verifiedAdminOperator; //ประเภทผู้ใช้งาน
		$_SESSION["dc_cost_acc_id"]			= $data["dc_cost_acc_id"]; //id ศูนย์ต้นทุนทางบัญชีของหน่วยงานที่ log in
		$_SESSION["last_login"]				= date("Y-m-d H:i:s");
		$_SESSION["dc_cost_id_default"]		= $data["dc_cost_id_default"];
		$_SESSION["ww_cost_show"]			= "'" . $data["sql_cost_show"] . "'";
		$_SESSION["ww_cost_post"]			= "'" . $data["sql_cost_post"] . "'";
		$_SESSION["ww_process_show"]        = "'" . $data["sql_process_show"] . "'";
		$_SESSION["ww_rep_br_show"]            = "'" . $data["sql_rep_br_show"] . "'";

		$re = array("reval" => 0, "success" => "Success", "msg" => "Login Success");

		// สร้าง ประวัติผู้ใช้งานทั้งหมด
		$typeUer = array("1" => "ผู้ปฎิบัติงาน", "2" => "ผู้ดูแลระบบ", "3" => "หน่วยงาน");
		$tmp = "../tmp/tmpUser.json";
		if ($objFopen = fopen($tmp, 'w')) {
			$sql = "
				SELECT
					a.dc_user_id
					,a.i_type_user
					,c.c_name AS title
					,b.c_name AS [name]
					,d.c_name AS costname
				FROM dbo.dc_user a
					INNER JOIN dbo.dc_emp b ON a.dc_emp_id = b.dc_emp_id
						AND b.i_enable = 1 AND b.i_delete = 2
					LEFT JOIN dbo.dc_title c ON b.dc_title_id = c.dc_title_id
						AND c.i_enable = 1 AND c.i_delete = 2
					LEFT JOIN " . $DB_DC_NAME . "dc_cost d ON b.dc_cost_id = d.dc_cost_id
						AND d.i_enable = 1 AND d.i_delete = 2
				WHERE a.i_enable = 1 AND a.i_delete = 2;";
			$stmt = $db->QueryParam($sql, array());
			while ($row = $db->Fetch($stmt)) {
				$temp = array(
					"id"					=> $row["dc_user_id"],
					"title"					=> $row["title"],
					"name"					=> $row["name"],
					"position"				=> $typeUer[$row["i_type_user"]],
					"costname"				=> $row["costname"]
				);
				$ss[] = $temp;
			}
			fwrite($objFopen, json_encode($ss));
		}
	}
}

echo json_encode($re);
exit;
