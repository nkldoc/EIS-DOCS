<?php
declare(strict_types=1);
require_once __DIR__ . '/Presence.php';
final class NMUEISAdminIdentity
{
    public static function name(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/-(?:procure|nmu-eis|supplies|eis-docs)$/D', '', $name);
        if (!preg_match('/^admin-[a-z0-9][a-z0-9_-]{1,31}$/D', $name)) throw new InvalidArgumentException('กรุณาระบุชื่อ เช่น admin-art หรือ admin-eak');
        return $name;
    }
    public static function operator(): string
    {
        if (empty($_SESSION['user_id']) || (int)($_SESSION['i_type_user'] ?? 0) !== 2 || isset($_SESSION['impersonation_admin_center_id'])) return '';
        $name = (string)($_SESSION['admin_operator'] ?? '');
        if ($name === '') return '';
        try { return self::name($name); } catch (InvalidArgumentException $e) { return ''; }
    }
    public static function needsName(): bool
    {
        return !empty($_SESSION['user_id']) && (int)($_SESSION['i_type_user'] ?? 0) === 2 && !isset($_SESSION['impersonation_admin_center_id']) && self::operator() === '';
    }
    public static function forLogin(array $row): string
    {
        return (string)($_SESSION['user_id'] ?? '') === (string)$row['dc_user_id'] && (int)($row['i_type_user'] ?? 0) === 2 ? self::operator() : '';
    }
    public static function apply(string $name): void
    {
        if (empty($_SESSION['user_id']) || (int)($_SESSION['i_type_user'] ?? 0) !== 2 || isset($_SESSION['impersonation_admin_center_id'])) throw new RuntimeException('เฉพาะผู้ดูแลระบบที่เข้าสู่ระบบแล้ว');
        $name = self::name($name);
        if (self::operator() === $name) return;
        require_once __DIR__ . '/../../access/presence_common.php';
        presence_revoke();
        NMUEISMessenger::revoke('NMU-EIS', (string)$_SESSION['user_id'], (string)($_SESSION['last_login'] ?? ''));
        $_SESSION['admin_operator'] = $name;
    }
}
