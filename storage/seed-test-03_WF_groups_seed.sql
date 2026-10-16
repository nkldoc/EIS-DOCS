-- Snapshot 2026-10-02 Asia/Bangkok; original WF order and permissions preserved.
-- Exported signer groups only; no documents, passwords, or login accounts.
-- Existing group names are skipped as a whole (no overwrites or extra members).
USE [EIS_DOCS_SEED_TEST_20261002];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
DECLARE @Headers TABLE(c_name nvarchar(255) PRIMARY KEY, visibility varchar(20), owner_user_id nvarchar(100), owner_cost_id int);
DECLARE @Members TABLE(user_id nvarchar(100), c_name nvarchar(255), dc_emp_id bigint, review_action bit, sign_action bit, approve_action bit, wf_order int);
INSERT @Headers VALUES (N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',N'all',NULL,NULL);
INSERT @Headers VALUES (N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',N'private',N'60429',38);
INSERT @Headers VALUES (N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',N'department',N'60070',38);
INSERT @Headers VALUES (N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',N'private',N'60070',38);
INSERT @Headers VALUES (N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',N'all',NULL,NULL);
INSERT @Headers VALUES (N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',N'department',N'60429',38);
INSERT @Members VALUES (N'60070',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20087,1,1,0,1);
INSERT @Members VALUES (N'60056',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20102,1,1,0,9);
INSERT @Members VALUES (N'60047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20074,1,0,0,10);
INSERT @Members VALUES (N'30047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20072,1,1,0,11);
INSERT @Members VALUES (N'40050',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20077,1,0,0,12);
INSERT @Members VALUES (N'60630',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20698,1,1,0,13);
INSERT @Members VALUES (N'60061',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20075,1,0,0,14);
INSERT @Members VALUES (N'60520',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20546,1,1,1,15);
INSERT @Members VALUES (N'60429',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20455,1,1,0,1);
INSERT @Members VALUES (N'60056',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20102,1,1,0,2);
INSERT @Members VALUES (N'60047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20074,1,0,0,3);
INSERT @Members VALUES (N'30047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20072,1,1,0,4);
INSERT @Members VALUES (N'40050',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20077,1,0,0,5);
INSERT @Members VALUES (N'60630',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20698,1,1,0,6);
INSERT @Members VALUES (N'60061',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20075,1,0,0,7);
INSERT @Members VALUES (N'60520',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน นัทพล',20546,1,1,1,8);
INSERT @Members VALUES (N'60070',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20087,1,1,0,1);
INSERT @Members VALUES (N'60056',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20102,1,1,0,9);
INSERT @Members VALUES (N'60047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20074,1,0,0,10);
INSERT @Members VALUES (N'30047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20072,1,1,0,11);
INSERT @Members VALUES (N'40050',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20077,1,0,0,12);
INSERT @Members VALUES (N'60630',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20698,1,1,0,13);
INSERT @Members VALUES (N'60061',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20075,1,0,0,14);
INSERT @Members VALUES (N'60520',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน หน่วยงานจัดซื้อ',20546,1,1,1,15);
INSERT @Members VALUES (N'60070',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20087,1,1,0,1);
INSERT @Members VALUES (N'60056',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20102,1,1,0,2);
INSERT @Members VALUES (N'60047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20074,1,0,0,3);
INSERT @Members VALUES (N'30047',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20072,1,1,0,4);
INSERT @Members VALUES (N'40050',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20077,1,0,0,5);
INSERT @Members VALUES (N'60630',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20698,1,1,0,6);
INSERT @Members VALUES (N'60061',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20075,1,0,0,7);
INSERT @Members VALUES (N'60520',N'สายงานที่ 1 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน อัณณ์ชญาน์',20546,1,1,1,8);
INSERT @Members VALUES (N'60429',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20455,1,1,0,1);
INSERT @Members VALUES (N'60056',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20102,1,1,0,8);
INSERT @Members VALUES (N'60047',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20074,1,0,0,9);
INSERT @Members VALUES (N'30047',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20072,1,1,0,10);
INSERT @Members VALUES (N'40050',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20077,1,0,0,11);
INSERT @Members VALUES (N'60630',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20698,1,1,0,12);
INSERT @Members VALUES (N'60061',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20075,1,0,0,13);
INSERT @Members VALUES (N'60520',N'สายงานที่ 2 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20546,1,1,1,14);
INSERT @Members VALUES (N'60070',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20087,1,1,0,1);
INSERT @Members VALUES (N'60056',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20102,1,1,0,2);
INSERT @Members VALUES (N'60047',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20074,1,0,0,3);
INSERT @Members VALUES (N'30047',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20072,1,1,0,4);
INSERT @Members VALUES (N'40050',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20077,1,0,0,5);
INSERT @Members VALUES (N'60630',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20698,1,1,0,6);
INSERT @Members VALUES (N'60061',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20075,1,0,0,7);
INSERT @Members VALUES (N'60520',N'สายงานที่ 3 กลุ่มผู้ลงนามเอกสารจัดซื้อ 8 คน',20546,1,1,1,8);
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @NewGroups TABLE(c_name nvarchar(255) PRIMARY KEY);
 INSERT @NewGroups SELECT s.c_name FROM @Headers s
 WHERE NOT EXISTS(SELECT 1 FROM dbo.WF_USER_HDR h WITH(UPDLOCK,HOLDLOCK) WHERE h.c_name=s.c_name);
 -- User IDs must refer to the same employees in production. Do not silently remap names.
 IF EXISTS(SELECT 1 FROM @Members d JOIN @NewGroups n ON n.c_name=d.c_name
   LEFT JOIN NMU_DATACENTER.dbo.dc_user u ON CONVERT(nvarchar(100),u.dc_user_id)=d.user_id AND u.dc_emp_id=d.dc_emp_id
   LEFT JOIN NMU_DATACENTER.dbo.dc_emp e ON e.dc_emp_id=d.dc_emp_id
   WHERE u.dc_user_id IS NULL OR e.dc_emp_id IS NULL)
 BEGIN
   SELECT d.c_name,d.user_id,d.dc_emp_id AS missing_or_mismatched_employee FROM @Members d JOIN @NewGroups n ON n.c_name=d.c_name
   LEFT JOIN NMU_DATACENTER.dbo.dc_user u ON CONVERT(nvarchar(100),u.dc_user_id)=d.user_id AND u.dc_emp_id=d.dc_emp_id
   LEFT JOIN NMU_DATACENTER.dbo.dc_emp e ON e.dc_emp_id=d.dc_emp_id WHERE u.dc_user_id IS NULL OR e.dc_emp_id IS NULL;
   THROW 50010,'Seed users do not match NMU_DATACENTER. No groups were imported.',1;
 END;
 IF EXISTS(SELECT 1 FROM @Headers h JOIN @NewGroups n ON n.c_name=h.c_name
   WHERE h.owner_user_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM NMU_DATACENTER.dbo.dc_user u WHERE CONVERT(nvarchar(100),u.dc_user_id)=h.owner_user_id))
   THROW 50011,'Group owner does not exist in NMU_DATACENTER. No groups were imported.',1;
 INSERT dbo.WF_USER_HDR(c_name,visibility,owner_user_id,owner_cost_id)
 SELECT h.c_name,h.visibility,h.owner_user_id,h.owner_cost_id FROM @Headers h JOIN @NewGroups n ON n.c_name=h.c_name;
 INSERT dbo.WF_USER_DTL(user_id,c_name,dc_emp_id,review_action,sign_action,approve_action,wf_order)
 SELECT d.user_id,d.c_name,d.dc_emp_id,d.review_action,d.sign_action,d.approve_action,d.wf_order
 FROM @Members d JOIN @NewGroups n ON n.c_name=d.c_name;
 COMMIT;
 SELECT h.c_name,h.visibility,(SELECT COUNT(*) FROM @Members d WHERE d.c_name=h.c_name) AS exported_members,
 CASE WHEN n.c_name IS NULL THEN 'SKIPPED_EXISTING_GROUP' ELSE 'IMPORTED' END AS import_result
 FROM @Headers h LEFT JOIN @NewGroups n ON n.c_name=h.c_name ORDER BY h.c_name;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
GO