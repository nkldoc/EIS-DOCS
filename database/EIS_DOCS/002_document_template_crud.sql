USE [EIS_DOCS];
GO

IF COL_LENGTH('dbo.DOCS_HDR', 'pr_tor_id') IS NULL
    ALTER TABLE dbo.DOCS_HDR ADD pr_tor_id BIGINT NULL;
GO
IF COL_LENGTH('dbo.DOCS_HDR', 'template_name') IS NULL
    ALTER TABLE dbo.DOCS_HDR ADD template_name NVARCHAR(255) NULL;
GO
IF COL_LENGTH('dbo.DOCS_HDR', 'file_path') IS NULL
    ALTER TABLE dbo.DOCS_HDR ADD file_path NVARCHAR(1000) NULL;
GO
IF COL_LENGTH('dbo.DOCS_HDR', 'config_json') IS NULL
    ALTER TABLE dbo.DOCS_HDR ADD config_json NVARCHAR(MAX) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('dbo.DOCS_HDR') AND name = 'IX_DOCS_HDR_pr_tor_id')
    CREATE INDEX IX_DOCS_HDR_pr_tor_id ON dbo.DOCS_HDR(pr_tor_id);
GO
