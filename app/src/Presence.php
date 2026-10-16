<?php
declare(strict_types=1);

final class Presence
{
    private static function config(): array
    {
        $file = getenv('EIS_PRESENCE_CONFIG') ?: __DIR__ . '/presence.config.php';
        return is_file($file) ? (array)require $file : [];
    }
    private static function host(): string
    {
        return strtolower((string)(parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''), PHP_URL_HOST) ?: ''));
    }
    public static function directory(): string
    {
        $configured = getenv('EIS_DOCS_PRESENCE_DIR') ?: (self::config()['directory'] ?? '');
        if ($configured !== '') return $configured;
        if (in_array(self::host(), ['eis.nmu.ac.th', 'doc-eis.nmu.ac.th'], true)) throw new RuntimeException('Configure the shared private presence directory before enabling production');
        return 'D:/EIS-DOCS/storage/presence';
    }
    public static function enabled(): bool
    {
        $config = self::config();
        if (($config['enabled'] ?? true) === false) return false;
        $host = self::host();
        if (in_array($host, ['dev-eis.nmu.ac.th', 'localhost', '127.0.0.1'], true)) return true;
        return in_array($host, ['eis.nmu.ac.th', 'doc-eis.nmu.ac.th'], true)
            && (bool)(getenv('EIS_DOCS_PRESENCE_DIR') ?: ($config['directory'] ?? ''));
    }
    public static function sessionKey(string $system, string $uid, string $login): string
    {
        return hash('sha256', $system . '|' . session_id() . '|' . $uid . '|' . $login);
    }
    public static function revoke(string $system, string $uid, string $login): void
    {
        if (!self::enabled() || $uid === '' || session_id() === '') return;
        $dir = self::directory() . '/revoked';
        if (is_dir($dir)) file_put_contents($dir . '/' . self::sessionKey($system, $uid, $login), (string)time(), LOCK_EX);
    }
    public static function token(string $system, string $uid, string $name, string $department, string $login, bool $admin = false): array
    {
        require_once __DIR__ . '/SingleLogin.php';
        if (!self::enabled() || !in_array($system, ['EIS-DOCS', 'SUPPLIES'], true)) throw new RuntimeException('Presence is not enabled for this deployment');
        $key = self::sessionKey($system, $uid, $login);
        $operator = SingleLogin::operator($system, $uid);
        if ($operator !== '') { $uid = SingleLogin::scoped($uid, $operator); $name = $operator . ($system === 'SUPPLIES' ? '-supplies' : '-EIS-DOCS'); }
        if (is_file(self::directory() . '/revoked/' . $key)) throw new UnexpectedValueException('Session revoked');
        $file = self::directory() . '/' . strtolower($system) . '.secret';
        if (!is_readable($file)) throw new RuntimeException('Presence service is not configured');
        $encoded = rtrim(strtr(base64_encode(json_encode(['system'=>$system, 'uid'=>$uid, 'name'=>$name, 'department'=>$department, 'admin'=>$system === 'EIS-DOCS' && $admin, 'session'=>$key, 'exp'=>time()+60], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $url = getenv('EIS_PRESENCE_WS_URL') ?: (self::config()['websocket_url'] ?? '');
        if ($url === '') $url = in_array(self::host(), ['eis.nmu.ac.th', 'doc-eis.nmu.ac.th'], true)
            ? 'wss://doc-eis.nmu.ac.th/eis-docs/presence' : 'wss://dev-eis.nmu.ac.th:4003/presence';
        return ['token'=>$encoded . '.' . hash_hmac('sha256', $encoded, trim(file_get_contents($file))), 'url'=>$url];
    }
    public static function script(string $system = 'EIS-DOCS'): string
    {
        if (!self::enabled()) return '';
        $url = $system === 'SUPPLIES' ? '/supplies/access/presence-token.php' : '/eis-docs/docMain/presence-token.php';
        $loginUrl = $system === 'SUPPLIES' ? '/NMU_permission/' : '/eis-docs/login';
        $client = $system === 'SUPPLIES' ? '/supplies/assets/js/presence-client.js' : '/eis-docs/assets/js/presence-client.js';
        $assets = $system === 'SUPPLIES' ? '/supplies/assets' : '/eis-docs/assets';
        return '<link rel="stylesheet" href="' . $assets . '/css/presence-chat.css?v=20261015-chat-dock-v2"><script>window.EISPresenceConfig={tokenUrl:' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ',loginUrl:' . json_encode($loginUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '};</script><script src="' . $client . '?v=20261014-connection-log"></script><script src="' . $assets . '/js/presence-image-viewer.js?v=20261010-read"></script><script src="' . $assets . '/js/presence-chat.js?v=20261015-chat-dock-v2"></script>';
    }
}
