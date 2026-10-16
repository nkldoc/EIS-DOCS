-- Run in NMU_DATACENTER shared by both applications.
IF OBJECT_ID('dbo.DOCS_SUPPLIES_IMPERSONATION', 'U') IS NULL
CREATE TABLE dbo.DOCS_SUPPLIES_IMPERSONATION (
    ticket_hash char(64) NOT NULL PRIMARY KEY,
    admin_user_id int NOT NULL,
    target_user_id int NOT NULL,
    expires_at datetime2 NOT NULL,
    used_at datetime2 NULL
);
