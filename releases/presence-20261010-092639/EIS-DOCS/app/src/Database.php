<?php

declare(strict_types=1);

final class Database
{
    /** @return resource */
    public static function connect(string $databaseKey = 'datacenter')
    {
        if (!function_exists('sqlsrv_connect')) {
            throw new RuntimeException('ยังไม่ได้ติดตั้ง Microsoft Drivers for PHP for SQL Server');
        }

        $config = require dirname(__DIR__) . '/config/database.php';
        $useWindowsAuthentication = trim((string) $config['user']) === '';
        $isLocalServer = in_array(strtolower((string) $config['host']), ['localhost', '127.0.0.1', '.'], true);
        // Windows Authentication บนเครื่องเดียวกันใช้ local SQL protocol ได้เสถียรกว่า TCP/TLS
        $server = $useWindowsAuthentication && $isLocalServer
            ? (string) $config['host']
            : $config['host'] . ',' . $config['port'];
        $database = $config['databases'][$databaseKey] ?? $databaseKey;
        $connectionOptions = [
            'Database' => $database,
            'CharacterSet' => 'UTF-8',
            'ReturnDatesAsStrings' => true,
        ];
        $connectionOptions['Encrypt'] = $config['encrypt'];
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
