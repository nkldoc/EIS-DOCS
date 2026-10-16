-- Run as DBA. Change the principal if this installation uses a different login.
USE NMU_DATACENTER;
GO
GRANT UPDATE ON dbo.dc_emp (c_email) TO eis_docs_app;
GO
