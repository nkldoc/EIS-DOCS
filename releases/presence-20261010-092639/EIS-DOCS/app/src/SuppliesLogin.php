<?php
declare(strict_types=1);

require_once __DIR__ . '/Presence.php';
require_once __DIR__ . '/SingleLogin.php';

final class SuppliesLogin
{
    public static function validate(): bool
    {
        if (empty($_SESSION['user_id']) || !Presence::enabled()) return true;
        $_SESSION['login_instance'] ??= '';
        $ttl = max(1, (int)($_SESSION['last_activity'] ?? time()) + 7200 - time());
        if (!SingleLogin::touch('SUPPLIES', (string)$_SESSION['user_id'], $_SESSION['login_instance'], (string)($_SESSION['last_login'] ?? ''), $ttl)) {
            SingleLogin::release('SUPPLIES', (string)$_SESSION['user_id'], $_SESSION['login_instance']);
            $_SESSION = ['login_replaced'=>true];
            return false;
        }
        return true;
    }
    public static function begin(array $row, array $extra = [], bool $html = false): string
    {
        $uid = (string)$row['dc_user_id'];
        $current = (string)($_SESSION['user_id'] ?? '') === $uid ? ($_SESSION['login_instance'] ?? null) : null;
        $admin = (int)($row['i_type_user'] ?? 0) === 2 && !isset($extra['impersonation_admin_center_id']);
        try {
            if ($admin) throw new DuplicateLoginException('');
            return SingleLogin::start('SUPPLIES', $uid, $current);
        }
        catch (DuplicateLoginException $e) {
            $proof = isset($row['c_password']) ? hash('sha256', (string)$row['c_password']) : null;
            unset($row['c_password'], $row['password'], $row['passwordf']);
            $previous = $_SESSION['supplies_login_pending'] ?? null;
            $nonce = $previous && (string)$previous['row']['dc_user_id'] === $uid && $previous['owner'] === $e->owner && $previous['expires'] >= time() ? $previous['nonce'] : bin2hex(random_bytes(32));
            $_SESSION['supplies_login_pending'] = ['admin'=>$admin, 'row'=>$row, 'extra'=>$extra, 'proof'=>$proof, 'owner'=>$e->owner, 'nonce'=>$nonce, 'expires'=>time()+120];
            session_write_close();
            if ($html) { header('Location: /supplies/access/confirm-login.php'); exit; }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['reval'=>2, 'success'=>false, 'duplicate_login'=>true, 'msg'=>$e->getMessage(), 'confirm_url'=>'/supplies/access/confirm-login.php'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    public static function activate(array $row, string $instance, array $extra = []): void
    {
        $oldUid = (string)($_SESSION['user_id'] ?? '');
        if ($oldUid !== '') { Presence::revoke('SUPPLIES', $oldUid, (string)($_SESSION['last_login'] ?? '')); SingleLogin::release('SUPPLIES', $oldUid, (string)($_SESSION['login_instance'] ?? '')); }
        $_SESSION = [];
        if (!session_regenerate_id(true)) { SingleLogin::release('SUPPLIES', (string)$row['dc_user_id'], $instance); throw new RuntimeException('ไม่สามารถสร้างเซสชันใหม่ได้ กรุณาลองใหม่'); }
        $_SESSION['DOMAIN'] = DOMAIN['en']; $_SESSION['DOMAIN_NAME'] = DOMAIN['th'];
        $_SESSION['DOMAIN_LOGIN'] = 'SUPPLIES'; $_SESSION['menu'] = $extra['menu'] ?? 'sp/dashBard.php';
        $_SESSION['user_id'] = (int)$row['dc_user_id']; $_SESSION['user_name'] = (string)$row['c_full_name'];
        if ((int)($row['i_type_user'] ?? 0) === 2 && !isset($extra['impersonation_admin_center_id']) && isset($extra['admin_operator'])) $_SESSION['admin_operator'] = SingleLogin::operatorName((string)$extra['admin_operator']);
        foreach (['dc_emp_id','sp_emp_id','dc_department_id','dc_department_type_id','i_seq','i_level','dc_center_user','dc_cost_id','dc_area_id','i_type_user','dc_cost_acc_id','i_set_type_emp','i_type_emp','super_user'] as $field) $_SESSION[$field] = (int)($row[$field] ?? 0);
        foreach (['c_sp_emp','c_department_type','c_department','c_position','cost_name','cost_code'] as $field) $_SESSION[$field] = (string)($row[$field] ?? '');
        if (isset($extra['impersonation_admin_center_id'])) $_SESSION['impersonation_admin_center_id'] = (int)$extra['impersonation_admin_center_id'];
        $_SESSION['last_login'] = date('Y-m-d H:i:s'); $_SESSION['last_activity'] = time(); $_SESSION['created'] = time(); $_SESSION['login_instance'] = $instance;
        self::validate();
        if (empty($_SESSION['user_id'])) throw new RuntimeException('มีการเข้าสู่ระบบใหม่ระหว่างดำเนินการ กรุณาลองใหม่');
    }
    public static function confirm(string $nonce, string $operator = ''): void
    {
        $pending = $_SESSION['supplies_login_pending'] ?? null;
        if (!$pending || $pending['expires'] < time() || !hash_equals($pending['nonce'], $nonce)) { unset($_SESSION['supplies_login_pending']); throw new RuntimeException('คำยืนยันหมดอายุ กรุณาเข้าสู่ระบบใหม่'); }
        if (!class_exists('DatabaseServer', false)) {
            $root = getenv('EIS_SUPPLIES_ROOT') ?: (is_dir(dirname(__DIR__, 2) . '/lib/database') ? dirname(__DIR__, 2) : 'D:/EIS-ERP/public/supplies');
            require_once $root . '/lib/database/DatabaseServer.php';
        }
        $db = new DatabaseServer();
        $stmt = $db->QueryParam('SELECT dc_user_id,c_password,i_type_user,c_full_name,dc_cost_id FROM dbo.dc_user WHERE dc_user_id=? AND i_enable=? AND i_delete=?', [(int)$pending['row']['dc_user_id'], STATUS_ENABLE, DELETE_FALSE]);
        $fresh = $stmt ? $db->Fetch($stmt) : null;
        if (!$fresh || (isset($pending['proof']) && !hash_equals($pending['proof'], hash('sha256', (string)$fresh['c_password'])))) { unset($_SESSION['supplies_login_pending']); throw new RuntimeException('บัญชีหรือรหัสผ่านเปลี่ยนแปลง กรุณาเข้าสู่ระบบใหม่'); }
        unset($fresh['c_password']); $pending['row'] = array_replace($pending['row'], $fresh);
        if (isset($pending['extra']['impersonation_admin_center_id'])) {
            $stmt = $db->QueryParam('SELECT dc_user_id FROM ' . DB_CENTER . 'dc_user WHERE dc_user_id=? AND i_type_user=2 AND i_enable=1 AND i_delete=2', [(int)$pending['extra']['impersonation_admin_center_id']]);
            if (!$stmt || !$db->Fetch($stmt)) { unset($_SESSION['supplies_login_pending']); throw new RuntimeException('สิทธิ์ผู้ดูแลระบบเปลี่ยนแปลง กรุณาขอสวมสิทธิ์ใหม่'); }
        }
        $operator = !empty($pending['admin']) ? SingleLogin::operatorName($operator) : '';
        if (!empty($pending['admin']) && (int)$fresh['i_type_user'] !== 2) { unset($_SESSION['supplies_login_pending']); throw new RuntimeException('สิทธิ์ Admin เปลี่ยนแปลง กรุณาเข้าสู่ระบบใหม่'); }
        $owner = ($pending['operator'] ?? '') === $operator ? ($pending['owner'] ?: null) : null;
        $_SESSION['supplies_login_pending']['operator'] = $operator;
        if ($operator !== '') $pending['extra']['admin_operator'] = $operator;
        try { $instance = SingleLogin::start('SUPPLIES', (string)$pending['row']['dc_user_id'], null, $owner, $operator); }
        catch (DuplicateLoginException $e) { $_SESSION['supplies_login_pending']['owner'] = $e->owner; $_SESSION['supplies_login_pending']['nonce'] = bin2hex(random_bytes(32)); throw new RuntimeException($operator !== '' && $owner === null ? 'ชื่อผู้ดูแลนี้ใช้งานอยู่ กรุณายืนยันการแทนที่ หรือเปลี่ยนชื่อผู้ดูแล' : 'มีเซสชันใหม่เข้ามาระหว่างยืนยัน กรุณายืนยันอีกครั้ง'); }
        self::activate($pending['row'], $instance, $pending['extra']);
    }
    public static function ssoAllowed(array $row): bool
    {
        $cookie = (string)($_COOKIE['PHPSESSID'] ?? '');
        $portalDirectory = getenv('EIS_PERMISSION_SESSION_DIR') ?: 'C:/xampp/htdocs/NMU_permission/tmp/sessions';
        if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $cookie) || !is_file($portalDirectory . '/sess_' . $cookie)) return false;
        $name = session_name(); $id = session_id(); $directory = session_save_path(); $cookies = ini_get('session.use_cookies');
        session_write_close();
        session_name('PHPSESSID'); session_id($cookie); session_save_path($portalDirectory);
        session_start(['use_cookies'=>false, 'read_and_close'=>true]); $portal = $_SESSION;
        session_name($name); session_id($id); session_save_path($directory); ini_set('session.use_cookies', $cookies); session_start();
        return (int)($row['dc_center_user'] ?? 0) > 0 && (int)($portal['dc_user_id'] ?? 0) === (int)$row['dc_center_user'] && (int)($portal['i_permit_supplies'] ?? 0) === 1;
    }
}
