function Invoke-SqlBackupStorageProbe {
    [CmdletBinding()]
    param([Parameter(Mandatory=$true)][string]$Directory,[PSCredential]$Credential)
    $token = [IntPtr]::Zero
    $context = $null
    $buffer = [IntPtr]::Zero
    try {
        if ($Credential) {
            if (-not ('EisBackupStorageLogon' -as [type])) {
                Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class EisBackupStorageLogon {
  [DllImport("advapi32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
  public static extern bool LogonUser(string user, string domain, IntPtr password, int type, int provider, out IntPtr token);
  [DllImport("kernel32.dll", SetLastError=true)]
  public static extern bool CloseHandle(IntPtr token);
}
'@
            }
            $network = $Credential.GetNetworkCredential()
            $buffer = [Runtime.InteropServices.Marshal]::SecureStringToGlobalAllocUnicode($Credential.Password)
            # Logon with local credentials retained for outbound SMB; verify before changing IIS.
            if (-not [EisBackupStorageLogon]::LogonUser($network.UserName,$network.Domain,$buffer,8,0,[ref]$token)) {
                $code = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
                throw "Cannot verify backup account logon (Win32 $code). Check account/password and local policy."
            }
            $context = [Security.Principal.WindowsIdentity]::Impersonate($token)
        }
        if (-not (Test-Path -LiteralPath $Directory)) { New-Item -ItemType Directory -Path $Directory -Force | Out-Null }
        $probe = Join-Path $Directory ('.setup-' + [guid]::NewGuid().ToString('N') + '.tmp')
        try {
            [IO.File]::WriteAllText($probe,'backup-storage-test')
            if ([IO.File]::ReadAllText($probe) -ne 'backup-storage-test') { throw 'Storage verification failed.' }
        } finally { if (Test-Path -LiteralPath $probe) { Remove-Item -LiteralPath $probe } }
    } finally {
        if ($context) { $context.Undo();$context.Dispose() }
        if ($token -ne [IntPtr]::Zero) { [void][EisBackupStorageLogon]::CloseHandle($token) }
        if ($buffer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeGlobalAllocUnicode($buffer) }
    }
}
