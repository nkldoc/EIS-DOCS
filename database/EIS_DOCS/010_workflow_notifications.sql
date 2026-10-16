USE EIS_DOCS;

IF OBJECT_ID('dbo.DOCS_NOTIFICATION', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.DOCS_NOTIFICATION (
        notification_id bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        user_id nvarchar(100) NOT NULL,
        docs_id int NOT NULL,
        notification_type varchar(40) NOT NULL,
        wf_order int NULL,
        title nvarchar(250) NOT NULL,
        message nvarchar(1000) NOT NULL,
        created_at datetime2 NOT NULL CONSTRAINT DF_DOCS_NOTIFICATION_created_at DEFAULT SYSDATETIME(),
        read_at datetime2 NULL
    );
    CREATE INDEX IX_DOCS_NOTIFICATION_user_unread
        ON dbo.DOCS_NOTIFICATION (user_id, read_at, notification_id DESC);
END;
