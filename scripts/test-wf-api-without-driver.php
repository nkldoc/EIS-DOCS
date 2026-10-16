<?php
declare(strict_types=1);
// Run with php -n to verify that error handling works without the SQLSRV extension.
if (function_exists('sqlsrv_connect')) throw new RuntimeException('Run this test using php -n');
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/app/src/bootstrap.php';
$_SESSION['auth'] = ['user_id' => 123, 'user_type' => 1, 'cost_id' => 0];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['q'] = '';
ob_start();
require dirname(__DIR__) . '/app/public/docMain/wf-groups.php';
$output = ob_get_clean();
$result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
if (http_response_code() !== 500 || ($result['success'] ?? true) !== false || strpos($result['message'], 'Microsoft Drivers') === false) {
    throw new RuntimeException('Missing-driver response was not valid JSON with the actual error');
}
echo "PASS: missing SQLSRV extension returns a JSON error without a second fatal error\n";
