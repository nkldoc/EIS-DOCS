-- EIS-DOCS production schema. No application data or credentials included.
USE [master];


GO

IF DB_ID(N'EIS_DOCS_SEED_TEST_20261002') IS NULL EXEC(N'CREATE DATABASE [EIS_DOCS_SEED_TEST_20261002]');


GO

USE [EIS_DOCS_SEED_TEST_20261002];


GO

SET ANSI_NULLS ON


GO

SET QUOTED_IDENTIFIER ON


GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[WF_USER_HDR]') AND type in (N'U'))
BEGIN
CREATE TABLE [dbo].[WF_USER_HDR](
	[c_name] [nvarchar](255) COLLATE Thai_CI_AS NOT NULL,
	[visibility] [varchar](20) COLLATE Thai_CI_AS NOT NULL,
	[owner_user_id] [nvarchar](100) COLLATE Thai_CI_AS NULL,
	[owner_cost_id] [int] NULL,
 CONSTRAINT [PK_WF_USER_HDR] PRIMARY KEY CLUSTERED 
(
	[c_name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
END


GO

SET ANSI_NULLS ON


GO

SET QUOTED_IDENTIFIER ON


GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[WF_USER_DTL]') AND type in (N'U'))
BEGIN
CREATE TABLE [dbo].[WF_USER_DTL](
	[user_id] [nvarchar](100) COLLATE Thai_CI_AS NOT NULL,
	[c_name] [nvarchar](255) COLLATE Thai_CI_AS NOT NULL,
	[dc_emp_id] [bigint] NOT NULL,
	[review_action] [bit] NOT NULL,
	[sign_action] [bit] NOT NULL,
	[approve_action] [bit] NOT NULL,
	[wf_order] [int] NOT NULL,
 CONSTRAINT [PK_WF_USER_DTL] PRIMARY KEY CLUSTERED 
(
	[c_name] ASC,
	[user_id] ASC,
	[dc_emp_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
END


GO

SET ANSI_NULLS ON


GO

SET QUOTED_IDENTIFIER ON


GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_HDR]') AND type in (N'U'))
BEGIN
CREATE TABLE [dbo].[DOCS_HDR](
	[docs_id] [bigint] IDENTITY(1,1) NOT NULL,
	[doc_no] [nvarchar](50) COLLATE Thai_CI_AS NOT NULL,
	[doc_type] [varchar](10) COLLATE Thai_CI_AS NOT NULL,
	[doc_type_name] [nvarchar](100) COLLATE Thai_CI_AS NOT NULL,
	[department] [nvarchar](255) COLLATE Thai_CI_AS NOT NULL,
	[requester_name] [nvarchar](255) COLLATE Thai_CI_AS NOT NULL,
	[doc_date] [date] NOT NULL,
	[amount] [decimal](18, 2) NOT NULL,
	[pdf_doc_id] [nvarchar](255) COLLATE Thai_CI_AS NULL,
	[description] [nvarchar](1000) COLLATE Thai_CI_AS NULL,
	[status] [varchar](20) COLLATE Thai_CI_AS NOT NULL,
	[created_at] [datetime2](0) NOT NULL,
	[updated_at] [datetime2](0) NOT NULL,
	[pr_tor_id] [bigint] NULL,
	[template_name] [nvarchar](255) COLLATE Thai_CI_AS NULL,
	[file_path] [nvarchar](1000) COLLATE Thai_CI_AS NULL,
	[config_json] [nvarchar](max) COLLATE Thai_CI_AS NULL,
	[created_by] [nvarchar](100) COLLATE Thai_CI_AS NULL,
 CONSTRAINT [PK_DOCS_HDR] PRIMARY KEY CLUSTERED 
(
	[docs_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
END


GO

SET ANSI_NULLS ON


GO

SET QUOTED_IDENTIFIER ON


GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_DTL]') AND type in (N'U'))
BEGIN
CREATE TABLE [dbo].[DOCS_DTL](
	[docs_dtl_id] [bigint] IDENTITY(1,1) NOT NULL,
	[docs_id] [bigint] NOT NULL,
	[wf_order] [int] NOT NULL,
	[user_id] [nvarchar](100) COLLATE Thai_CI_AS NOT NULL,
	[dc_emp_id] [bigint] NULL,
	[signer_name] [nvarchar](255) COLLATE Thai_CI_AS NOT NULL,
	[review_action] [bit] NOT NULL,
	[sign_action] [bit] NOT NULL,
	[approve_action] [bit] NOT NULL,
	[sign_status] [varchar](20) COLLATE Thai_CI_AS NOT NULL,
	[decision] [varchar](20) COLLATE Thai_CI_AS NULL,
	[signed_at] [datetime2](0) NULL,
	[rejection_reason] [nvarchar](1000) COLLATE Thai_CI_AS NULL,
	[review_marks_json] [nvarchar](max) COLLATE Thai_CI_AS NULL,
 CONSTRAINT [PK_DOCS_DTL] PRIMARY KEY CLUSTERED 
(
	[docs_dtl_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY],
 CONSTRAINT [UQ_DOCS_DTL_route] UNIQUE NONCLUSTERED 
(
	[docs_id] ASC,
	[wf_order] ASC,
	[user_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
END


GO

SET ANSI_NULLS ON


GO

SET QUOTED_IDENTIFIER ON


GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_SET]') AND type in (N'U'))
BEGIN
CREATE TABLE [dbo].[DOCS_SET](
	[set_id] [int] IDENTITY(1,1) NOT NULL,
	[set_name] [nvarchar](250) COLLATE Thai_CI_AS NOT NULL,
	[document_ids] [nvarchar](max) COLLATE Thai_CI_AS NOT NULL,
	[created_by] [nvarchar](100) COLLATE Thai_CI_AS NOT NULL,
	[created_at] [datetime2](7) NOT NULL,
	[ap_id] [bigint] NULL,
	[ap_code] [nvarchar](250) COLLATE Thai_CI_AS NULL,
	[ap_name] [nvarchar](max) COLLATE Thai_CI_AS NULL,
	[ap_amount] [decimal](19, 4) NULL,
	[contract_code] [nvarchar](250) COLLATE Thai_CI_AS NULL,
PRIMARY KEY CLUSTERED 
(
	[set_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
END


GO

SET ANSI_NULLS ON


GO

SET QUOTED_IDENTIFIER ON


GO

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_NOTIFICATION]') AND type in (N'U'))
BEGIN
CREATE TABLE [dbo].[DOCS_NOTIFICATION](
	[notification_id] [bigint] IDENTITY(1,1) NOT NULL,
	[user_id] [nvarchar](100) COLLATE Thai_CI_AS NOT NULL,
	[docs_id] [int] NOT NULL,
	[notification_type] [varchar](40) COLLATE Thai_CI_AS NOT NULL,
	[wf_order] [int] NULL,
	[title] [nvarchar](250) COLLATE Thai_CI_AS NOT NULL,
	[message] [nvarchar](1000) COLLATE Thai_CI_AS NOT NULL,
	[created_at] [datetime2](7) NOT NULL,
	[read_at] [datetime2](7) NULL,
PRIMARY KEY CLUSTERED 
(
	[notification_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]
) ON [PRIMARY]
END


GO

-- Upgrade: 002_document_template_crud.sql
USE [EIS_DOCS_SEED_TEST_20261002];

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


GO

-- Upgrade: 003_multiple_documents_per_pr.sql
USE [EIS_DOCS_SEED_TEST_20261002];

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


GO

-- Upgrade: 004_review_marks.sql
USE [EIS_DOCS_SEED_TEST_20261002];
IF COL_LENGTH('dbo.DOCS_DTL', 'review_marks_json') IS NULL
    ALTER TABLE dbo.DOCS_DTL ADD review_marks_json nvarchar(max) NULL;


GO

-- Upgrade: 006_document_sets.sql
USE [EIS_DOCS_SEED_TEST_20261002];
IF OBJECT_ID('dbo.DOCS_SET','U') IS NULL
CREATE TABLE dbo.DOCS_SET (
 set_id int IDENTITY PRIMARY KEY,
 set_name nvarchar(250) NOT NULL,
 document_ids nvarchar(max) NOT NULL,
 created_by nvarchar(100) NOT NULL,
 created_at datetime2 NOT NULL DEFAULT SYSDATETIME()
);
-- Permissions are configured separately in 02.


GO

-- Upgrade: 007_document_set_ap.sql
USE [EIS_DOCS_SEED_TEST_20261002];
IF COL_LENGTH('dbo.DOCS_SET','ap_id') IS NULL
 ALTER TABLE dbo.DOCS_SET ADD ap_id bigint NULL, ap_code nvarchar(250) NULL, ap_name nvarchar(1000) NULL, ap_amount decimal(19,4) NULL, contract_code nvarchar(250) NULL;

GO


GO

-- Upgrade: 008_ap_detail_names.sql
USE [EIS_DOCS_SEED_TEST_20261002];
ALTER TABLE dbo.DOCS_SET ALTER COLUMN ap_name nvarchar(max) NULL;

GO


GO

-- Upgrade: 010_workflow_notifications.sql
USE [EIS_DOCS_SEED_TEST_20261002];

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


GO

-- Upgrade: 011_wf_group_visibility.sql
USE [EIS_DOCS_SEED_TEST_20261002];

GO

-- Existing groups remain shared; new Save As groups explicitly set their scope.
IF COL_LENGTH('dbo.WF_USER_HDR', 'visibility') IS NULL
    ALTER TABLE dbo.WF_USER_HDR ADD visibility varchar(20) NOT NULL
        CONSTRAINT DF_WF_USER_HDR_visibility DEFAULT ('all') WITH VALUES;
IF COL_LENGTH('dbo.WF_USER_HDR', 'owner_user_id') IS NULL
    ALTER TABLE dbo.WF_USER_HDR ADD owner_user_id nvarchar(100) NULL;
IF COL_LENGTH('dbo.WF_USER_HDR', 'owner_cost_id') IS NULL
    ALTER TABLE dbo.WF_USER_HDR ADD owner_cost_id int NULL;

GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name='CK_WF_USER_HDR_visibility')
    ALTER TABLE dbo.WF_USER_HDR ADD CONSTRAINT CK_WF_USER_HDR_visibility
        CHECK (visibility IN ('private', 'department', 'all'));

GO


GO

-- Upgrade: 012_document_owner.sql
USE [EIS_DOCS_SEED_TEST_20261002];

GO

IF COL_LENGTH('dbo.DOCS_HDR','created_by') IS NULL
    ALTER TABLE dbo.DOCS_HDR ADD created_by nvarchar(100) NULL;

GO

-- Older records have no verified creator; leave them for Administrator access.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.DOCS_HDR') AND name='IX_DOCS_HDR_created_by')
    CREATE INDEX IX_DOCS_HDR_created_by ON dbo.DOCS_HDR(created_by);

GO


GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.WF_USER_HDR') AND name=N'visibility' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[WF_USER_HDR] ADD  CONSTRAINT [DF_WF_USER_HDR_visibility]  DEFAULT ('all') FOR [visibility]
END



GO

IF NOT EXISTS (SELECT * FROM sys.check_constraints WHERE object_id = OBJECT_ID(N'[dbo].[CK_WF_USER_HDR_visibility]') AND parent_object_id = OBJECT_ID(N'[dbo].[WF_USER_HDR]'))
ALTER TABLE [dbo].[WF_USER_HDR]  WITH CHECK ADD  CONSTRAINT [CK_WF_USER_HDR_visibility] CHECK  (([visibility]='all' OR [visibility]='department' OR [visibility]='private'))


GO

IF  EXISTS (SELECT * FROM sys.check_constraints WHERE object_id = OBJECT_ID(N'[dbo].[CK_WF_USER_HDR_visibility]') AND parent_object_id = OBJECT_ID(N'[dbo].[WF_USER_HDR]'))
ALTER TABLE [dbo].[WF_USER_HDR] CHECK CONSTRAINT [CK_WF_USER_HDR_visibility]


GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[WF_USER_DTL]') AND name = N'IX_WF_USER_DTL_dc_emp_id')
CREATE NONCLUSTERED INDEX [IX_WF_USER_DTL_dc_emp_id] ON [dbo].[WF_USER_DTL]
(
	[dc_emp_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[WF_USER_DTL]') AND name = N'IX_WF_USER_DTL_order')
CREATE NONCLUSTERED INDEX [IX_WF_USER_DTL_order] ON [dbo].[WF_USER_DTL]
(
	[c_name] ASC,
	[wf_order] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[WF_USER_DTL]') AND name = N'IX_WF_USER_DTL_user_id')
CREATE NONCLUSTERED INDEX [IX_WF_USER_DTL_user_id] ON [dbo].[WF_USER_DTL]
(
	[user_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.WF_USER_DTL') AND name=N'review_action' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[WF_USER_DTL] ADD  CONSTRAINT [DF_WF_USER_DTL_review]  DEFAULT ((1)) FOR [review_action]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.WF_USER_DTL') AND name=N'sign_action' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[WF_USER_DTL] ADD  CONSTRAINT [DF_WF_USER_DTL_sign]  DEFAULT ((0)) FOR [sign_action]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.WF_USER_DTL') AND name=N'approve_action' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[WF_USER_DTL] ADD  CONSTRAINT [DF_WF_USER_DTL_approve]  DEFAULT ((0)) FOR [approve_action]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.WF_USER_DTL') AND name=N'wf_order' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[WF_USER_DTL] ADD  CONSTRAINT [DF_WF_USER_DTL_order]  DEFAULT ((1)) FOR [wf_order]
END



GO

IF NOT EXISTS (SELECT * FROM sys.foreign_keys WHERE object_id = OBJECT_ID(N'[dbo].[FK_WF_USER_DTL_HDR]') AND parent_object_id = OBJECT_ID(N'[dbo].[WF_USER_DTL]'))
ALTER TABLE [dbo].[WF_USER_DTL]  WITH CHECK ADD  CONSTRAINT [FK_WF_USER_DTL_HDR] FOREIGN KEY([c_name])
REFERENCES [dbo].[WF_USER_HDR] ([c_name])


GO

IF  EXISTS (SELECT * FROM sys.foreign_keys WHERE object_id = OBJECT_ID(N'[dbo].[FK_WF_USER_DTL_HDR]') AND parent_object_id = OBJECT_ID(N'[dbo].[WF_USER_DTL]'))
ALTER TABLE [dbo].[WF_USER_DTL] CHECK CONSTRAINT [FK_WF_USER_DTL_HDR]


GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_HDR]') AND name = N'IX_DOCS_HDR_created_by')
CREATE NONCLUSTERED INDEX [IX_DOCS_HDR_created_by] ON [dbo].[DOCS_HDR]
(
	[created_by] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_HDR]') AND name = N'IX_DOCS_HDR_pr_tor_id')
CREATE NONCLUSTERED INDEX [IX_DOCS_HDR_pr_tor_id] ON [dbo].[DOCS_HDR]
(
	[pr_tor_id] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_HDR]') AND name = N'IX_DOCS_HDR_status_date')
CREATE NONCLUSTERED INDEX [IX_DOCS_HDR_status_date] ON [dbo].[DOCS_HDR]
(
	[status] ASC,
	[doc_date] DESC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_HDR]') AND name = N'UQ_DOCS_HDR_pr_document_name')
CREATE UNIQUE NONCLUSTERED INDEX [UQ_DOCS_HDR_pr_document_name] ON [dbo].[DOCS_HDR]
(
	[doc_no] ASC,
	[template_name] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, IGNORE_DUP_KEY = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_HDR') AND name=N'amount' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_HDR] ADD  CONSTRAINT [DF_DOCS_HDR_amount]  DEFAULT ((0)) FOR [amount]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_HDR') AND name=N'status' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_HDR] ADD  CONSTRAINT [DF_DOCS_HDR_status]  DEFAULT ('PENDING') FOR [status]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_HDR') AND name=N'created_at' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_HDR] ADD  CONSTRAINT [DF_DOCS_HDR_created]  DEFAULT (sysdatetime()) FOR [created_at]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_HDR') AND name=N'updated_at' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_HDR] ADD  CONSTRAINT [DF_DOCS_HDR_updated]  DEFAULT (sysdatetime()) FOR [updated_at]
END



GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_DTL]') AND name = N'IX_DOCS_DTL_pending')
CREATE NONCLUSTERED INDEX [IX_DOCS_DTL_pending] ON [dbo].[DOCS_DTL]
(
	[docs_id] ASC,
	[sign_status] ASC,
	[wf_order] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_DTL') AND name=N'review_action' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_DTL] ADD  CONSTRAINT [DF_DOCS_DTL_review]  DEFAULT ((1)) FOR [review_action]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_DTL') AND name=N'sign_action' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_DTL] ADD  CONSTRAINT [DF_DOCS_DTL_sign]  DEFAULT ((1)) FOR [sign_action]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_DTL') AND name=N'approve_action' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_DTL] ADD  CONSTRAINT [DF_DOCS_DTL_approve]  DEFAULT ((0)) FOR [approve_action]
END



GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_DTL') AND name=N'sign_status' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_DTL] ADD  CONSTRAINT [DF_DOCS_DTL_status]  DEFAULT ('PENDING') FOR [sign_status]
END



GO

IF NOT EXISTS (SELECT * FROM sys.foreign_keys WHERE object_id = OBJECT_ID(N'[dbo].[FK_DOCS_DTL_HDR]') AND parent_object_id = OBJECT_ID(N'[dbo].[DOCS_DTL]'))
ALTER TABLE [dbo].[DOCS_DTL]  WITH CHECK ADD  CONSTRAINT [FK_DOCS_DTL_HDR] FOREIGN KEY([docs_id])
REFERENCES [dbo].[DOCS_HDR] ([docs_id])
ON DELETE CASCADE


GO

IF  EXISTS (SELECT * FROM sys.foreign_keys WHERE object_id = OBJECT_ID(N'[dbo].[FK_DOCS_DTL_HDR]') AND parent_object_id = OBJECT_ID(N'[dbo].[DOCS_DTL]'))
ALTER TABLE [dbo].[DOCS_DTL] CHECK CONSTRAINT [FK_DOCS_DTL_HDR]


GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_SET') AND name=N'created_at' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_SET] ADD  DEFAULT (sysdatetime()) FOR [created_at]
END



GO

SET ANSI_PADDING ON



GO

IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = OBJECT_ID(N'[dbo].[DOCS_NOTIFICATION]') AND name = N'IX_DOCS_NOTIFICATION_user_unread')
CREATE NONCLUSTERED INDEX [IX_DOCS_NOTIFICATION_user_unread] ON [dbo].[DOCS_NOTIFICATION]
(
	[user_id] ASC,
	[read_at] ASC,
	[notification_id] DESC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON) ON [PRIMARY]


GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID(N'dbo.DOCS_NOTIFICATION') AND name=N'created_at' AND default_object_id<>0)
BEGIN
ALTER TABLE [dbo].[DOCS_NOTIFICATION] ADD  CONSTRAINT [DF_DOCS_NOTIFICATION_created_at]  DEFAULT (sysdatetime()) FOR [created_at]
END



GO


GO
