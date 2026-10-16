USE [EIS_DOCS];
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
