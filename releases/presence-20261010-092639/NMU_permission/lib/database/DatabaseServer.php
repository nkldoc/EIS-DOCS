<?php

@include_once("../../conf/config.php");

class DatabaseServer {

    public $conn;

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

    public function Query($sql) {

        $stmt = @sqlsrv_query($this->conn, $sql);
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
    public function __Destruct() {
        sqlsrv_close($this->conn);
    }

}
