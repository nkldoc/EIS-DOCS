<?php
require_once("../conf/config.php");
require_once(__DIR__ . '/presence_common.php');
presence_revoke();
require_once __DIR__ . '/../conf/messenger/Presence.php';
NMUEISMessenger::revoke('NMU-EIS', (string)($_SESSION['user_id'] ?? ''), (string)($_SESSION['last_login'] ?? ''));
session_destroy();

header('Location:./signin.php');
