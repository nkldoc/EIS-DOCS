USE EIS_DOCS;
GO
IF COL_LENGTH('dbo.DOCS_HDR','created_by') IS NULL
    ALTER TABLE dbo.DOCS_HDR ADD created_by nvarchar(100) NULL;
GO
-- Older records have no verified creator; leave them for Administrator access.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.DOCS_HDR') AND name='IX_DOCS_HDR_created_by')
    CREATE INDEX IX_DOCS_HDR_created_by ON dbo.DOCS_HDR(created_by);
GO
