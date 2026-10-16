-- Run on the PRODUCTION SQL Server as DBA. Existing PIN hashes and requests are preserved.
-- Application principal: eis_docs_app. Replace this principal if production uses another login.
USE EIS_DOCS;
GO
IF OBJECT_ID('dbo.DOCS_USER_PIN','U') IS NULL
CREATE TABLE dbo.DOCS_USER_PIN (
 user_id bigint NOT NULL PRIMARY KEY,
 pin_hash varchar(255) NOT NULL,
 failed_attempts int NOT NULL DEFAULT 0,
 locked_until datetime2 NULL,
 updated_at datetime2 NOT NULL DEFAULT SYSDATETIME()
);
GO
GRANT SELECT,INSERT,UPDATE ON dbo.DOCS_USER_PIN TO eis_docs_app;
GO

USE EIS_DOCS;
GO
IF COL_LENGTH('dbo.DOCS_USER_PIN','reset_allowed') IS NULL
 ALTER TABLE dbo.DOCS_USER_PIN ADD reset_allowed bit NOT NULL DEFAULT 0;
IF COL_LENGTH('dbo.DOCS_USER_PIN','pin_version') IS NULL
 ALTER TABLE dbo.DOCS_USER_PIN ADD pin_version int NOT NULL DEFAULT 1;
GO
IF OBJECT_ID('dbo.DOCS_PIN_RESET_REQUEST','U') IS NULL
BEGIN
 CREATE TABLE dbo.DOCS_PIN_RESET_REQUEST (
  request_id bigint IDENTITY PRIMARY KEY,
  user_id bigint NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','APPROVED','CONSUMED')),
  requested_at datetime2 NOT NULL DEFAULT SYSDATETIME(),
  approved_by bigint NULL,
  approved_at datetime2 NULL,
  consumed_at datetime2 NULL
 );
END;
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.DOCS_PIN_RESET_REQUEST') AND name='IX_DOCS_PIN_RESET_user_status')
 CREATE INDEX IX_DOCS_PIN_RESET_user_status ON dbo.DOCS_PIN_RESET_REQUEST(user_id,status);
GO
GRANT SELECT,INSERT,UPDATE ON dbo.DOCS_PIN_RESET_REQUEST TO eis_docs_app;
GO

USE EIS_DOCS;
GO
ALTER TABLE dbo.DOCS_NOTIFICATION ALTER COLUMN docs_id int NULL;
GO
IF COL_LENGTH('dbo.DOCS_NOTIFICATION','pin_request_id') IS NULL
 ALTER TABLE dbo.DOCS_NOTIFICATION ADD pin_request_id bigint NULL;
GO

USE EIS_DOCS;
GO
GRANT SELECT,INSERT,UPDATE ON dbo.DOCS_NOTIFICATION TO eis_docs_app;
GO
