<?php
declare(strict_types=1);

final class SqlBackup
{
    public static function errorMessage(array $errors): string
    {
        $codes = array_values(array_unique(array_map(static function ($error) { return (int)($error['code'] ?? 0); }, $errors)));
        $messages = implode(' ', array_column($errors, 'message'));
        $suffix = $codes ? ' (SQL ' . implode(', ', $codes) . ')' : '';
        if (array_intersect($codes, [262, 229, 916])) return 'บัญชี SQL ไม่มีสิทธิ์ BACKUP DATABASE ของฐานข้อมูลที่เลือก' . $suffix;
        if (preg_match('/(?:Operating system error|OS error)\s+5\b/i', $messages)) return 'SQL Server เขียนไฟล์ไม่ได้: OS error 5 ตรวจ NTFS/share ของบัญชี SQL service' . $suffix;
        if (preg_match('/(?:Operating system error|OS error)\s+3\b/i', $messages)) return 'SQL Server ไม่พบโฟลเดอร์: OS error 3 ตรวจ SQL path และ mapping กับโฟลเดอร์เว็บ' . $suffix;
        if (preg_match('/(?:Operating system error|OS error)\s+112\b/i', $messages)) return 'พื้นที่จัดเก็บ Backup ไม่เพียงพอ: OS error 112' . $suffix;
        return 'SQL Backup ไม่สำเร็จ ตรวจรายละเอียดใน PHP/SQL Server log' . $suffix;
    }

    public static function run($db, string $database, string $sqlPath): void
    {
        $previous = sqlsrv_get_config('WarningsReturnAsErrors');
        $statement = false;
        if (!sqlsrv_configure('WarningsReturnAsErrors', 0)) throw new RuntimeException('ตั้งค่ารับผล SQL Backup ไม่สำเร็จ');
        try {
            // BACKUP emits progress messages (4035/3014, SQLSTATE 01000).
            // Keep the prepared statement alive and drain every result before ZIP.
            $statement = sqlsrv_prepare($db, 'BACKUP DATABASE ' . SqlConsole::quote($database) . ' TO DISK = ? WITH COPY_ONLY, CHECKSUM', [$sqlPath], ['QueryTimeout'=>0]);
            if ($statement === false || !sqlsrv_execute($statement)) {
                $errors = sqlsrv_errors() ?: [];
                error_log('SQL backup: ' . json_encode($errors));
                throw new RuntimeException(self::errorMessage($errors));
            }
            do {
                $next = sqlsrv_next_result($statement);
                if ($next === false) {
                    $errors = sqlsrv_errors() ?: [];
                    error_log('SQL backup result: ' . json_encode($errors));
                    throw new RuntimeException(self::errorMessage($errors));
                }
            } while ($next === true);
        } finally {
            if ($statement !== false) sqlsrv_free_stmt($statement);
            sqlsrv_configure('WarningsReturnAsErrors', $previous);
        }
    }

    public static function settings(string $connection): array
    {
        if (!in_array($connection, ['local', 'production'], true)) throw new InvalidArgumentException('SQL connection ไม่ถูกต้อง');
        $file = dirname(__DIR__) . '/config/backup.' . $connection . '.php';
        $config = is_file($file) ? (array)require $file : [];
        $prefix = 'SQL_BACKUP_' . strtoupper($connection) . '_';
        $runtime = require dirname(__DIR__) . '/config/runtime.php';
        $legacy = $connection === $runtime['environment'];
        $sqlDir = rtrim((string)(getenv($prefix . 'SQL_DIR') ?: ($config['sql_directory'] ?? ($legacy ? getenv('SQL_BACKUP_SQL_DIR') : ''))), '/\\');
        $webDir = rtrim((string)(getenv($prefix . 'WEB_DIR') ?: ($config['web_directory'] ?? ($legacy ? getenv('SQL_BACKUP_WEB_DIR') : ''))), '/\\');
        if ($sqlDir === '' || $webDir === '') throw new RuntimeException('ยังไม่ได้ตั้งโฟลเดอร์ Backup สำหรับ ' . $connection . ' กรุณารัน Setup-SqlBackup.ps1 บนเครื่อง SQL และเว็บก่อน');
        foreach ([$sqlDir, $webDir] as $directory) {
            $normalized = str_replace('\\', '/', $directory);
            if (!preg_match('~^(?:[A-Za-z]:/|//[^/]+/[^/]+|/(?!/))~', $normalized) || preg_match('/[\x00-\x1f]/', $directory)) throw new RuntimeException('โฟลเดอร์ Backup ต้องเป็น absolute path หรือ UNC');
        }
        return ['sql_directory'=>$sqlDir, 'web_directory'=>$webDir];
    }

    public static function describe(string $connection): array
    {
        try { return ['configured'=>true] + self::settings($connection); }
        catch (Throwable $error) { return ['configured'=>false, 'error'=>$error->getMessage()]; }
    }

    public static function component(string $value): string
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $value) || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/i', $value)) throw new InvalidArgumentException('ชื่อโฟลเดอร์และ Backup ใช้ A-Z, a-z, 0-9, _ หรือ - สูงสุด 80 ตัวอักษร');
        return $value;
    }

    public static function plan(array $settings, string $database, string $connection, string $folder, string $name): array
    {
        self::component($connection);
        $defaultFolder = substr(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $database), '-'), 0, 60) ?: 'database';
        if (preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/i', $defaultFolder)) $defaultFolder = 'DB-' . $defaultFolder;
        $defaultName = strtoupper($defaultFolder) === 'EIS-DOCS' ? 'EIS-DOC' : $defaultFolder;
        $folder = trim($folder);
        if ($folder === '') $folder = 'backup-' . $defaultFolder;
        self::component($folder);
        $name = trim($name);
        if ($name === '') $name = $defaultName . '-' . date('Ymd-His');
        self::component($name);
        // Unique request directory preserves previous backups even when names repeat.
        $relative = $folder . '/' . $connection . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $base = $name;
        return ['relative'=>$relative, 'base'=>$base,
            'sql_path'=>rtrim($settings['sql_directory'], '/\\') . '/' . $relative . '/' . $base . '.bak',
            'web_directory'=>rtrim($settings['web_directory'], '/\\') . '/' . $relative,
            'web_root'=>$settings['web_directory']];
    }

    private static function normalized(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    public static function prepare(array $plan): void
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ยังไม่ได้เปิด extension zip กรุณาเปิด ZipArchive ก่อน Backup');
        $root = $plan['web_root'];
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('สร้างโฟลเดอร์ปลายทางไม่ได้ ตรวจสอบ share และสิทธิ์บัญชีเว็บ');
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) throw new RuntimeException('หาโฟลเดอร์ปลายทางไม่พบ');
        $public = realpath(dirname(__DIR__) . '/public');
        if ($public && (self::normalized($resolvedRoot) === self::normalized($public) || strpos(self::normalized($resolvedRoot), self::normalized($public) . '/') === 0)) throw new RuntimeException('โฟลเดอร์ Backup ต้องอยู่ภายนอก public');
        $directory = $root;
        foreach (explode('/', $plan['relative']) as $part) {
            self::component($part);
            $directory .= '/' . $part;
            if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('สร้างโฟลเดอร์ Backup ไม่สำเร็จ ตรวจสอบสิทธิ์เขียน');
            $resolved = realpath($directory);
            if (!$resolved || strpos(self::normalized($resolved), self::normalized($resolvedRoot) . '/') !== 0) throw new RuntimeException('โฟลเดอร์ Backup อยู่นอกปลายทางที่กำหนด');
        }
        // Windows/SMB directory permission metadata may differ from actual file access.
        // Test the operations needed for ZIP using a unique, exclusively created file.
        self::probe($directory);
    }

    public static function probe(string $directory): void
    {
        $path = rtrim($directory, '/\\') . '/.backup-probe-' . bin2hex(random_bytes(12));
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            error_log('SQL backup storage create probe: ' . json_encode(error_get_last(), JSON_UNESCAPED_UNICODE));
            throw new RuntimeException('บัญชี PHP สร้างไฟล์ใน Backup share ไม่ได้ ตรวจ NTFS/share และบัญชีที่ PHP ใช้จริง');
        }
        $failure = null;
        try {
            $marker = 'eis-backup-storage-check';
            if (fwrite($handle, $marker) !== strlen($marker) || !fflush($handle) || !rewind($handle) || fread($handle, strlen($marker)) !== $marker) {
                error_log('SQL backup storage read/write probe: ' . json_encode(error_get_last(), JSON_UNESCAPED_UNICODE));
                $failure = 'บัญชี PHP อ่านหรือเขียนไฟล์ Backup share ไม่สำเร็จ';
            }
        } finally {
            fclose($handle);
            if (!@unlink($path)) {
                error_log('SQL backup storage delete probe: ' . json_encode(error_get_last(), JSON_UNESCAPED_UNICODE));
                $failure = $failure ?: 'บัญชี PHP ลบไฟล์ชั่วคราวใน Backup share ไม่ได้ ต้องมีสิทธิ์ Modify';
            }
        }
        if ($failure !== null) throw new RuntimeException($failure);
    }

    public static function archive(string $backup, string $destination, string $entryName): int
    {
        clearstatcache(true, $backup);
        $source = is_file($backup) ? @fopen($backup, 'rb') : false;
        if ($source === false) throw new RuntimeException('SQL สำรองแล้ว แต่เว็บเปิดอ่าน .bak ไม่ได้ ตรวจ mapping และสิทธิ์ share');
        try { $sourceStat = fstat($source); } finally { fclose($source); }
        if (!$sourceStat || $sourceStat['size'] === 0) throw new RuntimeException('ไฟล์ .bak ว่างหรืออ่านขนาดไม่ได้ ตรวจ SQL Server log');
        if (file_exists($destination)) throw new RuntimeException('มีไฟล์ ZIP ชื่อนี้แล้ว');
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('สร้าง ZIP ไม่สำเร็จ ตรวจสิทธิ์และพื้นที่ว่าง');
        $added = $zip->addFile($backup, $entryName);
        if ($added) $added = $zip->setCompressionName($entryName, ZipArchive::CM_DEFLATE, 6);
        $closed = $zip->close();
        if (!$added || !$closed) {
            if (is_file($destination)) @unlink($destination);
            throw new RuntimeException('บีบอัด ZIP ไม่สำเร็จ ไฟล์ .bak ยังคงอยู่บนเซิร์ฟเวอร์');
        }
        clearstatcache(true, $destination);
        $size = filesize($destination);
        if (!$size) throw new RuntimeException('ZIP ว่างหรืออ่านไม่ได้ ไฟล์ .bak ยังคงอยู่');
        return (int)$size;
    }
}
