<?PHP
include_once "./conf/config.php";
include_once "./lib/database/DatabaseServer.php";
include_once "./lib/database/apiUtil.php";
include_once "./lib/date/i_date.class.php";
include_once 'inc.php';

$db = new DatabaseServer();
$annouce = array();
$sql = "SET NOCOUNT ON 
    select c_code, c_text from " . DB_CENTER . "dc_annouce";
$stmt = $db->QueryParam($sql, array());
while ($row = $db->Fetch($stmt)) {
  $annouce[$row["c_code"]] = $row["c_text"];
}

if (OST_HOST == "http") {
  echo "<script>window.location.href =\"https://" . HTTPS_HOST_NAME . "/NMU_permission\"</script>";
  exit;
}

if (!isset($_SESSION['i_type_user'])) {
  echo "<script>window.location.href =\"https://" . NMU_PERMISSION_HOST . "\"</script>";
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
  <link rel="stylesheet" href="css/style.css?dc=<?= __VPRODUCT_ ?>">
  <link rel="shortcut icon" href="images/favicon.ico" type="image/x-icon">

  <link rel="stylesheet" type="text/css" title="gray" href="./js/ext-3.4.0/resources/css/xtheme-gray.css" />
  <script type="text/javascript" src="./js/jquery.js"></script>
  <script type="text/javascript" src="./js/ext-3.4.0/adapter/jquery/ext-jquery-adapter.js"></script>
  <script type="text/javascript" src="./js/ext-3.4.0/ext-all.js"></script>

  <script type="text/javascript">
    Ext.getInfo = {
      sysUserId: <?php echo $_SESSION["dc_user_id"]; ?>,
      user_name: '<?php echo $_SESSION["c_full_name"]; ?>',
      i_type_user: <?php echo $_SESSION["i_type_user"]; ?>,
      cost_name: '<?php echo @$_SESSION['cost_name']; ?>',
    };
    Ext.session = Ext.getInfo;
    Ext.session.HOST_ERP = '<?php echo NMU_SUPPLIES_HOST; ?>'; // Define HOST for ChatAdmin
    Ext.date_now = '<?= date("Y-m-d") ?>';
  </script>

  <link href="./css/style.css" rel="stylesheet" type="text/css" />

  <div class="hdr">
    <div class="user-login">
      <div>
        <img src="imgs/user.png" ALIGN="ABSMIDDLE">
        ชื่อผู้ใช้งาน : <?php echo $_SESSION["c_full_name"]; ?>
      </div>
      <div>
        <img src="imgs/cost.png" ALIGN="ABSMIDDLE">
        หน่วยงาน : <?php echo $_SESSION["cost_name"]; ?>
      </div>
      <br>
      <div class="exit">
        <a href="access/logout.php"><img src="imgs/logout2.png" ALIGN="ABSMIDDLE">&nbsp;ออกจากระบบ</a>
      </div>
    </div>
  </div>
  <br>
</head>

<body>

  <?php

  //Popup Box
  if (@$annouce["POP_AFTER_LOGIN"] != "") {
    echo "
      <div id='popup' class='popup'> 
        <div class='popup-content'>
         <div id='close-btn' class='close-btn'>&times;</div>
              <div class='box-announce'>
              " . $annouce["POP_AFTER_LOGIN"] . "
              </div>
            <div class='popup-show-today'>
            <input type='checkbox' id='dont-show-today'>
            <label for='dont-show-today'>ไม่ต้องแสดงในวันนี้</label>
            </div>  
         </div>
      </div>";
  }
  ?>

  <h1 class="font-weight-bold">กรุณาเลือกระบบที่ท่านต้องการใช้งาน</h1>
  <div class="sep huge-gap light"></div>

  <br>
  <div class="menu-box">

    <?php
    $procure = $_SESSION['i_permit_procure'] ?? null;
    $supplies = $_SESSION['i_permit_supplies'] ?? null;

    $li = '';
    $li .= ($_SESSION['i_permit_eis'] > 0) ? '<a href="https://' . NMU_EIS_HOST . '">
      <div class="box-system b1" style="cursor:pointer">  
        <div class="head-menu">
          <div class="tab-color-b1"></div>
          <h2>
            EIS <br>
          </h2>
          <h4>
            Enterprise Information Systems
          </h4>
          <li>ระบบงบประมาณ</li>
          <li>ระบบการเงินรับ</li>
          <li>ระบบการเงินจ่าย</li>
          <li>ระบบบัญชีมหาวิทยาลัย</li>
          <li>ระบบบัญชีสินทรัพย์ถาวร</li>
        </div>
      </div>
    </a>' : '';
    $li .= ($_SESSION['i_permit_nmu'] > 0) ? '<a href="https://' . NMU_HOST . '">
      <div class="box-system b2" style="cursor:pointer"> 
        <div class="head-menu">
          <div class="tab-color-b2"></div>
          <h2>
            NMU <br>
          </h2>
          <h4>
            Accounting Financial Information <br> System
          </h4>
          <li>ระบบบัญชีคณะแพทย์</li>
          <li>ระบบลูกหนี้</li>
          <li>ระบบสนับสนุนการบริหารงานเบิกจ่ายเงินของฝ่ายการคลัง</li>
        </div>
      </div>
    </a>' : '';
    $li .= ($_SESSION['i_permit_fm'] > 0) ? '<a href="https://' . FM_NMU_HOST . '">
      <div class="box-system b3" style="cursor:pointer">
        <div class="head-menu">
          <div class="tab-color-b3"></div>
          <h2>
            FM <br>
          </h2>
          <h4>
            Fund Management <br> Faculty of Medicine
          </h4>
          <li>ระบบบัญชีกองทุนคณะแพทย์</li>
        </div>
      </div>
    </a>' : '';
    $li .= ($supplies > 0) ? '<a href="launch.php?system=supplies">
      <div class="box-system b4" style="cursor:pointer">  
        <div class="head-menu">
          <div class="tab-color-b4"></div>
          <h2>
            V-PROCURE<br>
          </h2> 
          <h4>
            V-Procurement Management System
          </h4>
          <li>ซื้อจ้าง พัสดุคณะแพทย์ </li> 
        </div>
      </div>
    </a>' : '';
    $li .= ($procure > 0) ? '<a href="launch.php?system=procure">
      <div class="box-system b5" style="cursor:pointer">  
        <div class="head-menu">
          <div class="tab-color-b5"></div>
          <h2>
            PROCURE<br>
          </h2> 
          <h4>
          Procurement Management System
          </h4>
          <li>ฝ่ายบริหารงานก่อสร้างบำรุงรักษาอาคารและพัสดุกลาง</li> 
        </div>
      </div>
    </a>' : '';
    echo $li;
    ?>
  </div>
  <br>

  <main class="card" style="margin-top:20px;">
    <div class="tools">
      <div class="circle">
        <span class="red box"></span>
      </div>
      <div class="circle">
        <span class="yellow box"></span>
      </div>
      <div class="circle">
        <span class="green box"></span>
      </div>
    </div>
    <div class="card__content">
      <div class="img-container">
        <img style="margin: 33px 10px 5px 25px; width: 950px;" src="imgs/img7.jpg">
      </div>
  </main>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>
  <br>

  <footer>
    <!-- <p>Develop <i class="fa fa-heart"></i> by <a target="_blank" href="#">
        <font color="f5bcfc">Chula Unisearch</font>
      </a>& <font color="9deffe">NKL International Co.,Ltd.</font>
    </p> -->
    <p>Develop by Chula Unisearch, Chulalongkorn University
    </p>
  </footer>

  <script>
    document.addEventListener("DOMContentLoaded", function() {
      // Get popup element, close button and checkbox
      const popup = document.getElementById("popup");
      const closeBtn = document.getElementById("close-btn");
      const checkbox = document.getElementById("dont-show-today");

      // ฟังก์ชันตรวจสอบว่าวันที่ใน localStorage เป็นวันนี้หรือไม่
      function isToday(date) {
        const today = new Date();
        return date.getDate() === today.getDate() &&
          date.getMonth() === today.getMonth() &&
          date.getFullYear() === today.getFullYear();
      }

      // โหลดค่าจาก Local Storage
      const dontShowToday = localStorage.getItem('dontShowToday');

      // ตรวจสอบค่าจาก Local Storage และแสดงป๊อปอัพถ้าจำเป็น
      if (dontShowToday === 'true') {
        console.log('Dont show today is true, no popup will be shown');
      } else {
        if (popup) {
          setTimeout(() => {
            popup.classList.add("show");
            document.body.classList.add("blocked"); // Block all interaction outside popup
          }, 500); // Delay to show the popup (optional)
          console.log('Showing popup because dontShowToday is not true');
        }
      }
      // เมื่อกดปิดป๊อปอัพ
      if (closeBtn) {
        closeBtn.addEventListener("click", () => {
          popup.classList.remove("show");
          document.body.classList.remove("blocked"); // Allow interaction when popup is closed

          // ถ้าติ๊ก checkbox "ไม่ต้องแสดงในวันนี้"
          if (checkbox.checked) {
            localStorage.setItem('dontShowToday', 'true');
            console.log('Dont show today is checked. Stored dontShowToday as true');
          } else {
            // ถ้าไม่ติ๊ก checkbox จะไม่บันทึกค่าใดๆ ทำให้แสดงป๊อปอัพในครั้งถัดไปเมื่อรีเฟรชหน้า
            console.log('Popup closed without checkbox checked. Will show on next refresh.');
          }
        });
      }

      // Close popup if user clicks outside the popup content
      if (popup) {
        popup.addEventListener("click", (event) => {
          if (event.target === popup) {
            popup.classList.remove("show");
            document.body.classList.remove("blocked");
          }
        });
      }

      // ลบ dontShowToday เฉพาะเมื่อเป็นวันใหม่จริง ๆ
      const popupLastShown = localStorage.getItem('popupLastShown');
      if (popupLastShown && !isToday(new Date(popupLastShown))) {
        localStorage.removeItem('dontShowToday');
        console.log('New day detected, removed dontShowToday');
      }

      // บันทึกเวลาแสดงป๊อปอัพวันนี้ใน Local Storage (เก็บไว้เช็ควันใหม่)
      localStorage.setItem('popupLastShown', new Date().toString());

    });
  </script>


</body>

</html>
<script type="text/javascript" src="js/config_function.js?dc=<?= __VPRODUCT_ ?>"></script>
<script type="text/javascript" src="js/ChatAdmin.js?dc=<?= __VPRODUCT_ ?>"></script>

<!-- Floating Action Button for Issue Reporting -->
<div id="fab-container" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
  <!-- แจ้งปัญหา (Report Issue) -->
  <button id="toggleChatBtn" title="แจ้งปัญหา (Report Issue)" onclick="openIssuePopup()">
    <img src="imgs/chat.png" alt="Chat Icon" style="width: 30px; height: 30px;">
  </button>
</div>

<script type="text/javascript" src="./js/tap_active_index.js?dc=<?= __VPRODUCT_ ?>"></script>
