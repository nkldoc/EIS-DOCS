IF COL_LENGTH('dbo.WF_USER_DTL', 'signature_text_json') IS NULL
    ALTER TABLE dbo.WF_USER_DTL ADD signature_text_json nvarchar(max) NULL;
