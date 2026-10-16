USE EIS_DOCS;
IF COL_LENGTH('dbo.DOCS_SET','ap_id') IS NULL
 ALTER TABLE dbo.DOCS_SET ADD ap_id bigint NULL, ap_code nvarchar(250) NULL, ap_name nvarchar(1000) NULL, ap_amount decimal(19,4) NULL, contract_code nvarchar(250) NULL;
GO
USE NMU_ERP;
GRANT SELECT ON dbo.sp_check_period_hdr TO eis_docs_app;
GRANT SELECT ON dbo.sp_tor_contract TO eis_docs_app;
