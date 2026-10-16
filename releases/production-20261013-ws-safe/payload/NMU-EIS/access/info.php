<?php
require_once("../conf/config.php");
$_btn = "";
if (isset($_SESSION['user_id']) && (int)($_SESSION['i_type_user'] ?? 0) === 2) {
	$_btn .= ' &nbsp;<a href="access/user_online.php" target="NMU_EIS_user_online" style="color:inherit">ผู้ใช้งานออนไลน์ (Admin)</a>';
}
if (@$_SESSION['user_id'] == 1) {
	$_btn .= '&nbsp;<a href="access/changeViewCost.php" target="NMU_EIS_changeViewCost"><img src="images/icons/information.png"></a>';
}

function encodeDate($number)
{
	$map = ['Q', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
	// $map = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
	$chars = str_split($number);
	$result = '';
	foreach ($chars as $char) {
		$result .= $map[intval($char)];
	}
	return $result;
}

$decodeDate = encodeDate(date('mdHis'));
$decodeDate = "&nbsp;&nbsp;<spen style='font-family: Arial, Helvetica, sans-serif; color: #ebebeb; font-size: 8px; opacity: 50%;'>" . $decodeDate . "</spen>";

echo '<div id="info-user" align="left">'
	. '<span class="info-username"> ชื่อผู้ใช้งาน : ' . @$_SESSION['user_name'] . $_btn . ' </span><br/>'
	. '<span class="info-username"> หน่วยงาน : ' . @$_SESSION['cost_name'] . ' </span><br/>'
	// 		.'<div class="info"><span class="info-key" id="info-changepass">&nbsp;&nbsp;&nbsp;&nbsp; เปลี่ยนรหัสผ่าน&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; </span></div>'
	. '<div class="info" id="close"><span class="info-close" id="logout"><a class="header" href="https://' . NMU_PERMISSION_HOST . '/access/logout.php" target="_self">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ออกจากระบบ</a> ' . $decodeDate . '</span></div>'
	. '</div>';
