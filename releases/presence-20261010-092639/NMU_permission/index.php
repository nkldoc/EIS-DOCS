<?php
include("conf/config.php");
include("lib/database/DatabaseServer.php");

$db = new DatabaseServer();
$annouce = array();
$sql = "SET NOCOUNT ON 
    select c_code, c_text from " . DB_CENTER . "dc_annouce";
$stmt = $db->QueryParam($sql, array());
while ($row = $db->Fetch($stmt)) {
    $annouce[$row["c_code"]] = $row["c_text"];
}

if (!file_exists('tmp/sessions')) {
    mkdir("tmp/sessions", 0777, true);
}

if (OST_HOST == "http") {
    echo "<script>location.href = \"https://" . HTTPS_HOST_NAME . "/NMU_permission/entrance\"</script>";
    exit;
}

if (isset($_SESSION['i_type_user'])) {
    echo "<script>location.href = \"https://" . NMU_PERMISSION_HOST . "/entrance\"</script>";
    exit;
}


?>
<!DOCTYPE html>
<html>

<head>
    <title>EIS(Enterprise Information Systems)</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- System ERP :: Src js  -->
    <link type="text/css" href="./js/ext-3.4.0/resources/css/ext-all.css" rel="stylesheet" />
    <link rel="shortcut icon" href="images/favicon.ico" type="image/x-icon">

    <link rel="stylesheet" type="text/css" title="gray" href="./js/ext-3.4.0/resources/css/xtheme-gray.css" />
    <script type="text/javascript" src="./js/jquery.js"></script>
    <script type="text/javascript" src="./js/ext-3.4.0/adapter/jquery/ext-jquery-adapter.js"></script>
    <script type="text/javascript" src="./js/ext-3.4.0/ext-all.js"></script>
    <link href="./css/style.css?_dc=<?= __VPRODUCT_; ?>" rel="stylesheet" type="text/css" />
</head>

<body id="bodyID">
    <h2 style="background-repeat: repeat-xy;"><img src="imgs/bar.jpg" width="100%" /></h2><br><br>

    <div class="container" id="container" style="margin-top:20px;">
        <div class="form-container sign-in-container">
            <form name="frm" id="frmID">
                <h1>Sign In</h1> <br><br>

                <!-- <div class="group">
                    <input required="" id="userID" type="text" class="input">
                    <span class="highlight"></span>
                    <span class="bar"></span>
                    <label>Username</label>
                </div>
                <br>
                <div class="group">
                    <input required="" id="passID" type="password" class="input">
                    <span class="highlight"></span>
                    <span class="bar"></span>
                    <label>Password</label>
                </div> -->
                <div class="input-group">
                    <div class="label2">Username</div>
                    <input required="" id="userID" type="text" class="input2">
                    <div></div>
                </div>
                <div class="input-group">
                    <div class="label2">Password</div>
                    <input required="" id="passID" type="password" class="input2">
                    <div></div>
                </div>
                <br>
                <div class="buttonlog" id="buSubmitID">Log In</div>

            </form>
        </div>
        <div class="overlay-container">
            <div class="overlay">
                <div class="overlay-panel overlay-right" id="txtBoard">
                    <?= $annouce["LOGIN"] != "" ? $annouce["LOGIN"] : "<h5 style='text-align: center;'><br><br><br><br><br>ยินดีต้อนรับ<br>กรุณาลงชื่อเข้าใช้เพื่อเข้าสู่ระบบ</h5>" ?>
                    <div class="social-container" id="myDIV"></div>
                </div>
                <div class="circle2"></div>
                <div class="circle3"></div>
            </div>
        </div>
    </div>

    <div id="myIframe1ID"></div>
    <div id="myIframe2ID"></div>
    <div id="myIframe3ID"></div>
    <div id="myIframe4ID"></div>
    <div id="myIframe5ID"></div>

    </div>
    <svg class="waves" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
        <defs>
            <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z" />
        </defs>
        <g class="parallax">
            <use xlink:href="#gentle-wave" x="48" y="0" fill="rgba(255,255,255,0.7" />
            <use xlink:href="#gentle-wave" x="48" y="3" fill="rgba(255,255,255,0.5)" />
            <use xlink:href="#gentle-wave" x="48" y="5" fill="rgba(255,255,255,0.3)" />
            <use xlink:href="#gentle-wave" x="48" y="7" fill="#fff" />
        </g>
    </svg>
    </div>

    <footer>
        <!-- <p>Develop <i class="fa fa-heart"></i> by <a target="_blank" href="#">
                <font color="f5bcfc">Chula Unisearch</font>
            </a>& <font color="9deffe">NKL International Co.,Ltd.</font>
        </p> -->
        <p>Develop by Chula Unisearch, Chulalongkorn University
        </p>
    </footer>
    <iframe style="display: none; " name="myFrame" src="#"></iframe>

</body>

</html>
<script type="text/javascript" src="js/config_function.js?dc=<?= __VPRODUCT_ ?>"></script>
<script type="text/javascript" src="access/js/tap_active_signin.js?dc=<?= __VPRODUCT_ ?>"></script>

<script>
    Ext.onReady(function() {
        Ext.login_test = false;
        typeD = false;

        //define("NMU_SUPPLIES_HOST", MAIN_HOST_NAME . NMU_SUPPLIES_PORT . "/supplies"); //URL ระบบ NMU_ERP  
        //define("EIS_PROCURE_HOST", MAIN_HOST_NAME . NMU_SUPPLIES_PORT . "/procure"); //URL ระบบ procure 
        Ext.SUPPLIES = '<?= OST_HOST . '://' . NMU_SUPPLIES_HOST . '/access/login_1.php'; ?>';
        Ext.PROCURE = '<?= OST_HOST . '://' . EIS_PROCURE_HOST . '/access/login_1.php'; ?>';



        document.getElementById("userID").focus();
        document.getElementById("userID").addEventListener("keypress", function(event) {
            if (event.key === "Enter") {
                event.preventDefault();
                document.getElementById("buSubmitID").click();
            }
        });
        document.getElementById("passID").addEventListener("keypress", function(event) {
            if (event.key === "Enter") {
                event.preventDefault();
                document.getElementById("buSubmitID").click();
            }
        });
        Ext.QuickTips.init();
        Ext.mainSystem = 'EIS';
        Ext.box = null;
        var header = document.getElementById("myDIV");
        var btns = header.getElementsByClassName("btn");
        var buSubmit = document.getElementById("buSubmitID");
        Ext.sysEis = [1, ["a", "3"]];
        buSubmit.addEventListener("click", function() {
            Ext.getBody().mask("Please wait...", "x-mask-loading");
            Ext.MessageBox.minWidth = 400;
            Ext.Ajax.request({
                url: "login.php",
                method: "POST",
                params: {
                    mode: typeD ? "LOGIN" : "SIGNIN",
                    user: Ext.get('userID').dom.value,
                    pass: Ext.get('passID').dom.value
                },
                success: function(result, request) {
                    let json = Ext.util.JSON.decode(result.responseText); //decode json

                    Ext.get('bodyID').mask('Login Loading', 'x-mask-loading');
                    if (json.success == "Success") {
                        let url = json.data[0].url;
                        let user_id = json.data[0].sub_user_id;

                        let eis_id = parseInt(json.data[0].dc_user_eis_id[0]);
                        let fm_id = parseInt(json.data[0].dc_user_fm_id[0]);
                        let nmu_id = parseInt(json.data[0].dc_user_nmu_id[0]);
                        //port 443
                        let supplies_id = parseInt(json.data[0].dc_user_supplies_id[1]);
                        let procure_id = parseInt(json.data[0].dc_user_procure_id[1]); //dc_user_procure_id



                        //----------------------------------------------

                        if (parseInt(json.data[0].dc_user_eis_id[1]) > 0) {
                            document.getElementById("myIframe1ID").innerHTML = '<iframe style="visibility:hidden" src="' + url[eis_id] + '?ss_st=St0099&&ss_user_id=' + user_id[eis_id] + '&ss_last_login=2023-04-22%2013:41:04"></iframe>';
                        }

                        if (parseInt(json.data[0].dc_user_fm_id[1]) > 0) {
                            document.getElementById("myIframe2ID").innerHTML = '<iframe style="visibility:hidden" src="' + url[fm_id] + '?ss_st=St0099&&ss_user_id=' + user_id[fm_id] + '&ss_last_login=2023-04-22%2013:41:04"></iframe>';
                        }
                        if (parseInt(json.data[0].dc_user_nmu_id[1]) > 0) {
                            document.getElementById("myIframe3ID").innerHTML = '<iframe style="visibility:hidden" src="' + url[nmu_id] + '?ss_st=St0099&&ss_user_id=' + user_id[nmu_id] + '&ss_last_login=2023-04-22%2013:41:04"></iframe>';
                        }

                        if (!Ext.login_test) {
                            /**/
                            setTimeout(() => {
                                Ext.getBody().unmask();
                                window.location.href = './entrance';
                            }, 1000);
                        }
                        // });
                    } else {
                        Ext.getBody().unmask();

                        Ext.Msg.alert("แจ้งเตือน", json.msg);
                    }


                },
                failure: function(result, request) {
                    Ext.MessageBox.alert("Failed", result.responseText); // connect error
                }
            });
        });

        for (var i = 0; i < btns.length; i++) {

            btns[i].addEventListener("click", function() {
                var current = document.getElementsByClassName("active");
                document.getElementsByClassName("active")[0].className = current[0].className.replace(" active", ""); //add class 
                this.className += " active";
            });

            const nameID = document.getElementById(btns[i].getAttribute('id'));

            nameID.addEventListener('click', () => {
                Ext.mainSystem = nameID.innerText;
                if (!Ext.getCmp("win-" + nameID))
                    new Ext.Window({
                        id: "win-" + nameID,
                        modal: false,
                        width: 850,
                        title: " " + nameID.innerText,
                        layout: "fit",
                        height: 250,
                        html: ' <===> ' + nameID.id,
                        items: [],
                        listeners: {
                            beforerender: async function() {
                                //                                 alert(1);
                            },
                            afterrender: async function() {
                                //                                 alert(2);   
                            },
                            show: async function() {
                                //                                 alert(3);   

                            }
                        }

                    }).show();
                else
                    Ext.getCmp("win-" + nameID).destroy();
            });

        } //LOOP 


    });
</script>