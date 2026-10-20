<?php
declare(strict_types=1);
final class SqlRestore
{
    public static function run($db, string $database, array $upload, bool $replace, bool $close): void
    {
        if (in_array(strtolower($database), ['master','model','msdb','tempdb'], true)) throw new RuntimeException('System database restore is disabled');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('ZIP upload failed; check upload_max_filesize and post_max_size');
        if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive is required');
        $plan = SqlBackup::plan(SqlBackup::settings('local'), $database, 'local', 'restore-upload', bin2hex(random_bytes(12)));
        SqlBackup::prepare($plan);
        $path = $plan['web_directory'].'/'.$plan['base'].'.bak';
        $zip = new ZipArchive(); $stream = false; $output = false; $single = false;
        $previous = sqlsrv_get_config('WarningsReturnAsErrors');
        try {
            if ($zip->open($upload['tmp_name']) !== true) throw new RuntimeException('Invalid ZIP');
            $index = null;
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (strtolower(pathinfo($stat['name'], PATHINFO_EXTENSION)) !== 'bak') continue;
                if ($index !== null) throw new RuntimeException('ZIP must contain exactly one .bak');
                if ($stat['size'] > 100 * 1024 * 1024 * 1024 || $stat['size'] > disk_free_space($plan['web_directory'])) throw new RuntimeException('Insufficient restore storage or backup exceeds 100 GB');
                $index = $i;
            }
            if ($index === null) throw new RuntimeException('ZIP contains no .bak');
            $stream = $zip->getStream($zip->getNameIndex($index)); $output = fopen($path, 'xb');
            if (!$stream || !$output) throw new RuntimeException('Cannot extract backup');
            $bytes = stream_copy_to_stream($stream, $output); fclose($output); $output = false;
            if ($bytes !== $zip->statIndex($index)['size']) throw new RuntimeException('Incomplete backup extraction');
            fclose($stream); $stream = false; $zip->close();
            sqlsrv_configure('WarningsReturnAsErrors', 0);
            self::execute($db, 'RESTORE VERIFYONLY FROM DISK = ? WITH CHECKSUM', [$plan['sql_path']]);
            $statement = self::execute($db, 'RESTORE FILELISTONLY FROM DISK = ?', [$plan['sql_path']], false);
            $files = []; while ($row = sqlsrv_fetch_array($statement, SQLSRV_FETCH_ASSOC)) $files[] = $row; sqlsrv_free_stmt($statement);
            if (!$files) throw new RuntimeException('No backup files found');
            // Reuse destination paths, never the original server paths in the backup.
            $destination = consoleRows($db, 'SELECT type,physical_name FROM sys.master_files WHERE database_id=DB_ID(?) ORDER BY file_id', [$database]);
            $dirs = []; foreach ($destination as $file) $dirs[(int)$file['type']] = dirname(str_replace('\\','/', $file['physical_name']));
            $moves = []; $params = [$plan['sql_path']];
            foreach ($files as $i => $file) {
                if (!in_array($file['Type'], ['D','L'], true)) throw new RuntimeException('Only data and log backups are supported');
                $type = $file['Type'] === 'L' ? 1 : 0;
                if (!isset($dirs[$type])) throw new RuntimeException('Destination database needs data and log paths');
                $target = $dirs[$type].'/restore-'.substr(hash('sha256',$database),0,16).'-'.$i.($type ? '.ldf' : '.mdf');
                $moves[] = 'MOVE ? TO ?'; $params[] = $file['LogicalName']; $params[] = $target;
            }
            $name = SqlConsole::quote($database);
            if ($close) { self::execute($db, 'ALTER DATABASE '.$name.' SET SINGLE_USER WITH ROLLBACK IMMEDIATE'); $single = true; }
            self::execute($db, 'RESTORE DATABASE '.$name.' FROM DISK = ? WITH RECOVERY, '.implode(', ', $moves).($replace ? ', REPLACE' : ''), $params);
        } finally {
            if ($single) { try { self::execute($db, 'ALTER DATABASE '.SqlConsole::quote($database).' SET MULTI_USER'); } catch (Throwable $e) { error_log('Restore MULTI_USER: '.$e->getMessage()); } }
            sqlsrv_configure('WarningsReturnAsErrors', $previous);
            if (is_resource($output)) fclose($output); if (is_resource($stream)) fclose($stream);
            if (is_file($path)) unlink($path);
        }
    }
    private static function execute($db, string $sql, array $params = [], bool $drain = true) {
        $s = sqlsrv_query($db, $sql, $params, ['QueryTimeout'=>0]);
        if (!$s) { error_log(json_encode(sqlsrv_errors())); throw new RuntimeException('Restore SQL failed; see server log'); }
        if (!$drain) return $s;
        try { do { $next = sqlsrv_next_result($s); if ($next === false) { error_log(json_encode(sqlsrv_errors())); throw new RuntimeException('Restore failed; see server log'); } } while ($next === true); }
        finally { sqlsrv_free_stmt($s); }
        return true;
    }
}
