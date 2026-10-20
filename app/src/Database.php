<?php

declare(strict_types=1);

final class Database
{
    public static function consoleConnectionError(array $errors, string $server, string $user): string
    {
        $codes = array_map(static fn($e)=>(int)($e['code'] ?? 0), $errors);
        $states = array_column($errors, 'SQLSTATE');
        $messages = implode(' ', array_column($errors, 'message'));
        $target = 'SQL Server ' . $server;
        if (in_array(-49,$codes,true) || in_array('IM002',$states,true)) return $target . ': ไม่พบ ODBC Driver ที่ตั้งไว้ กรุณาตรวจไดรเวอร์ของ PHP บนเครื่องเว็บ';
        if (in_array(18456,$codes,true)) return $target . ': SQL ปฏิเสธการล็อกอินบัญชี ' . ($user ?: 'Windows Authentication') . ' (18456) ตรวจรหัสผ่าน สถานะบัญชี และ SQL Authentication';
        if (in_array(4060,$codes,true) || in_array(916,$codes,true)) return $target . ': บัญชีไม่มีสิทธิ์เปิดฐานข้อมูลที่เลือก (4060/916)';
        if (preg_match('/SSL|TLS|certificate|Encryption not supported/i',$messages)) return $target . ': เชื่อมต่อ TLS ไม่สำเร็จ ตรวจ certificate และ ODBC Driver บนเครื่องเว็บ';
        if (array_intersect($codes,[10065,10060,10061,53,258]) || in_array('HYT00',$states,true) || in_array('08001',$states,true)) return $target . ': เครื่องเว็บเชื่อมต่อเครือข่ายไป SQL ไม่ได้หรือหมดเวลา ตรวจ VPN / เส้นทางเครือข่าย / Firewall และ TCP port ของ SQL Server ยังยืนยันบัญชีหรือรายชื่อฐานข้อมูลไม่ได้';
        return $target . ': เชื่อมต่อไม่สำเร็จ กรุณาตรวจ SQL Server log และ PHP error log';
    }
    public static function serverName(string $host, int $port, bool $windowsAuthentication): string
    {
        $host = trim($host);
        if ($host === '') throw new InvalidArgumentException('SQL host is required');
        $local = in_array(strtolower($host), ['localhost', '127.0.0.1', '.'], true);
        if ($local && $windowsAuthentication) return $host;
        // SQL authentication uses an explicit TCP loopback endpoint, not the dot alias.
        if ($local) $host = 'tcp:127.0.0.1';
        // Preserve explicit protocol/port and named-instance discovery settings.
        if (str_contains($host, ',') || str_contains($host, '\\') || str_starts_with(strtolower($host), 'np:') || str_starts_with(strtolower($host), 'lpc:')) return $host;
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Invalid SQL port');
        return $host . ',' . $port;
    }

    /** @return resource */
    public static function connect(string $databaseKey = 'datacenter', bool $useDatabaseName = false, bool $sqlConsole = false, ?string $consoleEnvironment = null)
    {
        if (!function_exists('sqlsrv_connect')) {
            throw new RuntimeException('ยังไม่ได้ติดตั้ง Microsoft Drivers for PHP for SQL Server');
        }

        $config = require dirname(__DIR__) . '/config/database.php';
        if ($sqlConsole) {
            $runtime = require dirname(__DIR__) . '/config/runtime.php';
            $consoleEnvironment ??= $runtime['environment'];
            if (!in_array($consoleEnvironment, ['local','production'], true)) throw new InvalidArgumentException('Invalid SQL connection');
            // Explicit console selection must use that profile, not the web host's DB_HOST.
            { // Console profiles are isolated from the application's DB_HOST/DB_PORT overrides.
                $profile = require dirname(__DIR__) . '/config/profiles/' . $consoleEnvironment . '.php';
                $credentials = is_file($profile['credentials_file']) ? require $profile['credentials_file'] : [];
                $config = array_replace($config, $profile['database'], ['user'=>$credentials['user'] ?? '', 'password'=>$credentials['password'] ?? '']);
            }
            $consoleFile = dirname(__DIR__) . '/config/sql-console.' . $consoleEnvironment . '.local.php';
            if (is_file($consoleFile)) {
                $console = require $consoleFile;
                if (($console['enabled'] ?? true) === true) {
                    $config = array_replace($config, array_intersect_key($console, array_flip(['host','port','user','password','encrypt','trust_server_certificate','driver'])));
                }
            }
        }
        $useWindowsAuthentication = trim((string) $config['user']) === '';
        $server = self::serverName((string)$config['host'], (int)$config['port'], $useWindowsAuthentication);
        $database = $useDatabaseName ? $databaseKey : ($config['databases'][$databaseKey] ?? $databaseKey);
        $connectionOptions = [
            'Database' => $database,
            'CharacterSet' => 'UTF-8',
            'ReturnDatesAsStrings' => true,
        ];
        $connectionOptions['Encrypt'] = $config['encrypt'];
        if ($sqlConsole) $connectionOptions['LoginTimeout'] = 8;
        if (!empty($config['driver'])) $connectionOptions['Driver'] = $config['driver'];
        $connectionOptions['TrustServerCertificate'] = $config['trust_server_certificate'];
        // ถ้าไม่ได้กำหนด SQL Login ให้ใช้ Windows Authentication ของ IIS App Pool
        // การส่ง UID/PWD เป็นค่าว่างทำให้ SQL Server ปฏิเสธการเชื่อมต่อ
        if (!$useWindowsAuthentication) {
            $connectionOptions['UID'] = $config['user'];
            $connectionOptions['PWD'] = $config['password'];
        }
        $connection = sqlsrv_connect($server, $connectionOptions);

        if ($connection === false) {
            $errors = sqlsrv_errors() ?: [];
            error_log('SQL Server connection failed: ' . json_encode($errors));
            if ($sqlConsole) throw new RuntimeException(self::consoleConnectionError($errors, $server, (string)$config['user']));
            foreach ($errors as $error) {
                if ((int) ($error['code'] ?? 0) === -49 || ($error['SQLSTATE'] ?? '') === 'IM002') {
                    throw new RuntimeException('เซิร์ฟเวอร์ไม่พบ ODBC Driver for SQL Server ที่รองรับ กรุณาให้ผู้ดูแลติดตั้งไดรเวอร์ให้ตรงกับ PHP บนเครื่องเซิร์ฟเวอร์');
                }
            }
            throw new RuntimeException('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
        }

        return $connection;
    }
}
