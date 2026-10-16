function Get-SqlBackupPhpSessionDirectory {
    param([Parameter(Mandatory=$true)][string]$PhpExecutable)
    $process=New-Object Diagnostics.Process
    $process.StartInfo.FileName=$PhpExecutable
    $process.StartInfo.Arguments='-r "echo json_encode(array(''session''=>session_save_path()));"'
    $process.StartInfo.UseShellExecute=$false
    $process.StartInfo.CreateNoWindow=$true
    $process.StartInfo.RedirectStandardOutput=$true
    $process.StartInfo.RedirectStandardError=$true
    try {
        if (!$process.Start()) { throw 'Cannot start PHP to read the session directory.' }
        $stdout=$process.StandardOutput.ReadToEndAsync()
        $stderr=$process.StandardError.ReadToEndAsync()
        if (!$process.WaitForExit(15000)) { $process.Kill();throw 'PHP session-path check timed out. Specify SessionDirectory.' }
        $output=$stdout.GetAwaiter().GetResult()
        $diagnostic=$stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { throw 'PHP session-path check failed. Check php.ini or specify SessionDirectory.' }
        try { $data=$output | ConvertFrom-Json -ErrorAction Stop } catch { throw 'PHP returned an invalid session-path response. Check php.ini or specify SessionDirectory.' }
        if ($null -eq $data.session) { throw 'PHP did not return session.save_path.' }
        if ($diagnostic) { Write-Warning 'PHP startup emitted a warning; session path was read successfully. Check duplicate extensions in php.ini separately.' }
        return (([string]$data.session -split ';')[-1]).Trim()
    } finally { $process.Dispose() }
}
