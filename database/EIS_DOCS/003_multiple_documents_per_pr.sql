USE [EIS_DOCS];
GO
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

IF EXISTS (SELECT 1 FROM sys.key_constraints WHERE parent_object_id=OBJECT_ID('dbo.DOCS_HDR') AND name='UQ_DOCS_HDR_doc_no')
    ALTER TABLE dbo.DOCS_HDR DROP CONSTRAINT UQ_DOCS_HDR_doc_no;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.DOCS_HDR') AND name='UQ_DOCS_HDR_doc_no')
    DROP INDEX UQ_DOCS_HDR_doc_no ON dbo.DOCS_HDR;
GO
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.DOCS_HDR') AND name='UQ_DOCS_HDR_pr_document_name' AND has_filter=1)
    DROP INDEX UQ_DOCS_HDR_pr_document_name ON dbo.DOCS_HDR;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.DOCS_HDR') AND name='UQ_DOCS_HDR_pr_document_name')
    CREATE UNIQUE INDEX UQ_DOCS_HDR_pr_document_name ON dbo.DOCS_HDR(doc_no, template_name);
GO
