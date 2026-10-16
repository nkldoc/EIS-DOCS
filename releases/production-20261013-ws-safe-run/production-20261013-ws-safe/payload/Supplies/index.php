<?php
include("conf/config.php");
if (isset($_SESSION['supplies_login_pending']) && $_SESSION['supplies_login_pending']['expires'] >= time()) { header('Location: access/confirm-login.php'); exit; }
ob_start();     // Turn on output buffering
system('ipconfig/all');  // Execute external program to display output
$mycom = ob_get_contents(); // Capture the output into a variable
ob_clean();     // Clean (erase) the output buffer
//$pathNodeJs = "http://" . SERVER_NAME . ":3000";
$findme = "Physical";
$pmac = strpos($mycom, $findme); // Find the position of Physical text
$mac = substr($mycom, ($pmac + 36), 17); // Get Physical Address

$ss_domain = $_SESSION["DOMAIN"] ?? null;
$ss_emp_id = $_SESSION["sp_emp_id"] ?? null;
$ss_cost_id = $_SESSION["dc_cost_id"] ?? null;
$ss_username = $_SESSION["user_name"] ?? null;
$ss_user_id = $_SESSION["user_id"] ?? null;

if (!$ss_user_id) {
    echo "<script>window.location.href ='./access/logout.php'</script>";
    exit;
}

// Permission Logic
include "bi/logic/permission_logic.php";
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <title><?php echo COMPANY_NAME; ?></title>
        <!--        <link rel="shortcut icon" href="images/favicon.ico" type="image/x-icon">-->
        <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">-->
        <!--            <link rel="icon" href="images/favicon.ico" type="image/x-icon">-->
        <link rel="stylesheet" type="text/css" href="./css/icon_all.css?dc=<?= __VPRODUCT_ ?>" />
        <!-- System ERP :: Src js  -->
        <link href="./js/ext-3.4.0/resources/css/ext-all.css" rel="stylesheet" type="text/css" />
        <link href="./css/icon_all.css" rel="stylesheet" type="text/css" />
        <script type="text/javascript" src="./js/jquery.js"></script>
        <script type="text/javascript" src="./js/ext-3.4.0/adapter/jquery/ext-jquery-adapter.js"></script>
        <script type="text/javascript" src="./js/ext-3.4.0/ext-all.js"></script>
        <link rel="stylesheet" type="text/css" title="blue" href="./js/ext-3.4.0/resources/css/xtheme-blue.css?_dc=V1116122023" />

        <script type="text/javascript">
            Ext.onReady(function () {
                Ext.QuickTips.init();
                Ext.showLoadingMask = function (loadingMessage) {
                    if (Ext.isEmpty(loadingMessage))
                        loadingMessage = 'Loading... Please wait';
                    //Use the mask function on the Ext.getBody() element to mask the body element during Ajax calls
                    Ext.Ajax.on('beforerequest', function () {
                        Ext.getBody().mask(loadingMessage, 'loading');
                    }, Ext.getBody());
                    Ext.Ajax.on('requestcomplete', Ext.getBody().unmask, Ext.getBody());
                    Ext.Ajax.on('requestexception', Ext.getBody().unmask, Ext.getBody());
                };
                Ext.ipServer = "<?php
echo htmlspecialchars(
        (string) ($_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '127.0.0.1'),
        ENT_QUOTES,
        'UTF-8'
);
?>";
                Ext.NMU_PERMISSION = "<?php echo NMU_PERMISSION_HOST; ?>";
                window.USER_HAS_PERMISSION = <?php echo $permission ? 'true' : 'false'; ?>;


            });
        </script>
        <!-- System ERP :: -->
        <script type="text/javascript" src="./lib/right/GrantPermission.php?_dc=<?php echo __VPRODUCT_; ?>&f=<?php echo $_SERVER["PHP_SELF"]; ?>"></script>



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
                background: url('images/logo-procure.jpg?dc_=<?= __VPRODUCT_ ?>') top left no-repeat, url('images/line-procure.jpg?dc_=<?= __VPRODUCT_ ?>') repeat
            }

            div#header {
                height: 34px;
                padding: 25px 0 11px 0;
                margin: 0 0px;
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
                margin: -11px 0px 0px 0px;
                font: normal 13px/15px 'Mitr', sans-serif;
                color: #eee;
                display: inline-block;
                width: 2800px;
                white-space: nowrap;
                overflow: hidden !important;

            }

            #info-changepass {
                cursor: pointer;
            }

            .info {
                float: left;
                margin: 2px 0px 0px 0px;
                color: #ccc;
            }

            /*                    .info .info-close {
                                                                margin-left: 15px;
                                                                padding: 0px 2px 3px 5px;
                                                                background: url(images/close.png);
                                                                background-repeat: no-repeat;
                                                                background-position: 1px 2px;
                                                            }*/

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

            .divTable {
                display: table;
                width: auto;
                border-spacing: 0px;
            }

            .divRow {
                display: table-row;
                width: auto;
            }

            .divCell {
                float: left;
                /*fix for  buggy browsers*/
                display: table-column;

            }

            /* สไตล์กระดิ่ง + badge */
            .notif-wrap {
                display: flex;
                align-items: center;
                gap: 6px
            }

            .notif-bell svg {
                display: block
            }

            .notif-badge {
                min-width: 16px;
                height: 16px;
                line-height: 16px;
                padding: 0 4px;
                border-radius: 9999px;
                text-align: center;
                font-weight: bold;
                font-size: 11px;
                background: #0e7bf0;
                border: 2px solid #8b9db0;
                color: #fff;
                cursor: pointer;
                user-select: none
            }

            .notif-badge.zero {
                background: #9aa7b1;
                color: #eee;
                border-color: #b7c3cd
            }

            /*เพิ่ม การแจ้งเตือน*/
            .toast-wrap {
                position: fixed;
                right: 16px;
                bottom: 16px;
                z-index: 99999;
            }

            .toast-item {
                width: 320px;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, .18);
                overflow: hidden;
                margin-top: 10px;
                border: 1px solid #eee;
            }

            .toast-head {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 10px 12px;
                font-weight: bold;
                font-size: 13px;
                border-bottom: 1px solid #f0f0f0;
            }

            .toast-body {
                padding: 10px 12px;
                font-size: 13px;
                line-height: 1.45;
            }

            .toast-close {
                margin-left: auto;
                cursor: pointer;
                opacity: .7
            }

            .toast-item.toast-info .toast-head {
                background: #e8f1ff
            }

            .toast-item.toast-success .toast-head {
                background: #e8f9ef
            }

            .toast-item.toast-warning .toast-head {
                background: #fff7e6
            }

            .toast-item.toast-error .toast-head {
                background: #ffebee
            }

            .toast-item a {
                color: #0a58ca;
                text-decoration: none
            }

            .toast-item a:hover {
                text-decoration: underline
            }

            .toast-muted {
                opacity: .6;
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
        </style>
    </head>
    <script type="text/javascript" src="websocket.js?_dc=<?php echo rand(0, 100000); ?>"></script>
    <script type="text/javascript" src="js/Function.js?_dc=<?php echo rand(0, 100000); ?>"></script>
    <link rel="stylesheet" href="css/docs-notifications.css?v=<?php echo (string)filemtime(__DIR__ . '/css/docs-notifications.css'); ?>" />
    <script type="text/javascript" src="js/docs-notifications.js?v=<?php echo strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) === 'eis.nmu.ac.th' ? '20261005-production-fix1' : '20261002'; ?>"></script>
    <script type="text/javascript" src="js/show_nat.js?_dc=<?php echo rand(0, 100000); ?>"></script>
    <body>
        <?php require_once __DIR__ . '/conf/presence/Presence.php'; echo Presence::script('SUPPLIES'); ?>
    </body>

</html>
