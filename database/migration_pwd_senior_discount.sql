/* =========================================================================
   database/migration_pwd_senior_discount.sql
   -------------------------------------------------------------------------
   Adds:
     1. dbo.Sales.senior_pwd_type / senior_pwd_id_number / senior_pwd_discount
        - records which statutory discount (if any) was applied to a sale,
          the ID number required on the receipt for compliance, and the
          peso amount of that discount.
     2. Settings keys 'pwd_senior_discount_enabled' (default '1') and
        'pwd_senior_discount_rate' (default '20', i.e. 20%) controlling
        whether the option appears in the POS Payment modal and at what
        rate it's calculated.

   Safe to re-run - only adds what's missing. Run this against a database
   that already ran pos_store.sql (or run pos_store.sql fresh - both now
   already include these).
   ========================================================================= */

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.Sales') AND name = 'senior_pwd_type')
ALTER TABLE dbo.Sales ADD senior_pwd_type NVARCHAR(10) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.Sales') AND name = 'senior_pwd_id_number')
ALTER TABLE dbo.Sales ADD senior_pwd_id_number NVARCHAR(50) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.Sales') AND name = 'senior_pwd_discount')
ALTER TABLE dbo.Sales ADD senior_pwd_discount DECIMAL(12,2) NOT NULL DEFAULT 0;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Settings WHERE setting_key = 'pwd_senior_discount_enabled')
INSERT INTO dbo.Settings (setting_key, setting_value) VALUES ('pwd_senior_discount_enabled', '1');
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Settings WHERE setting_key = 'pwd_senior_discount_rate')
INSERT INTO dbo.Settings (setting_key, setting_value) VALUES ('pwd_senior_discount_rate', '20');
GO
