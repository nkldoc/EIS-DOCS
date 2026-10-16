<?php
include("conf/config.php");
require_once __DIR__ . '/conf/messenger/AdminIdentity.php';
if (NMUEISAdminIdentity::needsName()) { header('Location: /NMU_EIS/access/admin-identity.php'); exit; }
include("lib/database/DatabaseServer.php");

$db = new DatabaseServer();
$annouce = array();
$sql = "SET NOCOUNT ON 
    select c_code, c_text from " . DB_CENTER . "dc_annouce";
$stmt = $db->QueryParam($sql, array());
while ($row = $db->Fetch($stmt)) {
    $annouce[$row["c_code"]] = $row["c_text"];
}
$annouce = json_encode($annouce);

$socket_status = 0;
$socket_server = "https://" . SERVER_NAME . ":4000/socket.io/socket.io.js";
if (SERVER_NAME != "" && SERVER_NAME != 'localhost') {
    $headers = @get_headers($socket_server, false, stream_context_create(['http'=>['timeout'=>1,'follow_location'=>0]]));
    if ($headers && strpos($headers[0], '200') !== false) {
        $socket_status = 1;
    } else {
        $socket_status = 0;
    }
}
// $socket_status = 0;
ob_start();     // Turn on output buffering
system('ipconfig/all');  // Execute external program to display output
$mycom = ob_get_contents(); // Capture the output into a variable
ob_clean();     // Clean (erase) the output buffer

$findme = "Physical";
$pmac = strpos($mycom, $findme); // Find the position of Physical text
$mac = substr($mycom, ($pmac + 36), 17); // Get Physical Address

if (!isset($_SESSION['user_id'])) {
    echo "<script>window.location.href =\"access/signin.php\"</script>";
    exit;
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title><?php echo COMPANY_NAME; ?></title>
    <link rel="shortcut icon" href="images/favicon.ico" type="image/x-icon">
    <link rel="icon" href="images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="css/icon_all.css?dc=<?= __VPRODUCT_ ?>" />
    <!-- System ERP :: Src js  -->
    <link href="./js/ext-3.4.0/resources/css/ext-all.css" rel="stylesheet" type="text/css" />
    <script type="text/javascript" src="./js/jquery.js"></script>
    <script type="text/javascript" src="./js/ext-3.4.0/adapter/jquery/ext-jquery-adapter.js"></script>
    <script type="text/javascript" src="./js/ext-3.4.0/ext-all.js"></script>
    <script type="text/javascript" src="./js/examples/examples.js"></script>
    <script type="text/javascript" src="./js/config_function.js?dc=<?= __VPRODUCT_ ?>"></script>
    <!-- Theme includes -->
    <!-- <link rel="stylesheet" type="text/css" title="gray" href="./js/ext-3.4.0/resources/css/xtheme-gray.css" /> -->
    <link rel="stylesheet" type="text/css" title="gray" href="./js/ext-3.4.0/resources/css/xtheme-gray.css" />
    <!-- System ERP :: -->
    <script type="text/javascript">
        Ext.getInfo = {
            sysUserId: <?php echo $_SESSION["user_id"]; ?>,
            user_name: '<?php echo $_SESSION["user_name"]; ?>',
            i_type_user: <?php echo $_SESSION["i_type_user"]; ?>,
            cost_name: '<?php echo @$_SESSION['cost_name']; ?>',
            macAdress: '<?php echo $mac; ?>',
            serverName: '<?= SERVER_NAME ?>'
        };
        Ext.HostServer = {
            ost_host: '<?= OST_HOST ?>',
            nmu_permission_host: '<?= NMU_PERMISSION_HOST ?>',
            nmu_eis_host: '<?= NMU_EIS_HOST ?>',
            nmu_host: '<?= NMU_HOST ?>',
            fm_nmu_host: '<?= FM_NMU_HOST ?>',
            nmu_api_host: '<?= NMU_API_HOST ?>',
            nmu_supplies_host: '<?= NMU_SUPPLIES_HOST ?>'
        };
        Ext.Annouce = <?= $annouce; ?>;
        Ext.date_now = '<?= date("Y-m-d") ?>';
        Ext.socket_status = <?= $socket_status ?>;
        Ext.showLoadingMask = function(loadingMessage) {
            if (Ext.isEmpty(loadingMessage))
                loadingMessage = 'Loading... Please wait';
            //Use the mask function on the Ext.getBody() element to mask the body element during Ajax calls
            Ext.Ajax.on('beforerequest', function() {
                Ext.getBody().mask(loadingMessage, 'loading');
            }, Ext.getBody());
            Ext.Ajax.on('requestcomplete', Ext.getBody().unmask, Ext.getBody());
            Ext.Ajax.on('requestexception', Ext.getBody().unmask, Ext.getBody());
        };
    </script>
    <script type="text/javascript" src="./js/tap_active_index.js?dc=<?= __VPRODUCT_ ?>"></script>
    <script type="text/javascript" src="./js/show.js?_dc=<?php echo rand(0, 100000); ?>"></script>
    <script>window.EISPresenceConfig = {tokenUrl: './access/presence_token.php'};</script>
    <script src="./js/presence-client.js?_dc=<?= __VPRODUCT_ ?>"></script>
    <?php
    if ($socket_status == 1) {
        $tmpNotifyPath = "../NMU/tmp/tmpNotify/tmpNotify_" . $_SESSION["user_id"] . ".json";
        if (!file_exists($tmpNotifyPath)) file_put_contents($tmpNotifyPath, json_encode(array()));

        echo "<script type='text/javascript' src='" . $socket_server . "'></script>";
        echo "<script type='text/javascript' src='./js/notification-socket.js?_dc=" . rand(0, 100000) . "'></script>";
        // echo "<script type='text/javascript' src='./js/webSocket.js?_dc=" . rand(0, 100000) . "'></script>";
    }
    ?>
    <!--<script type="text/javascript" src="./js/alertSystems.js?_dc=<?php echo rand(0, 100000); ?>"></script>-->

    <style type="text/css">
        @font-face {
            font-family: "Krub";
            src: url("static/fonts/Krub-Regular.ttf");
        }

        .x-panel-noborder .x-panel-tbar-noborder .x-toolbar {
            height: 22px;
        }

        .system_msg {
            color: #BDBDBD;
            font-style: italic;
        }

        .user_name {
            font-weight: bold;
        }

        .user_message {
            color: #88B6E0;
        }

        #headerx {
            background: url('images/bgHeader.jpg?dc_=<?= __VPRODUCT_ ?>');
        }

        div#header {
            background: url('images/logo-nmu.jpg?dc_=<?= __VPRODUCT_ ?>') no-repeat;
            height: 34px;
            padding: 25px 0 11px 0;
            margin: 0 0px;
        }

        .clickable-box {
            position: absolute;
            top: 0px;
            left: 0px;
            width: 300px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        .x-menu-item-text {
            color: #000;
        }

        .header,
        a:link {
            text-decoration: none;
            color: #eee;
        }

        .header,
        a:visited {
            text-decoration: none;
        }

        .header,
        a:hover {
            text-decoration: none;
        }

        .header,
        a:active {
            text-decoration: none;
        }

        #logout {
            width: 260px;
            font-size: 13px;
        }

        #info-user {
            margin: -12px 0px 0px 0px;
            font-weight: bold;
            color: #eee;
            font-size: 13px;
            display: inline-block;
            width: 2800px;
            white-space: nowrap;
            overflow: hidden !important;
            text-overflow: ellipsis;
        }

        #info-changepass {
            cursor: pointer;
        }

        .info {
            float: left;
            margin: 0px 0px 0px 0px;
            color: #ccc;
        }

        .info .info-close {
            margin-left: 15px;
            padding: 0px 2px 3px 5px;
            background: url(images/close.png);
            background-repeat: no-repeat;
            background-position: 1px 2px;
        }

        #close {
            margin-left: -15px;
        }

        .x-panel-header {
            text-align: left;
            font-size: 13px;
        }

        #footer div.x-panel-header {
            text-align: center;
            font-size: 13px;
        }

        #toast {
            font-family: 'Krub';
            font-weight: bold;
            position: fixed;
            bottom: 53px;
            width: 165px;
            left: 230px;
            transform: translateX(-100%);
            background-color: #E7F3FF;
            color: #000000;
            padding: 10px 20px;
            border-radius: 5px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 13px;
            border-width: 2px;
            border-style: solid;
            border-color: #6499D3;
        }
        /* 
            DEV-ICON
        */
        <?php
        $host = $_SERVER['HTTP_HOST'];
        if (strpos($host, 'dev') !== false) {
            $env = 'DEV';
            $color = '#a3f3a9';
        } else if (strpos($host, 'uat') !== false) {
            $env = 'UAT';
            $color = '#ffb6c1';
        }
        if (isset($env)) {
            echo '
                div#header::after {
                    content: "DEV";
                    position: absolute;
                    left: 280px;
                    /* ปรับตำแหน่งตามต้องการ */
                    top: 5px;
                    font-weight: bold;
                    font-size: 45px;
                    color: #a3f3a9;
                    text-shadow: 0 0 3px white;
                    /* เพิ่มความอ่านง่าย */
                    font-family: Arial, sans-serif; 
                    pointer-events: none;
                    /* ป้องกันไม่ให้บังคลิก */
                }
            ';
        }
        ?>
    </style>
    <div id="toast"></div>
</head>

<body>
<?php require_once __DIR__ . '/conf/messenger/Presence.php'; echo NMUEISMessenger::script(); ?>
</body>

</html>
