<?PHP
require_once("../conf/config.php");
require_once("../lib/database/DatabaseServer.php");
require_once("../lib/database/apiUtil.php");
$util = new apiUtil();
$db = new DatabaseServer();

$data_post = json_decode($util->Text_Decode($_POST["data"]), true);

if (isset($data_post["idf"]) && isset($data_post["passwordf"]) && strval($data_post['dateTimef']) == strval(date("Y-m-d H:i:s"))) {

	/*
	$sql = "SELECT dc_user_id, dc_cost_id, dc_emp_id, c_full_name
				, ISNULL((SELECT TOP 1 c_name FROM dc_cost WHERE dc_cost_id=dc_user.dc_cost_id),'ไม่พบ') AS cost_name
				, ISNULL((SELECT TOP 1 c_code FROM dc_cost WHERE dc_cost_id=dc_user.dc_cost_id),'ไม่พบ') AS cost_code
				,(SELECT TOP 1 dc_area_id FROM dc_cost WHERE dc_cost_id=dc_user.dc_cost_id) AS dc_area_id
				,i_type_user
				,ISNULL((SELECT TOP 1  bb.dc_cost_acc_id FROM dc_cost bb WHERE bb.dc_cost_id=dc_user.dc_cost_id),0) AS dc_cost_acc_id
			FROM dc_user
			WHERE i_enable = ? AND i_delete = ? AND dc_user_id = ? AND c_password = ?";
	*/
	$sql = "
		SELECT bb.dc_user_id as bid
			, a.dc_user_id
			, cc.dc_emp_id
			, ee.sp_emp_id
			,a.c_full_name
			,a.dc_cost_id
			,a.c_user_name
			,a.i_type_user 
			,(select top 1 c_name from ".DB_CENTER."dc_cost aa where aa.dc_cost_id = a.dc_cost_id) as cost_name
			,ISNULL(b.i_permit_eis ,0) AS i_permit_eis
			,ISNULL(b.i_permit_nmu ,0) AS i_permit_nmu
			,ISNULL(b.i_permit_fm ,0) AS i_permit_fm
			,ISNULL(b.i_permit_supplies ,0) AS i_permit_supplies
			,ISNULL(b.i_permit_procure ,0) AS i_permit_procure
		FROM ".DB_CENTER."dc_user a
			LEFT JOIN ".DB_CENTER."dc_user_permit b ON a.dc_user_id = b.dc_user_id
			LEFT JOIN ".DB_EIS_PROCURE."dc_user bb ON a.c_user_name = bb.c_user_name AND bb.i_delete = 2
			LEFT JOIN ".DB_EIS_PROCURE."dc_emp cc ON bb.dc_emp_id = cc.dc_emp_id
			LEFT JOIN ".DB_EIS_PROCURE."sp_emp ee ON ee.dc_emp_id = cc.dc_emp_id 
		WHERE a.i_enable = ? AND a.i_delete = ? AND a.dc_user_id = ? AND a.c_password = ?
	";

	$stmt = $db->QueryParam($sql, array(STATUS_ENABLE, DELETE_FALSE, $data_post["idf"], md5($data_post["passwordf"])));

	$re = array("reval" => 1, "success" => "Error", "msg" => "Login False");
	while ($data = $db->Fetch($stmt)) {
		/*
		require_once __DIR__ . '/../conf/messenger/Presence.php';
		NMUEISMessenger::revoke('NMU-EIS', (string)($_SESSION['user_id'] ?? ''), (string)($_SESSION['last_login'] ?? ''));
		unset($_SESSION['messenger_login_nonce']);
		$_SESSION["user_id"]		= $data["dc_user_id"]; //id ผู้ใช้งาน
		$_SESSION["user_name"]		= $data["c_full_name"]; //ชื่อผู้ใช้งาน
		$_SESSION["dc_emp_id"]		= $data["dc_emp_id"]; //พนักงานผู้รับผิดชอบ
		$_SESSION["dc_cost_id"]		= $data["dc_cost_id"]; //id หน่วยงาน
		$_SESSION["cost_name"]		= $data["cost_name"]; //หน่วยงาน 
		$_SESSION["cost_code"]		= $data["cost_code"]; //รหัสหน่วยงาน 
		$_SESSION["dc_area_id"]		= $data["dc_area_id"]; //id หน่วยธุรกิจ
		$_SESSION["i_type_user"]	= $data["i_type_user"]; //ประเภทผู้ใช้งาน
		$_SESSION["dc_cost_acc_id"]	= $data["dc_cost_acc_id"]; //id ศูนย์ต้นทุนทางบัญชีของหน่วยงานที่ log in
		$_SESSION["last_login"]		= date("Y-m-d H:i:s");
		*/
		$users[1] = $data["dc_user_id"] ?? null;
        $users[2] = $data["dc_user_id"] ?? null;
        $users[3] = $data["dc_user_id"] ?? null;
        $users[4] = $data["dc_user_id"] ?? null;
        $users[5] = $data["dc_user_id"] ?? null;

        //loginGetHeader(); 
        
        $temp = array(
            'c_full_name' => $data["c_full_name"],
            'dc_cost_id' => $data["dc_cost_id"],
            'cost_name' => $data["cost_name"],
            'dc_user_id' => $data["dc_user_id"],
            'dc_user_eis_id' => array(1, $data["dc_user_id"]),
            'dc_user_fm_id' => array(2, $data["dc_user_id"]),
            'dc_user_nmu_id' => array(3, $data["dc_user_id"]),
            'dc_user_supplies_id' => array(4, $data["dc_user_id"]),
            'dc_user_procure_id' => array(5, $data["bid"], $data["c_user_name"]), 
            'i_permit_eis' => $data["i_permit_eis"],
            'i_permit_nmu' => $data["i_permit_nmu"],
            'i_permit_fm' => $data["i_permit_fm"],
            'i_permit_supplies' => $data["i_permit_supplies"],
			'i_permit_procure' => $data["i_permit_procure"],

            'url' => URL_SYS,
            'sub_user_id' => $users,
            'i_type_user' => $data["i_type_user"]
        );
        $arr[] = $temp;
        $_SESSION = $temp;

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
				FROM ".DB_CENTER."dc_user a
					INNER JOIN ".DB_CENTER."dc_emp b ON a.dc_emp_id = b.dc_emp_id
						AND b.i_enable = 1 AND b.i_delete = 2
					LEFT JOIN dbo.dc_title c ON b.dc_title_id = c.dc_title_id
						AND c.i_enable = 1 AND c.i_delete = 2
					LEFT JOIN ".DB_CENTER."dc_cost d ON b.dc_cost_id = d.dc_cost_id
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
} else {
	$re = array("reval" => 1, "success" => "Error", "msg" => "Login Data False");
}

echo json_encode($re);
exit;
