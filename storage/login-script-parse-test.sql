SET PARSEONLY ON;
GO
-- Run after 01 using a DBA account in SSMS. Edit the password below locally.
-- Existing login passwords are NOT changed. No sysadmin or db_owner grants.
USE [master];
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @Login sysname = N'eis_docs_app';
DECLARE @Password nvarchar(128) = N'REPLACE_WITH_A_UNIQUE_STRONG_PASSWORD';
IF DB_ID(N'EIS_DOCS') IS NULL THROW 50001, 'Run 01_EIS_DOCS_schema.sql first.', 1;
IF DB_ID(N'NMU_DATACENTER') IS NULL OR DB_ID(N'NMU_ERP') IS NULL
    THROW 50002, 'Restore the existing NMU_DATACENTER and NMU_ERP databases first. Do not create empty user/PR tables.', 1;
IF CONVERT(int, SERVERPROPERTY('IsIntegratedSecurityOnly')) = 1
    THROW 50003, 'Enable SQL Server and Windows Authentication mode and restart SQL Server before using SQL Login.', 1;
IF SUSER_ID(@Login) IS NULL AND @Password = N'REPLACE_WITH_A_UNIQUE_STRONG_PASSWORD'
    THROW 50004, 'Set a unique password in @Password before running this script.', 1;
DECLARE @Objects TABLE(db sysname, tbl sysname, writable bit);
INSERT @Objects VALUES
 (N'EIS_DOCS',N'WF_USER_HDR',1),(N'EIS_DOCS',N'WF_USER_DTL',1),
 (N'EIS_DOCS',N'DOCS_HDR',1),(N'EIS_DOCS',N'DOCS_DTL',1),
 (N'EIS_DOCS',N'DOCS_SET',1),(N'EIS_DOCS',N'DOCS_NOTIFICATION',1),
 (N'NMU_DATACENTER',N'dc_user',0),(N'NMU_DATACENTER',N'dc_emp',0),
 (N'NMU_ERP',N'sp_tor',0),(N'NMU_ERP',N'sp_check_period_hdr',0),
 (N'NMU_ERP',N'sp_check_period_dtl',0),(N'NMU_ERP',N'sp_tor_contract',0);
DECLARE @Db sysname, @Table sysname, @Write bit, @Sql nvarchar(max);
DECLARE objects_cursor CURSOR LOCAL FAST_FORWARD FOR SELECT db,tbl,writable FROM @Objects;
OPEN objects_cursor;
FETCH NEXT FROM objects_cursor INTO @Db,@Table,@Write;
WHILE @@FETCH_STATUS=0
BEGIN
    IF OBJECT_ID(QUOTENAME(@Db)+N'.dbo.'+QUOTENAME(@Table)) IS NULL
    BEGIN
        DECLARE @Error nvarchar(2048)=N'Missing required table: '+@Db+N'.dbo.'+@Table;
        THROW 50005,@Error,1;
    END;
    FETCH NEXT FROM objects_cursor INTO @Db,@Table,@Write;
END;
CLOSE objects_cursor;
BEGIN TRY
 BEGIN TRANSACTION;
 IF SUSER_ID(@Login) IS NULL
 BEGIN
    SET @Sql=N'CREATE LOGIN '+QUOTENAME(@Login)+N' WITH PASSWORD=N'''+REPLACE(@Password,'''','''''')+N''', CHECK_POLICY=ON, DEFAULT_DATABASE=[EIS_DOCS];';
    EXEC sys.sp_executesql @Sql;
 END;
 OPEN objects_cursor;
 FETCH NEXT FROM objects_cursor INTO @Db,@Table,@Write;
 WHILE @@FETCH_STATUS=0
 BEGIN
    SET @Sql=N'USE '+QUOTENAME(@Db)+N'; IF DATABASE_PRINCIPAL_ID(@Name) IS NULL CREATE USER '+QUOTENAME(@Login)+N' FOR LOGIN '+QUOTENAME(@Login)+N'; ELSE ALTER USER '+QUOTENAME(@Login)+N' WITH LOGIN='+QUOTENAME(@Login)+N'; GRANT CONNECT TO '+QUOTENAME(@Login)+N'; GRANT SELECT'+CASE WHEN @Write=1 THEN N', INSERT, UPDATE, DELETE' ELSE N'' END+N' ON OBJECT::dbo.'+QUOTENAME(@Table)+N' TO '+QUOTENAME(@Login)+N';';
    EXEC sys.sp_executesql @Sql,N'@Name sysname',@Name=@Login;
    FETCH NEXT FROM objects_cursor INTO @Db,@Table,@Write;
 END;
 CLOSE objects_cursor;
 DEALLOCATE objects_cursor;
 COMMIT;
 PRINT 'SQL Login and database permissions configured. Use the same credentials in PHP and Tomcat.';
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
GO

SET PARSEONLY OFF;
GO