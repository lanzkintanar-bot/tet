/* =========================================================================
   database/migration_shifts.sql
   -------------------------------------------------------------------------
   Adds the Start Shift / End Shift feature:
     1. dbo.Shifts - one row per cashier register session (opening cash,
        counted closing cash, expected cash, variance, notes, status).
     2. dbo.Sales.shift_id - which shift a sale was rung up under, so
        per-shift totals are exact rather than an approximation.

   Safe to re-run - only adds what's missing. Run this against a database
   that already ran pos_store.sql (or run pos_store.sql fresh - both now
   already include these).
   ========================================================================= */

IF OBJECT_ID('dbo.Shifts', 'U') IS NULL
CREATE TABLE dbo.Shifts (
    shift_id       INT IDENTITY(1,1) PRIMARY KEY,
    shift_number   INT NOT NULL,
    business_date  DATE NOT NULL,
    user_id        INT NOT NULL,
    opening_cash   DECIMAL(12,2) NOT NULL DEFAULT 0,
    actual_cash    DECIMAL(12,2) NULL,
    expected_cash  DECIMAL(12,2) NULL,
    variance       DECIMAL(12,2) NULL,
    opening_notes  NVARCHAR(500) NULL,
    closing_notes  NVARCHAR(500) NULL,
    status         NVARCHAR(10) NOT NULL DEFAULT 'active',
    opened_at      DATETIME NOT NULL DEFAULT GETDATE(),
    closed_at      DATETIME NULL,
    CONSTRAINT FK_Shifts_User FOREIGN KEY (user_id) REFERENCES dbo.Users(user_id)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id = OBJECT_ID('dbo.Sales') AND name = 'shift_id')
ALTER TABLE dbo.Sales ADD shift_id INT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_Sales_Shift')
ALTER TABLE dbo.Sales ADD CONSTRAINT FK_Sales_Shift FOREIGN KEY (shift_id) REFERENCES dbo.Shifts(shift_id);
GO
