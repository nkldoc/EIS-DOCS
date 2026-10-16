USE [NMU_EIS];
GO

IF OBJECT_ID(N'dbo.documents', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.documents (
        document_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        document_no NVARCHAR(100) NOT NULL,
        title NVARCHAR(500) NOT NULL,
        status NVARCHAR(30) NOT NULL CONSTRAINT DF_documents_status DEFAULT N'DRAFT',
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_documents_created_at DEFAULT SYSUTCDATETIME(),
        updated_at DATETIME2(0) NULL,
        row_version ROWVERSION NOT NULL,
        CONSTRAINT UQ_documents_document_no UNIQUE (document_no)
    );
END;
GO

