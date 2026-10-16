-- DEV: restore employee search permissions after restoring NMU_DATACENTER.
USE [NMU_DATACENTER];
GO
IF SUSER_ID(N'eis_docs_app') IS NULL
    THROW 50001, N'Login eis_docs_app does not exist', 1;
IF DATABASE_PRINCIPAL_ID(N'eis_docs_app') IS NULL
    CREATE USER [eis_docs_app] FOR LOGIN [eis_docs_app];
ELSE
    ALTER USER [eis_docs_app] WITH LOGIN = [eis_docs_app];
GRANT CONNECT TO [eis_docs_app];
GRANT SELECT ON OBJECT::dbo.dc_user TO [eis_docs_app];
GRANT SELECT ON OBJECT::dbo.dc_emp TO [eis_docs_app];
GO
