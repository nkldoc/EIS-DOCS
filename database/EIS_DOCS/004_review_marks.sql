USE EIS_DOCS;
IF COL_LENGTH('dbo.DOCS_DTL', 'review_marks_json') IS NULL
    ALTER TABLE dbo.DOCS_DTL ADD review_marks_json nvarchar(max) NULL;
