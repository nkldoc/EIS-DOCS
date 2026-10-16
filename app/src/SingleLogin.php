<?php
declare(strict_types=1);

final class DuplicateLoginException extends RuntimeException
{
    public function __construct(public readonly string $owner)
    {
        parent::__construct('บัญชีนี้มีเซสชันใช้งานอยู่ ต้องการเข้าสู่ระบบแทนที่เซสชันเดิมหรือไม่?');
    }
}

final class SingleLogin
{
    public static function operatorName(string $name): string
    {
        $name = strtolower(trim($name));
        // The registry already separates systems; keep the base operator stable.
        $name = preg_replace('/-(?:supplies|eis-docs)$/D', '', $name);
        if (!preg_match('/^admin-[a-z0-9][a-z0-9_-]{1,31}$/D', $name)) throw new RuntimeException('กรุณากรอกชื่อผู้ดูแล เช่น admin-eak หรือ admin-moo (ภาษาอังกฤษ ตัวเลข ขีดกลาง)');
        return $name;
    }
    public static function operator(string $system, string $uid): string
    {
        $identity = $system === 'EIS-DOCS' ? ($_SESSION['auth'] ?? []) : ($_SESSION ?? []);
        $type = $system === 'EIS-DOCS' ? ($identity['user_type'] ?? 0) : ($identity['i_type_user'] ?? 0);
        if ((string)($identity['user_id'] ?? '') !== $uid || (int)$type !== 2 || isset($_SESSION['impersonation_admin']) || isset($_SESSION['impersonation_admin_center_id'])) return '';
        return isset($identity['admin_operator']) ? self::operatorName((string)$identity['admin_operator']) : '';
    }
    public static function scoped(string $uid, string $operator): string
    {
        return $operator === '' ? $uid : $uid . '@' . self::operatorName($operator);
    }
    public static function registryPath(string $system, string $uid): string
    {
        if (!in_array($system, ['EIS-DOCS', 'SUPPLIES'], true) || $uid === '') throw new InvalidArgumentException('Invalid login identity');
        return Presence::directory() . '/logins/' . hash('sha256', $system . '|' . $uid) . '.json';
    }
    private static function transaction(string $system, string $uid, callable $fn): mixed
    {
        $file = self::registryPath($system, $uid);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0770, true) && !is_dir(dirname($file))) throw new RuntimeException('ไม่สามารถเตรียมทะเบียนเซสชันได้');
        $handle = fopen($file, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('ไม่สามารถตรวจสอบเซสชันได้');
        try {
            $raw = stream_get_contents($handle);
            $record = $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            [$result, $updated] = $fn($record);
            $json = json_encode($updated, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('ไม่สามารถบันทึกทะเบียนเซสชันได้');
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    private static function revoked(string $key, string $reason = 'login_replaced'): void
    {
        if ($key === '') return;
        $dir = Presence::directory() . '/revoked';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('ไม่สามารถเพิกถอนเซสชันได้');
        if (file_put_contents($dir . '/' . $key, json_encode(['reason'=>$reason, 'at_ms'=>(int)floor(microtime(true)*1000)]), LOCK_EX) === false) throw new RuntimeException('ไม่สามารถเพิกถอนเซสชันได้');
    }
    // Only a verified login flow calls start. The expected owner comes from a
    // short-lived server-side pending proof, never from an untrusted override flag.
    public static function start(string $system, string $uid, ?string $current = null, ?string $confirmedOwner = null, string $operator = ''): string
    {
        $uid = self::scoped($uid, $operator);
        $instance = $current ?: bin2hex(random_bytes(32));
        if (!Presence::enabled()) return $instance;
        return self::transaction($system, $uid, static function ($record) use ($instance, $confirmedOwner) {
            if ($record && $record['expires'] > time() && $record['instance'] !== $instance) {
                if ($confirmedOwner === null || !hash_equals($record['instance'], $confirmedOwner)) throw new DuplicateLoginException($record['instance']);
                self::revoked((string)($record['presence_key'] ?? ''));
            }
            return [$instance, ['instance'=>$instance, 'expires'=>time()+30, 'presence_key'=>'']];
        });
    }
    public static function touch(string $system, string $uid, string &$instance, string $login, int $ttl): bool
    {
        if (!Presence::enabled()) return true;
        $key = Presence::sessionKey($system, $uid, $login);
        $uid = self::scoped($uid, self::operator($system, $uid));
        if (is_file(Presence::directory() . '/revoked/' . $key)) return false;
        if ($instance === '') $instance = bin2hex(random_bytes(32));
        return self::transaction($system, $uid, static function ($record) use ($instance, $key, $ttl) {
            // A displaced login must never reclaim ownership merely because the
            // new owner has logged out or its lease has expired.
            if ($record && $record['instance'] !== $instance) {
                self::revoked($key);
                return [false, $record];
            }
            return [true, ['instance'=>$instance, 'expires'=>time()+max(1, $ttl), 'presence_key'=>$key]];
        });
    }
    public static function release(string $system, string $uid, string $instance): void
    {
        if (!Presence::enabled() || $uid === '' || $instance === '') return;
        $uid = self::scoped($uid, self::operator($system, $uid));
        self::transaction($system, $uid, static function ($record) use ($instance) {
            if ($record && $record['instance'] === $instance) $record['expires'] = 0;
            return [null, $record];
        });
    }
}
