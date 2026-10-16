<?php
// Local development: private files must stay outside the web document root.
function presence_dir() { return getenv('EIS_PRESENCE_DIR') ?: 'C:/xampp/var/nmu-eis-presence'; }
function presence_admin() { return isset($_SESSION['user_id']) && (int)($_SESSION['i_type_user'] ?? 0) === 2; }
function presence_session_key() { return hash('sha256', session_id() . '|' . ($_SESSION['user_id'] ?? '') . '|' . ($_SESSION['last_login'] ?? '') . '|' . ($_SESSION['admin_operator'] ?? '')); }
function presence_revoke() {
    $directory = presence_dir() . '/revoked';
    if (is_dir($directory) && session_id() !== '') {
        file_put_contents($directory . '/' . presence_session_key(), (string)time(), LOCK_EX);
    }
}
