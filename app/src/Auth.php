<?php

declare(strict_types=1);

final class Auth
{
    public static function check(): bool
    {
        if (!isset($_SESSION['auth']['user_id'])) return false;
        $_SESSION['auth']['login_instance'] ??= '';
        if (!SingleLogin::touch('EIS-DOCS', (string)$_SESSION['auth']['user_id'], $_SESSION['auth']['login_instance'], (string)($_SESSION['auth']['login_at'] ?? ''), max(60, (int)ini_get('session.gc_maxlifetime')))) {
            SingleLogin::release('EIS-DOCS', (string)$_SESSION['auth']['user_id'], $_SESSION['auth']['login_instance']);
            unset($_SESSION['auth'], $_SESSION['impersonation_admin'], $_SESSION['pin_sign_grants']);
            $_SESSION['login_replaced'] = true;
            return false;
        }
        return true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . appUrl('login'));
            exit;
        }
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return self::check() ? $_SESSION['auth'] : null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && in_array((int) ($user['user_type'] ?? 0), [2, 3], true);
    }

    public static function attempt(string $username, string $password): bool
    {
        unset($_SESSION['docs_login_pending']);
        $user = self::findLogin($username);
        // Verify credentials before revealing an existing login or allowing a takeover.
        if ($user === null || !safeCmp(strtolower((string)$user['c_password']), md5($password))) return false;
        if ((int)$user['i_type_user'] === 2) {
            $_SESSION['docs_login_pending'] = ['admin'=>true, 'username'=>$username, 'uid'=>(int)$user['dc_user_id'], 'proof'=>hash('sha256', (string)$user['c_password']), 'owner'=>'', 'nonce'=>bin2hex(random_bytes(32)), 'expires'=>time()+120];
            throw new RuntimeException('กรุณาระบุชื่อผู้ดูแลเพื่อใช้งานร่วมกับ Admin คนอื่น');
        }
        try { $instance = SingleLogin::start('EIS-DOCS', (string)$user['dc_user_id']); }
        catch (DuplicateLoginException $e) {
            $_SESSION['docs_login_pending'] = ['username'=>$username, 'uid'=>(int)$user['dc_user_id'], 'proof'=>hash('sha256', (string)$user['c_password']), 'owner'=>$e->owner, 'nonce'=>bin2hex(random_bytes(32)), 'expires'=>time()+120];
            throw $e;
        }
        self::activate($user, $instance);
        return true;
    }

    public static function confirmDuplicate(string $nonce, string $operator = ''): void
    {
        $pending = $_SESSION['docs_login_pending'] ?? null;
        if (!$pending || $pending['expires'] < time() || !hash_equals($pending['nonce'], $nonce)) { unset($_SESSION['docs_login_pending']); throw new RuntimeException('คำยืนยันหมดอายุ กรุณาเข้าสู่ระบบใหม่'); }
        $user = self::findLogin($pending['username']);
        if (!$user || (int)$user['dc_user_id'] !== $pending['uid'] || !hash_equals($pending['proof'], hash('sha256', (string)$user['c_password']))) { unset($_SESSION['docs_login_pending']); throw new RuntimeException('บัญชีหรือรหัสผ่านเปลี่ยนแปลง กรุณาเข้าสู่ระบบใหม่'); }
        $operator = !empty($pending['admin']) ? SingleLogin::operatorName($operator) : '';
        if (!empty($pending['admin']) && (int)$user['i_type_user'] !== 2) { unset($_SESSION['docs_login_pending']); throw new RuntimeException('สิทธิ์ Admin เปลี่ยนแปลง กรุณาเข้าสู่ระบบใหม่'); }
        $owner = ($pending['operator'] ?? '') === $operator ? ($pending['owner'] ?: null) : null;
        $_SESSION['docs_login_pending']['operator'] = $operator;
        try { $instance = SingleLogin::start('EIS-DOCS', (string)$user['dc_user_id'], null, $owner, $operator); }
        catch (DuplicateLoginException $e) { $_SESSION['docs_login_pending']['owner'] = $e->owner; $_SESSION['docs_login_pending']['nonce'] = bin2hex(random_bytes(32)); throw new RuntimeException($operator !== '' && $owner === null ? 'ชื่อผู้ดูแลนี้ใช้งานอยู่ กรุณายืนยันการแทนที่ หรือเปลี่ยนชื่อผู้ดูแล' : 'มีเซสชันใหม่เข้ามาระหว่างยืนยัน กรุณายืนยันอีกครั้ง'); }
        self::activate($user, $instance, $operator);
    }

    private static function findLogin(string $username): ?array
    {
        $connection = Database::connect();
        $sql = <<<'SQL'
SELECT TOP 1
    a.dc_user_id,
    a.dc_emp_id,
    a.dc_cost_id,
    a.c_user_name,
    a.c_password,
    a.c_full_name,
    a.i_type_user
FROM NMU_DATACENTER.dbo.dc_user AS a
WHERE a.i_enable = 1
  AND a.i_delete = 2
  AND a.c_user_name = ?
SQL;
        $statement = sqlsrv_query($connection, $sql, [$username]);
        if ($statement === false) {
            error_log('Login query failed: ' . json_encode(sqlsrv_errors()));
            sqlsrv_close($connection);
            throw new RuntimeException('ไม่สามารถตรวจสอบบัญชีผู้ใช้ได้');
        }

        $user = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC) ?: null;
        sqlsrv_free_stmt($statement);
        sqlsrv_close($connection);

        return $user;
    }
    private static function activate(array $user, string $instance, string $operator = ''): void
    {
        if (!session_regenerate_id(true)) { SingleLogin::release('EIS-DOCS', (string)$user['dc_user_id'], $instance); throw new RuntimeException('ไม่สามารถสร้างเซสชันใหม่ได้ กรุณาลองใหม่'); }
        unset($_SESSION['impersonation_admin']);
        unset($_SESSION['pin_sign_grants']);
        $_SESSION['app_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['auth'] = self::identity($user);
        $_SESSION['auth']['login_instance'] = $instance;
        if ($operator !== '') $_SESSION['auth']['admin_operator'] = $operator;
        unset($_SESSION['docs_login_pending'], $_SESSION['login_replaced']);
        if (!self::check()) throw new RuntimeException('มีการเข้าสู่ระบบใหม่ระหว่างดำเนินการ กรุณาลองใหม่');
    }

    private static function identity(array $user): array
    {
        return [
            'user_id' => (int) $user['dc_user_id'],
            'employee_id' => (int) $user['dc_emp_id'],
            'cost_id' => (int) $user['dc_cost_id'],
            'username' => (string) $user['c_user_name'],
            'full_name' => (string) $user['c_full_name'],
            'user_type' => (int) $user['i_type_user'],
            // Login ต้องอาศัย dc_user เท่านั้น ไม่เรียก sp_emp/ตารางบุคลากรอื่น
            'cost_name' => 'ผู้ใช้งานระบบ',
            'cost_code' => '',
            'cost_account_id' => 0,
            'login_at' => date(DATE_ATOM),
        ];

    }

    public static function impersonating(): bool
    {
        return self::check() && isset($_SESSION['impersonation_admin']);
    }

    public static function impersonate(int $userId, ?string $confirmedOwner = null): void
    {
        if ((int)(self::user()['user_type'] ?? 0) !== 2 || self::impersonating()) {
            http_response_code(403);
            throw new RuntimeException('เฉพาะ admin เท่านั้นที่สวมสิทธิ์ได้');
        }
        $db = Database::connect();
        try {
            $stmt = sqlsrv_query($db, 'SELECT dc_user_id,dc_emp_id,dc_cost_id,c_user_name,c_full_name,i_type_user FROM dbo.dc_user WHERE dc_user_id=? AND i_enable=1 AND i_delete=2', [$userId]);
            if (!$stmt) throw new RuntimeException('ตรวจสอบบัญชีผู้ใช้ไม่สำเร็จ');
            $target = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if (!$target) throw new RuntimeException('ไม่พบบัญชีผู้ใช้ที่เปิดใช้งาน');
            if ((int)$target['dc_user_id'] === (int)self::user()['user_id']) throw new RuntimeException('คุณเข้าสู่ระบบด้วยบัญชีนี้อยู่แล้ว');
            $admin = self::user();
            try { $instance = SingleLogin::start('EIS-DOCS', (string)$userId, null, $confirmedOwner); }
            catch (DuplicateLoginException $e) {
                $_SESSION['docs_impersonation_pending'] = ['kind'=>'login', 'uid'=>$userId, 'name'=>(string)$target['c_full_name'], 'owner'=>$e->owner, 'nonce'=>bin2hex(random_bytes(32)), 'expires'=>time()+120];
                throw $e;
            }
            Presence::revoke('EIS-DOCS', (string)$admin['user_id'], (string)($admin['login_at'] ?? ''));
            SingleLogin::release('EIS-DOCS', (string)$admin['user_id'], (string)($admin['login_instance'] ?? ''));
            session_regenerate_id(true);
            $_SESSION['impersonation_admin'] = $admin;
            unset($_SESSION['pin_sign_grants']);
            $_SESSION['auth'] = self::identity($target);
            $_SESSION['auth']['login_instance'] = $instance;
            unset($_SESSION['docs_impersonation_pending']);
            self::check();
            $_SESSION['app_csrf'] = bin2hex(random_bytes(32));
            error_log('Impersonation start: admin='.(int)$admin['user_id'].' target='.(int)$target['dc_user_id']);
        } finally { sqlsrv_close($db); }
    }

    public static function stopImpersonating(?string $confirmedOwner = null): void
    {
        if (!self::impersonating() || (int)($_SESSION['impersonation_admin']['user_type'] ?? 0) !== 2) {
            http_response_code(403);
            throw new RuntimeException('ไม่มีบัญชี admin สำหรับกลับเข้าสู่ระบบ');
        }
        $admin = $_SESSION['impersonation_admin'];
        $target = self::user();
        // Recheck the original administrator in case the account was disabled during impersonation.
        $db = Database::connect();
        try {
            $stmt = sqlsrv_query($db, 'SELECT dc_user_id,dc_emp_id,dc_cost_id,c_user_name,c_full_name,i_type_user FROM dbo.dc_user WHERE dc_user_id=? AND i_type_user=2 AND i_enable=1 AND i_delete=2', [(int)$admin['user_id']]);
            if (!$stmt) throw new RuntimeException('ตรวจสอบบัญชี admin ไม่สำเร็จ');
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($stmt);
            if (!$row) throw new RuntimeException('บัญชี admin ไม่สามารถใช้งานได้ กรุณาออกจากระบบ');
            try { $instance = SingleLogin::start('EIS-DOCS', (string)$admin['user_id'], $admin['login_instance'] ?? null, $confirmedOwner, (string)($admin['admin_operator'] ?? '')); }
            catch (DuplicateLoginException $e) {
                $_SESSION['docs_impersonation_pending'] = ['kind'=>'restore', 'uid'=>(int)$admin['user_id'], 'name'=>(string)$row['c_full_name'], 'owner'=>$e->owner, 'nonce'=>bin2hex(random_bytes(32)), 'expires'=>time()+120];
                throw $e;
            }
            Presence::revoke('EIS-DOCS', (string)$target['user_id'], (string)($target['login_at'] ?? ''));
            SingleLogin::release('EIS-DOCS', (string)$target['user_id'], (string)($target['login_instance'] ?? ''));
            session_regenerate_id(true);
            $_SESSION['auth'] = self::identity($row);
            if (isset($admin['admin_operator'])) $_SESSION['auth']['admin_operator'] = $admin['admin_operator'];
            $_SESSION['auth']['login_instance'] = $instance;
            unset($_SESSION['docs_impersonation_pending']);
            unset($_SESSION['impersonation_admin']);
            unset($_SESSION['pin_sign_grants']);
            $_SESSION['app_csrf'] = bin2hex(random_bytes(32));
            self::check();
            error_log('Impersonation stop: admin='.(int)$admin['user_id'].' target='.(int)$target['user_id']);
        } finally { sqlsrv_close($db); }
    }

    public static function logout(): void
    {
        $identity = $_SESSION['auth'] ?? null;
        if ($identity) Presence::revoke('EIS-DOCS', (string)$identity['user_id'], (string)($identity['login_at'] ?? ''));
        if ($identity) SingleLogin::release('EIS-DOCS', (string)$identity['user_id'], (string)($identity['login_instance'] ?? ''));
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
