USE EIS_DOCS;
ALTER TABLE dbo.DOCS_SET ALTER COLUMN ap_name nvarchar(max) NULL;
GO
USE NMU_ERP;
GRANT SELECT ON dbo.sp_check_period_dtl TO eis_docs_app;
