/* =========================================================================
   database/migration_wholesale_pricing.sql
   -------------------------------------------------------------------------
   Adds an optional second price tier per product ("Wholesale"), plus a
   store-wide on/off switch for the feature:

     1. dbo.Products.wholesale_price - NULL by default (no wholesale price
        set). Only products with a wholesale price AND the store-wide
        toggle on show the Retail/Wholesale switch in the POS.
     2. Settings 'wholesale_pricing_enabled' - off ('0') by default, so
        nothing changes for stores that don't use this until an admin
        turns it on in Settings > POS & Payments.

   Safe to re-run - only adds what's missing.
   ========================================================================= */

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.Products') AND name = 'wholesale_price')
ALTER TABLE dbo.Products ADD wholesale_price DECIMAL(12,2) NULL;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Settings WHERE setting_key = 'wholesale_pricing_enabled')
INSERT INTO dbo.Settings (setting_key, setting_value) VALUES ('wholesale_pricing_enabled', '0');
GO
