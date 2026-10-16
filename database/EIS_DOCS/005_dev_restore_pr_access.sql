-- Run on DEV after restoring NMU_ERP from production.
USE [NMU_ERP];
GO
IF SUSER_ID(N'eis_docs_app') IS NULL
    THROW 50001, N'Dev login eis_docs_app does not exist', 1;
IF DATABASE_PRINCIPAL_ID(N'eis_docs_app') IS NULL
    CREATE USER [eis_docs_app] FOR LOGIN [eis_docs_app];
ELSE
    ALTER USER [eis_docs_app] WITH LOGIN = [eis_docs_app];
GRANT CONNECT TO [eis_docs_app];
GRANT SELECT ON OBJECT::[dbo].[sp_tor] TO [eis_docs_app];
GO
