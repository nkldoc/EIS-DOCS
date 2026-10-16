<?php

if (!defined("DB_SERVER")) {
    $configFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "conf" . DIRECTORY_SEPARATOR . "config.php";
    if (is_file($configFile)) {
        require_once $configFile;
    }
}

class DatabaseServer {

    public $conn;
    public $json_period = '';

    public function __Construct($DB_HOST = "", $DB_USER = "", $DB_PASS = "", $DB_NAME = "", $DB_CHARSET = "") {
        $DB_HOST = ($DB_HOST == "") ? DB_SERVER : $DB_HOST;
        $DB_USER = ($DB_USER == "") ? DB_USER : $DB_USER;
        $DB_PASS = ($DB_PASS == "") ? DB_PASS : $DB_PASS;
        $DB_NAME = ($DB_NAME == "") ? DB_NAME : $DB_NAME;
        $DB_CHARSET = ($DB_CHARSET == "") ? DB_CHARSET : $DB_CHARSET;

        $conInfo = array("Database" => $DB_NAME, "UID" => $DB_USER, "PWD" => $DB_PASS, "CharacterSet" => $DB_CHARSET);

        $requestHost = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
        if (in_array($requestHost, ['dev-eis.nmu.ac.th', 'localhost', '127.0.0.1'], true)
            && in_array(strtolower($DB_HOST), ['.', 'localhost', '127.0.0.1'], true)) {
            // Explicit local driver/TLS settings avoid IIS selecting the legacy driver.
            $conInfo['Driver'] = 'ODBC Driver 18 for SQL Server';
            $conInfo['Encrypt'] = true;
            $conInfo['TrustServerCertificate'] = true;
        }

        $this->conn = @sqlsrv_connect($DB_HOST, $conInfo);

        if ($this->conn) {
            return true;
        } else {
            echo "Connection could not be established.<br />";
            die(print_r(sqlsrv_errors(), true));
        }
    }

    public function sanitizeFileName($filename) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');
        return $name . '.' . $ext;
    }

    public function debugSql($sql, $params) {
        foreach ($params as $param) {
            $escaped = "'" . str_replace("'", "''", $param) . "'"; // Escape single quotes
            $sql = preg_replace('/\\?/', $escaped, $sql, 1);
        }
        return $sql;
    }

    public function Query($sql) {

        $stmt = @sqlsrv_query($this->conn, $sql);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        return $stmt;
    }

    public function execute(?array $params = null): bool {
        $stmt = @sqlsrv_query($this->conn, $sql, $arrParam);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        return $stmt;
    }

    public function QueryParam($sql, $arrParam) {
        //echo "$sql<hr>"; print_r($arrParam);
        $stmt = @sqlsrv_query($this->conn, $sql, $arrParam);
        if ($stmt === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        return $stmt;
    }

    protected function RowAffect($stmt) {
        return @sqlsrv_rows_affected($stmt);
    }

    public function NumRows($stmt) {
        return @sqlsrv_num_rows($stmt);
    }

    public function NumFields($stmt) {
        return @sqlsrv_num_fields($stmt);
    }

    public function Fetch($stmt, $fetchType = SQLSRV_FETCH_ASSOC) {
        return @sqlsrv_fetch_array($stmt, $fetchType);
    }

    public function FreeSTMT($stmt) {
        @sqlsrv_free_stmt($stmt);
    }

    public function NextResult($stmt) {
        $next_result = @sqlsrv_next_result($stmt);

        if ($next_result === false) {
            die(print_r(sqlsrv_errors(), true));
        }
        return $next_result;
    }

    public function BeginTran() {
        if (@sqlsrv_begin_transaction($this->conn) === false) {
            die(print_r(sqlsrv_errors(), true));
        }
    }

    public function CommitTran() {
        return @sqlsrv_commit($this->conn);
    }

    public function RollBackTran() {
        return sqlsrv_rollback($this->conn);
    }

    public function GetDataBySQL($sql, $arrParam) {

        $stmt = $this->QueryParam($sql, $arrParam);
        $fetch = $this->Fetch($stmt, SQLSRV_FETCH_BOTH);
        if ($this->NumFields($stmt) == 1)
            list($data) = $fetch;
        else
            $data = $fetch;

        $this->FreeSTMT($stmt);
        return $data;
    }

    public function json_clean_decode($json, $assoc = false, $depth = 512, $options = 0) {

        // search and remove comments like /* */ and //
        $json = preg_replace("#(/\*([^*]|[\r\n]|(\*+([^*/]|[\r\n])))*\*+/)|([\s\t](//).*)#", '', $json);

        if (version_compare(phpversion(), '5.4.0', '>=')) {
            $json = json_decode($json, $assoc, $depth, $options);
        } elseif (version_compare(phpversion(), '5.3.0', '>=')) {
            $json = json_decode($json, $assoc, $depth);
        } else {
            $json = json_decode($json, $assoc);
        }

        return $json;
    }

    //end func

    public function sendAPI($arr, $url = 'http://staging-api.sellsuki.com/dpx/warehousetxcheck', $meth = 'POST') {
        $data_json = $arr;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, getcwd() . "/dpx.crt");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);
        if ($meth == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
        } else if ($meth == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        } else if ($meth == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        }

        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    //NEW BY EAK
    public function getData($url) {
        $po_dtl_list = file_get_contents($url);
        $po_dtl_list = json_decode($po_dtl_list, true);
        return $po_dtl_list;
    }

    public function getList($list = array(), $val = 0, $id = 'id', $c_name = 'c_name') {
        foreach ($list as $ptArr) {
            if ($ptArr[$id] == $val) {
                $getPTgetHDR = $ptArr[$c_name];
            }
        }
        return $getPTgetHDR;
    }

    public function setData($url, $val = 0, $id = 'id', $c_name = 'c_name') {
        if ($val > 0)
            return $this->getList($this->getData($url)['data'], $val);
        else
            return $this->getData($url);
    }

    public function formatLog() {
        $time = date("M j G:i:s Y");
        $ip = getenv('REMOTE_ADDR');
        $userAgent = getenv('HTTP_USER_AGENT');
        $referrer = getenv('HTTP_REFERER');
        $query = getenv('QUERY_STRING');
        //COMBINE VARS INTO OUR LOG ENTRY
        return $msg = "IP: " . $ip . " TIME: " . $time . " REFERRER: " . $referrer . " SEARCHSTRING: " . $query . " USERAGENT: " . $userAgent;
    }

    public function logs(
            $menu,
            $mode,
            $id = null /* field name of primary key on menu */,
            $value = null /* id row on acton */,
            $sql = null /* sql statement */,
            $parram = array() /* parram sql */,
            $ses = array() /* sesson */,
            $log_filename = "../../../_logs" /* curren http://localhost/nmu/po/dc/controller */
    ) {
        if (is_array($ses)) {
            $user = array($ses['user_id'] => $ses["user_name"]);
        } // End if

        $paratxt = json_encode($parram);
        //remove space & newline
        $sql3 = str_replace(
                array("\n", "\r"),
                "",
                str_replace(
                        array("  ", "   ", "        ", "        "),
                        " ",
                        $sql
                )
        );
        //prepraring array return
        $arr = array(
            "d_action_date" => date('Y-m-d H:i:s'),
            "menu" => "{$menu}",
            "user_info" => $user,
            "mode" => "{$mode}",
            "user_id" => "{$ses['user_id']}",
            "id" => $id,
            "value" => $value,
            "sql" => $sql3,
            "parram" => $paratxt
        );
        /* ใช้แยกโฟลเดอร์ */
        $logym = ("/" . date('Y-m') . "/");

        $log_filename = $log_filename . $logym;

        if (!file_exists($log_filename)) {
            // create directory/folder uploads.
            mkdir($log_filename, 0777, true);
        }
        $log_file_data = $log_filename . date('Y-m-d') . '-' . $menu . '-' . $ses['user_id'] . '.log';

        if (file_put_contents(
                        $log_file_data,
                        ("," . json_encode($arr) . "\n"),
                        FILE_APPEND
                )) {
            $re = "log success";
            $success = "Success";
            $reval = 0;
        } else {
            $re = "log failed";
            $success = "Success";
            $reval = 1;
        }
        return json_encode(array("reval" => $reval, "success" => $success, "msg" => $re));
    }

    //TODO create genLogFile();
//    eakibanez api authen

    public function dbConnection() {

        try {
            $conn = new PDO('mysql:host=' . $this->db_host . ';dbname=' . $this->db_name, $this->db_username, $this->db_password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $conn;
        } catch (PDOException $e) {
            echo "Connection error " . $e->getMessage();
            exit;
        }
    }

    public function getHeaderCookieErp($endpoint = 'https://erpdev.vajira.ac.th') {
        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $responseHeaders = '';
        // Set a callback function to capture the headers
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$responseHeaders) {
            $responseHeaders .= $data;
            return strlen($data);
        });
        // Execute the request
        curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = substr($responseHeaders, 0, $headerSize);
        preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', $headers, $cookies);
        $cookie = $cookies[1][0] ?? null;
        // Output the cookie
        return $cookie;
    }

    public function lineNotif($msg) {
        $url = 'https://notify-api.line.me/api/notify';
        $token = 'D6FRoKcCbis7buuMmlDNHBJYXgOWamF8pbtHo6AUzS1'; // SEN TO Dev.ICE

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Bearer ' . $token
        ];
        $fields = 'message=' . $msg;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($result, TRUE);
        return $result;
    }

    public function jsonLog($arrJson = array()) {
        $datetime = date('Y-m-d H:i:s');
        $posts = array("postdt" => $datetime, "datas" => $arrJson);
        $json = json_encode($posts);
        file_put_contents('logs/' . date('Y-m') . '-log.json', ($json . ","), FILE_APPEND);
        //print_r($posts["datas"]["datatype"]);
        //alert(lineGroup);
        $success = $posts["datas"]["success"] ?? null;
        if ($success === 0) {
            $datatype = $posts["datas"]["message"] ?? null;
        } else {
            $datatype = $posts["datas"]["datatype"] ?? null;
        }
        lineNotif("logtime api :::" . $datetime . ":::" . $datatype);
    }

    public function jsonSendLog($arrJson = array()) {
        $datetime = date('Y-m-d H:i:s');
        $posts = array("postdt" => $datetime, "datas" => $arrJson);
        $json = json_encode($posts);
        file_put_contents('logs/' . date('Y-m') . '-log.json', ($json . ","), FILE_APPEND);
        //print_r($posts["datas"]["datatype"]);
        //alert(lineGroup);
        $success = $posts["datas"]["success"] ?? null;
        if ($success === 0) {
            $datatype = $posts["datas"]["message"] ?? null;
        } else {
            $datatype = $posts["datas"]["datatype"] ?? null;
        }
    }

    public function postPeriod($step, $id, $dtl_id, $db, $date, $data = [], $data2 = []) {


        //             echo ($data['sp_tor_contract_id']);
        ////         print_r($data2);
        //         exit();

        $url = URL_API_JULA . '/mnTorController';

        // Payload for the POST request


        if ($step == 1) {
            $payload = [
                "mode" => "UP_SP_TOR_HDR_PERIOD",
                "sp_tor_hdr_period_id" => null,
                "tor_id" => 2361,
                "sp_tor_contract_id" => $data['sp_tor_contract_id'], //$data["sp_tor_contract_id"],
                "i_is_join_ventureID" => null,
                "c_name" => $data2["c_name"],
                "i_qty" => 1,
                "dc_unit_type_id" => 24,
                "i_is_po" => 0,
                "i_is_purchase" => null,
                "ap_po_hdr_id" => null,
                "i_period" => $data["i_period"],
                "d_doc_date" => "29-11-2567",
                "i_day_use_l" => 1,
                "d_period_date" => "31-01-2568",
                "i_day" => 63,
                "i_alert" => 1,
                "f_total_amt" => $data["f_total_amt"],
                "dc_expense_budget_type_id" => 2,
                "dc_bg_budget_type_id" => "เงินรายได้คณะแพทย์ฯ-โรงพยาบาล",
                "dc_cost2_id" => 51,
                "dc_creditor_id" => 9726,
                "i_is_join_venture_per" => null,
                "c_code" => "พวช.ซ.005006/2568",
                "dc_creditor_id2" => null,
                "txtdc_creditor_id2ID" => "เลือกผู้เสนอราคา",
                "already_withdrawn" => 1,
                "i_pr_type1" => 1,
                "copy_contract_dtl" => "save",
                "sp_expense_id" => 131,
                "po_expense_id" => "030308001 : ค่าวัสดุเครื่องบริโภค",
                "i_hire_type_l" => 1,
                "i_product_type" => 1,
                "period_i_is_inv" => null,
                "c_name_dtl" => $data2["c_name"],
                "i_qty" => 1,
                "f_net_unit_price" => $data["f_total_amt"],
                "sp_unit_type_id" => 24,
                "dc_unit_type_id" => "รายการ"
            ];
            $jsonPayload = json_encode($payload);
        } else if ($step == 2) {


            $payload2 = [
                "mode" => "UP_SP_CHECK_PERIOD_DTL",
                "data_dtl" => [
                    [
                        "i_qty" => "1",
                        "sp_tor_hdr_period_id" => $id,
                        "sp_tor_dtl_period_id" => $dtl_id,
                        "dc_creditor_id" => "9726",
                        "c_name" => $data2["c_name"],
                        "dc_unit_type_id" => 24,
                        "c_unit" => "รายการ",
                        "dc_bg_budget_type_id" => "2",
                        "po_expense_id" => "131",
                        "i_hire_type" => 1,
                        "i_product_type" => 1,
                        "i_is_inv" => null,
                        "f_net_unit_price" => $data["f_total_amt"],
                        "f_net_total_price" => $data["f_total_amt"]
                    ]
                ],
                "sp_tor_hdr_period_id" => $id,
                "sp_mn_contract_hdr_id" => 1868, //get ผ่านรายการก่อนถึงจะได้มาซึ่ง
                "sp_tor_contract_id" => $data['sp_tor_contract_id'], //$data["sp_tor_contract_id"],
                "i_is_po" => 0,
                "f_vat_amt" => "1,226,716.64",
                "f_total_add_vat_amt" => "17,524,523.36",
                "f_rate_vat" => 7.00,
                "c_doc_ref" => $data['c_doc_ref'],
                "d_arrive_date" => "2025-01-08",
                "d_doc_arrive_dt" => "2025-01-15",
                "c_comment" => $data["c_comment"],
                "dc_creditor_per_id" => 9726
            ];
            $jsonPayload = json_encode($payload2);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json',]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        // Disable SSL verification (temporary)
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'cURL error: ' . curl_error($ch);
        } else {
            // echo 'Response: ' . $response;
        }
        curl_close($ch);
        return $response;
    }

    public function postMsg($params = [], $url = "https://eis.vajira.ac.th:8443/supplies/websocket/event") {
        // Append the query parameters to the URL
        $fullUrl = $url . '?' . http_build_query($params);
        // Initialize cURL session
        $ch = curl_init();
        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $fullUrl); // Use the full URL with query parameters
        curl_setopt($ch, CURLOPT_POST, true);   // Specify this is a POST request
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification if needed
        // Execute the request and get the response
        $response = curl_exec($ch);
        // Check for errors
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo 'Response:' . $response;
        }
        // Close the cURL session
        curl_close($ch);
    }

    public function postIr($period, $db, $date, $data = [], $data2 = []) {
        $arr = json_decode($period);
        postPeriod(2, $arr->id, $arr->dtl_id, $db, $date, $data, $data2);
        $msg = 'ระบบ ERP สัญญา ' . $data["c_contract_code"] . ' จำนวนเงิน ' . number_format($data["f_total_amt"], 2) . ' ID ' . $arr->id . ' ได้ส่งรายการรับของจากคลังมาเพื่อ ดำเนินการออกเลข IR พนักงานผู้รับผิดดำเนินการต่อไป';
        $this->postMsg(['msgType' => '4', 'msg' => $msg]);
    }

    public function postToSuppliesApi($db, $date, $arrJson = []) {
        if (!is_array($arrJson)) {
            die('ข้อมูลผิดพลาด กรุณาตรวจเช็คอีกครั้ง');
        }
        $data = array();
        // เตรียมข้อมูล
        // b
        // period_hdr_id
        $data["sp_tor_hdr_period_id"] = $arrJson["data"][0]["erp_hdr_period_id"];
        $data["sp_tor_contract_id"] = $arrJson["data"][0]["sp_tor_contract_id"];
        $data["c_doc_ref"] = $arrJson["data"][0]["c_doc_ref"];
        $data["i_period"] = $arrJson["data"][0]["i_period"];
        $data["c_contract_code"] = $arrJson["data"][0]["c_contract_code"];
        $data["f_vat_amt"] = $arrJson["data"][0]["f_vat_amt"];
        $data["f_total_amt"] = $arrJson["data"][0]["f_total_amt"];
        $data["d_doc_date"] = $arrJson["data"][0]["d_pickup_date"];
        $data["c_comment"] = $arrJson["data"][0]["c_comment"];

        $dtl = $arrJson["data"][0]["tbl_dtl"][0];
        $data2 = array();
        // period_dtl_id
        $data2["erp_dtl_period_id"] = $dtl["erp_dtl_period_id"];
        $data2["c_name"] = $dtl["c_name"];
        $data2["i_qty"] = $dtl["i_qty"];
        $data2["dc_unit_id"] = $dtl["dc_unit_id"];
        $data2["dc_unit_name"] = $dtl["dc_unit_name"];
        $data2["f_net_unit_price"] = $dtl["f_net_unit_price"];
        $data2["dc_product_type_id"] = $dtl["dc_product_type_id"];
        $data2["dc_product_type_name"] = $dtl["dc_product_type_name"];

        // post period
        $this->json_period = $this->postPeriod(1, null, null, $db, $date, $data, $data2);   // inert period

        try {
            $this->postIr($this->json_period, $db, $date, $data, $data2); //เตรียมข้อมูลและ insert checking
        } catch (PDOException $e) {
            echo "Connection error " . $e->getMessage();
            exit;
        }
    }

//End IF Insert

    public function __Destruct() {
        @sqlsrv_close($this->conn);
    }
}
