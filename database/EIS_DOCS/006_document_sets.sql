USE EIS_DOCS;
IF OBJECT_ID('dbo.DOCS_SET','U') IS NULL
CREATE TABLE dbo.DOCS_SET (
 set_id int IDENTITY PRIMARY KEY,
 set_name nvarchar(250) NOT NULL,
 document_ids nvarchar(max) NOT NULL,
 created_by nvarchar(100) NOT NULL,
 created_at datetime2 NOT NULL DEFAULT SYSDATETIME()
);
GRANT SELECT, INSERT ON dbo.DOCS_SET TO eis_docs_app;
