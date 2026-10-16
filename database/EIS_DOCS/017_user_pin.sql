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
