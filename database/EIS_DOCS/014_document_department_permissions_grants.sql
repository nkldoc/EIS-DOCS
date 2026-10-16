-- Run as DBA after 013. Change the principal if this installation uses another login.
USE EIS_DOCS;
GO
GRANT SELECT, INSERT, DELETE ON dbo.DOCS_DEPARTMENT_PERMISSION TO eis_docs_app;
GO
USE NMU_DATACENTER;
GO
GRANT SELECT ON dbo.dc_cost TO eis_docs_app;
GO
