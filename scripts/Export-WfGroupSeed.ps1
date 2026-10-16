param([string]$Server='localhost', [string]$OutputDirectory='D:\EIS-DOCS\database\production')
$ErrorActionPreference='Stop'
function SqlLiteral($Value) {
    if ($null -eq $Value -or $Value -is [DBNull]) { return 'NULL' }
    if ($Value -is [bool]) { return [string][int]$Value }
    if ($Value -is [string]) { return "N'"+$Value.Replace("'","''")+"'" }
    return [Convert]::ToString($Value,[Globalization.CultureInfo]::InvariantCulture)
}
$connection=New-Object System.Data.SqlClient.SqlConnection("Server=$Server;Database=EIS_DOCS;Integrated Security=True;Encrypt=True;TrustServerCertificate=True")
$connection.Open()
$transaction=$connection.BeginTransaction([System.Data.IsolationLevel]::Serializable)
try {
    $command=$connection.CreateCommand();$command.Transaction=$transaction
    $command.CommandText='SELECT c_name,visibility,owner_user_id,owner_cost_id FROM dbo.WF_USER_HDR ORDER BY c_name; SELECT user_id,c_name,dc_emp_id,review_action,sign_action,approve_action,wf_order FROM dbo.WF_USER_DTL ORDER BY c_name,wf_order,dc_emp_id;'
    $data=New-Object System.Data.DataSet
    $adapter=New-Object System.Data.SqlClient.SqlDataAdapter($command)
    [void]$adapter.Fill($data)
    $transaction.Commit()
} catch { $transaction.Rollback();throw } finally { $connection.Close() }
$lines=New-Object 'System.Collections.Generic.List[string]'
$lines.Add(@'
-- Exported signer groups only; no documents, passwords, or login accounts.
-- Existing group names are skipped as a whole (no overwrites or extra members).
USE [EIS_DOCS];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @Headers TABLE(c_name nvarchar(255) PRIMARY KEY, visibility varchar(20), owner_user_id nvarchar(100), owner_cost_id int);
DECLARE @Members TABLE(user_id nvarchar(100), c_name nvarchar(255), dc_emp_id bigint, review_action bit, sign_action bit, approve_action bit, wf_order int);
'@)
foreach($row in $data.Tables[0].Rows) {
    $values=@('c_name','visibility','owner_user_id','owner_cost_id') | ForEach-Object {SqlLiteral $row[$_]}
    $lines.Add('INSERT @Headers VALUES ('+($values -join ',')+');')
}
foreach($row in $data.Tables[1].Rows) {
    $values=@('user_id','c_name','dc_emp_id','review_action','sign_action','approve_action','wf_order') | ForEach-Object {SqlLiteral $row[$_]}
    $lines.Add('INSERT @Members VALUES ('+($values -join ',')+');')
}
$lines.Add(@'
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @NewGroups TABLE(c_name nvarchar(255) PRIMARY KEY);
 INSERT @NewGroups SELECT s.c_name FROM @Headers s
 WHERE NOT EXISTS(SELECT 1 FROM dbo.WF_USER_HDR h WITH(UPDLOCK,HOLDLOCK) WHERE h.c_name=s.c_name);
 -- User IDs must refer to the same employees in production. Do not silently remap names.
 IF EXISTS(SELECT 1 FROM @Members d JOIN @NewGroups n ON n.c_name=d.c_name
   LEFT JOIN NMU_DATACENTER.dbo.dc_user u ON CONVERT(nvarchar(100),u.dc_user_id)=d.user_id AND u.dc_emp_id=d.dc_emp_id
   LEFT JOIN NMU_DATACENTER.dbo.dc_emp e ON e.dc_emp_id=d.dc_emp_id
   WHERE u.dc_user_id IS NULL OR e.dc_emp_id IS NULL)
 BEGIN
   SELECT d.c_name,d.user_id,d.dc_emp_id AS missing_or_mismatched_employee FROM @Members d JOIN @NewGroups n ON n.c_name=d.c_name
   LEFT JOIN NMU_DATACENTER.dbo.dc_user u ON CONVERT(nvarchar(100),u.dc_user_id)=d.user_id AND u.dc_emp_id=d.dc_emp_id
   LEFT JOIN NMU_DATACENTER.dbo.dc_emp e ON e.dc_emp_id=d.dc_emp_id WHERE u.dc_user_id IS NULL OR e.dc_emp_id IS NULL;
   THROW 50010,'Seed users do not match NMU_DATACENTER. No groups were imported.',1;
 END;
 IF EXISTS(SELECT 1 FROM @Headers h JOIN @NewGroups n ON n.c_name=h.c_name
   WHERE h.owner_user_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM NMU_DATACENTER.dbo.dc_user u WHERE CONVERT(nvarchar(100),u.dc_user_id)=h.owner_user_id))
   THROW 50011,'Group owner does not exist in NMU_DATACENTER. No groups were imported.',1;
 INSERT dbo.WF_USER_HDR(c_name,visibility,owner_user_id,owner_cost_id)
 SELECT h.c_name,h.visibility,h.owner_user_id,h.owner_cost_id FROM @Headers h JOIN @NewGroups n ON n.c_name=h.c_name;
 INSERT dbo.WF_USER_DTL(user_id,c_name,dc_emp_id,review_action,sign_action,approve_action,wf_order)
 SELECT d.user_id,d.c_name,d.dc_emp_id,d.review_action,d.sign_action,d.approve_action,d.wf_order
 FROM @Members d JOIN @NewGroups n ON n.c_name=d.c_name;
 COMMIT;
 SELECT h.c_name,h.visibility,(SELECT COUNT(*) FROM @Members d WHERE d.c_name=h.c_name) AS exported_members,
 CASE WHEN n.c_name IS NULL THEN 'SKIPPED_EXISTING_GROUP' ELSE 'IMPORTED' END AS import_result
 FROM @Headers h LEFT JOIN @NewGroups n ON n.c_name=h.c_name ORDER BY h.c_name;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
GO
'@)
[void](New-Item -ItemType Directory -Force -Path $OutputDirectory)
[IO.File]::WriteAllText((Join-Path $OutputDirectory '03_WF_groups_seed.sql'),($lines -join "`r`n"),(New-Object Text.UTF8Encoding($true)))
$summary=foreach($row in $data.Tables[0].Rows) {
    [pscustomobject]@{GroupName=$row.c_name;Visibility=$row.visibility;OwnerUserId=$row.owner_user_id;OwnerCostId=$row.owner_cost_id;Members=@($data.Tables[1].Rows | Where-Object {$_.c_name -eq $row.c_name}).Count}
}
$summary | Export-Csv -NoTypeInformation -Encoding UTF8 -LiteralPath (Join-Path $OutputDirectory 'WF_groups_summary.csv')
Write-Output "Exported $($data.Tables[0].Rows.Count) groups and $($data.Tables[1].Rows.Count) memberships."
