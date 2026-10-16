<?php
final class PortalSession
{
    public static function allows(int $centerUserId): bool
    {
        $cookie = (string)($_COOKIE['PHPSESSID'] ?? '');
        $path = getenv('EIS_PERMISSION_SESSION_DIR') ?: 'C:/xampp/htdocs/NMU_permission/tmp/sessions';
        if ($centerUserId <= 0 || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $cookie) || !is_file($path . '/sess_' . $cookie)) return false;
        $name = session_name(); $id = session_id(); $directory = session_save_path(); $cookies = ini_get('session.use_cookies');
        session_write_close();
        session_name('PHPSESSID'); session_id($cookie); session_save_path($path);
        session_start(['use_cookies'=>false, 'read_and_close'=>true]); $portal = $_SESSION;
        session_name($name); session_id($id); session_save_path($directory); ini_set('session.use_cookies', $cookies); session_start();
        return isset($portal['i_type_user']) && (int)($portal['dc_user_id'] ?? 0) === $centerUserId && (int)($portal['i_permit_procure'] ?? 0) > 0;
    }
}
