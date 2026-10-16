<?php
// Run with php -n: database functions below are fixtures, not the live SQL driver.
declare(strict_types=1);
if (extension_loaded('sqlsrv')) throw new RuntimeException('Run this test with php -n to isolate database access');
define('SQLSRV_FETCH_ASSOC', 2);
final class Database { public static function connect(): object { return new stdClass(); } }
$fixturePassword = md5('test-only-password'); $fixtureEnabled = true; $fixtureType = 1;
function sqlsrv_query($connection, $sql, $params) {
    global $fixturePassword, $fixtureEnabled, $fixtureType;
    return (object)['row'=>$fixtureEnabled ? ['dc_user_id'=>123456, 'dc_emp_id'=>1, 'dc_cost_id'=>38, 'c_user_name'=>'test-only-account', 'c_password'=>$fixturePassword, 'c_full_name'=>'Test only', 'i_type_user'=>$fixtureType] : null];
}
function sqlsrv_fetch_array($statement, $mode) { return $statement->row; }
function sqlsrv_free_stmt($statement) {}
function sqlsrv_close($connection) {}
function sqlsrv_connect($server, $options) { return new stdClass(); }
require_once dirname(__DIR__, 2) . '/src/helpers/safeCmp.php';
require_once dirname(__DIR__, 2) . '/src/Presence.php';
require_once dirname(__DIR__, 2) . '/src/SingleLogin.php';
require_once dirname(__DIR__, 2) . '/src/Auth.php';
$directory = sys_get_temp_dir() . '/single-login-test-' . bin2hex(random_bytes(8));
mkdir($directory); mkdir($directory . '/sessions');
putenv('EIS_DOCS_PRESENCE_DIR=' . $directory);
$_SERVER['HTTP_HOST'] = 'dev-eis.nmu.ac.th';
session_save_path($directory . '/sessions'); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
$checks = 0;
function check(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function browser(string $id): void { if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); session_id($id); session_start(); }
function pending(): array {
    try { Auth::attempt('test-only-account', 'test-only-password'); throw new RuntimeException('Duplicate login was silently accepted'); }
    catch (DuplicateLoginException $e) { check(!isset($_SESSION['auth']), 'Pending login must not authenticate'); return $_SESSION['docs_login_pending']; }
}
function rejected(callable $fn): void { try { $fn(); throw new LogicException('Expected rejection'); } catch (RuntimeException $e) { check(true, 'Rejected'); } }
function record(string $system): array { return json_decode(file_get_contents(SingleLogin::registryPath($system, '123456')), true); }
function cleanup(string $directory): void { foreach (scandir($directory) as $name) { if ($name === '.' || $name === '..') continue; $file = $directory . '/' . $name; if (is_dir($file)) cleanup($file); else unlink($file); } rmdir($directory); }
try {
    browser('browser-a');
    check(!Auth::attempt('test-only-account', 'wrong-password'), 'Bad credentials must fail');
    check(!isset($_SESSION['docs_login_pending']), 'Bad password must not reveal a duplicate');
    check(Auth::attempt('test-only-account', 'test-only-password'), 'First login succeeds');
    $a = session_id(); $oldInstance = $_SESSION['auth']['login_instance'];
    $oldKey = Presence::sessionKey('EIS-DOCS', '123456', $_SESSION['auth']['login_at']);
    browser('browser-b'); $proof = pending();
    check(!is_file($directory . '/revoked/' . $oldKey), 'Prompt must preserve the old session');
    check(!str_contains(json_encode($proof), 'test-only-password'), 'Pending proof must not retain the password');
    unset($_SESSION['docs_login_pending']);
    browser($a); check(Auth::check(), 'Cancel must preserve old authentication');
    browser('browser-b'); $proof = pending(); rejected(fn()=>Auth::confirmDuplicate('invalid-nonce'));
    browser($a); check(Auth::check(), 'Invalid nonce must not displace old login');
    browser('browser-b'); $proof = pending(); Auth::confirmDuplicate($proof['nonce']);
    $b = session_id(); $bInstance = $_SESSION['auth']['login_instance'];
    check(Auth::check(), 'Confirmed new session authenticates');
    check(is_file($directory . '/revoked/' . $oldKey), 'Old presence token is revoked');
    browser($a); check(!Auth::check(), 'Old PHP session is denied on the next request');
    SingleLogin::release('EIS-DOCS', '123456', $oldInstance);
    check(record('EIS-DOCS')['instance'] === $bInstance && record('EIS-DOCS')['expires'] > time(), 'Old logout cannot release the new owner');
    browser($b); check(Auth::check(), 'Other tabs sharing a session stay authenticated');
    $supplies = SingleLogin::start('SUPPLIES', '123456');
    check(SingleLogin::touch('SUPPLIES', '123456', $supplies, 'supplies-test', 7200), 'Same account can use Supplies independently');
    browser('browser-c'); $proof = pending(); $_SESSION['docs_login_pending']['expires'] = time()-1;
    rejected(fn()=>Auth::confirmDuplicate($proof['nonce']));
    check(record('EIS-DOCS')['instance'] === $bInstance, 'Expired proof must not replace');
    $proofC = pending(); $c = session_id();
    browser('browser-d'); $proofD = pending(); Auth::confirmDuplicate($proofD['nonce']); $d = session_id(); $dInstance = $_SESSION['auth']['login_instance'];
    browser($c); rejected(fn()=>Auth::confirmDuplicate($proofC['nonce']));
    check(record('EIS-DOCS')['instance'] === $dInstance, 'Stale confirmation must not overwrite another new login');
    check($_SESSION['docs_login_pending']['owner'] === $dInstance && $_SESSION['docs_login_pending']['nonce'] !== $proofC['nonce'], 'Race must require a fresh confirmation');
    $proofC = $_SESSION['docs_login_pending']; $fixturePassword = md5('changed-test-password');
    rejected(fn()=>Auth::confirmDuplicate($proofC['nonce']));
    check(record('EIS-DOCS')['instance'] === $dInstance, 'Changed password invalidates the pending proof');
    check(record('SUPPLIES')['instance'] === $supplies, 'EIS-DOCS takeover must not affect Supplies');
    $fixturePassword = md5('test-only-password');
    browser($d); Auth::logout();
    check(record('EIS-DOCS')['expires'] === 0, 'Logout releases ownership');
    browser('browser-e'); check(Auth::attempt('test-only-account', 'test-only-password'), 'Login after logout requires no duplicate prompt');
    $docsInstance = $_SESSION['auth']['login_instance'];
    foreach (['DB_SERVER'=>'fixture','DB_USER'=>'fixture','DB_PASS'=>'','DB_NAME'=>'fixture','DB_CHARSET'=>'UTF-8','STATUS_ENABLE'=>1,'DELETE_FALSE'=>2,'DB_CENTER'=>'fixture.'] as $name=>$value) define($name, $value);
    define('DOMAIN', ['en'=>'supplies','th'=>'Supplies']);
    require_once dirname(__DIR__, 2) . '/src/SuppliesLogin.php';
    SingleLogin::release('SUPPLIES', '123456', $supplies);
    browser('supplies-a');
    $row = ['dc_user_id'=>123456,'c_full_name'=>'Test only','i_type_user'=>1,'dc_cost_id'=>38,'c_password'=>$fixturePassword];
    $instance = SuppliesLogin::begin($row); SuppliesLogin::activate($row, $instance);
    $supplyA = session_id(); $supplyKey = Presence::sessionKey('SUPPLIES', '123456', $_SESSION['last_login']);
    browser('supplies-b');
    try { SingleLogin::start('SUPPLIES', '123456'); throw new LogicException('Supplies must prompt'); }
    catch (DuplicateLoginException $e) {
        $cached = $row; unset($cached['c_password']);
        $_SESSION['supplies_login_pending'] = ['row'=>$cached,'extra'=>[],'proof'=>hash('sha256',$fixturePassword),'owner'=>$e->owner,'nonce'=>bin2hex(random_bytes(32)),'expires'=>time()+120];
    }
    check(!is_file($directory.'/revoked/'.$supplyKey), 'Supplies prompt preserves the old session');
    $pending = $_SESSION['supplies_login_pending'];
    rejected(fn()=>SuppliesLogin::confirm('invalid-nonce'));
    check(record('SUPPLIES')['instance'] === $instance, 'Invalid Supplies nonce must not replace');
    $_SESSION['supplies_login_pending'] = $pending; SuppliesLogin::confirm($pending['nonce']);
    check(!empty($_SESSION['user_id']) && $_SESSION['login_instance'] !== $instance, 'Confirmed Supplies login authenticates');
    check(is_file($directory.'/revoked/'.$supplyKey), 'Supplies old token revoked');
    browser($supplyA); SuppliesLogin::validate(); check(empty($_SESSION['user_id']), 'Supplies old PHP session denied');
    check(record('EIS-DOCS')['instance'] === $docsInstance, 'Supplies replacement must not affect EIS-DOCS');
    session_write_close(); mkdir($directory.'/portal'); putenv('EIS_PERMISSION_SESSION_DIR='.$directory.'/portal');
    session_save_path($directory.'/portal'); session_name('PHPSESSID'); session_id('portal-fixture-session-123456'); session_start();
    $_SESSION = ['dc_user_id'=>123456,'i_permit_supplies'=>1]; session_write_close();
    session_save_path($directory.'/sessions'); session_name('NMU_SUPPLIES_SESSID'); browser('sso-candidate-session');
    $_COOKIE['PHPSESSID'] = 'portal-fixture-session-123456'; $candidateId = session_id(); $cookies = ini_get('session.use_cookies');
    check(SuppliesLogin::ssoAllowed(['dc_center_user'=>123456]), 'SSO requires a matching authenticated permission session');
    check(session_name() === 'NMU_SUPPLIES_SESSID' && session_id() === $candidateId && ini_get('session.use_cookies') === $cookies, 'SSO proof checking must restore the Supplies session and cookie configuration');
    check(!SuppliesLogin::ssoAllowed(['dc_center_user'=>654321]), 'SSO cannot choose another account by changing an ID');
    check(SingleLogin::operatorName('admin-art-supplies') === 'admin-art', 'Supplies suffix resolves to the base operator');
    check(SingleLogin::operatorName('admin-art-EIS-DOCS') === 'admin-art', 'Docs suffix resolves to the base operator');
    $fixtureType = 2;
    foreach (['eis-docs','supplies'] as $source) file_put_contents($directory.'/'.$source.'.secret', bin2hex(random_bytes(32)));
    function namedDocs(string $name): void {
        try { Auth::attempt('test-only-account', 'test-only-password'); throw new LogicException('Admin must enter a name'); }
        catch (RuntimeException $e) { check(!isset($_SESSION['auth']) && !empty($_SESSION['docs_login_pending']['admin']), 'Name prompt only after verified admin credentials'); }
        Auth::confirmDuplicate($_SESSION['docs_login_pending']['nonce'], $name);
    }
    browser('admin-eak-browser'); namedDocs('admin-eak'); $eakBrowser=session_id();
    $eakToken = Presence::token('EIS-DOCS','123456','Test','Test',$_SESSION['auth']['login_at'],true);
    $payload=json_decode(base64_decode(strtr(explode('.', $eakToken['token'])[0], '-_', '+/')),true);
    check($payload['uid']==='123456@admin-eak' && $payload['name']==='admin-eak-EIS-DOCS' && $payload['admin'], 'Signed chat identity separates named admins');
    browser('admin-moo-browser'); namedDocs('admin-moo'); $mooBrowser=session_id();
    browser($eakBrowser); check(Auth::check(), 'Different named admins remain logged in together');
    browser('admin-eak-replacement');
    try { namedDocs('admin-eak'); throw new LogicException('Same name must confirm replacement'); } catch (RuntimeException $e) {}
    $proof=$_SESSION['docs_login_pending']; check($proof['operator']==='admin-eak' && $proof['owner']!=='', 'Duplicate named admin requires confirmation');
    rejected(fn()=>Auth::confirmDuplicate($proof['nonce'], 'bad<script>'));
    Auth::confirmDuplicate($proof['nonce'], 'admin-eak');
    browser($eakBrowser); check(!Auth::check(), 'Only the replaced named admin is denied');
    browser($mooBrowser); check(Auth::check(), 'Replacing eak does not log out moo');
    Auth::logout();
    browser('supplies-named-eak');
    $row['i_type_user']=2; $cached=$row; unset($cached['c_password']);
    $makePending = static function() use ($cached,$fixturePassword) { $_SESSION['supplies_login_pending']=['admin'=>true,'row'=>$cached,'extra'=>[],'proof'=>hash('sha256',$fixturePassword),'owner'=>'','nonce'=>bin2hex(random_bytes(32)),'expires'=>time()+120]; };
    $makePending(); SuppliesLogin::confirm($_SESSION['supplies_login_pending']['nonce'], 'admin-eak'); $sEak=session_id();
    browser('supplies-named-moo'); $makePending(); SuppliesLogin::confirm($_SESSION['supplies_login_pending']['nonce'], 'admin-moo');
    check($_SESSION['admin_operator']==='admin-moo', 'Supplies stores verified operator name');
    browser($sEak); check(SuppliesLogin::validate() && !empty($_SESSION['user_id']), 'Supplies admins work simultaneously');
    $token=Presence::token('SUPPLIES','123456','Test','Test',$_SESSION['last_login']);
    $payload=json_decode(base64_decode(strtr(explode('.', $token['token'])[0], '-_', '+/')),true);
    check($payload['uid']==='123456@admin-eak' && $payload['name']==='admin-eak-supplies' && !$payload['admin'], 'Supplies name does not grant Docs administration');
    $_SESSION['impersonation_admin_center_id']=123; check(SingleLogin::operator('SUPPLIES','123456')==='', 'Impersonation cannot use named-admin exemption');
    session_write_close();
    echo 'PASS: ' . $checks . " single-login checks including concurrent named admins and replacement isolation\n";
} finally { if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); cleanup($directory); }
