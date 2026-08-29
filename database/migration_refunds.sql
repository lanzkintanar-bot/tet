/* =========================================================================
   database/migration_refunds.sql
   -------------------------------------------------------------------------
   Adds item-level, partial-or-full Refunds for completed sales:

     1. dbo.Refunds        - one row per refund transaction against a sale
                              (who processed it, why, and the total amount).
     2. dbo.RefundDetails  - the specific SaleDetails line(s)/quantities
                              that were returned in that refund.
     3. Permission 'sales.refund' - gates the new "Refund" action
                              separately from 'sales.view' (which only
                              covers looking at Sales History).

   This is deliberately a different concept from Sales.status = 'voided'
   (see Sale::void()): voiding reverses an entire transaction outright
   (e.g. it was rung up by mistake) and stock for every line goes back.
   A refund happens after the fact, for specific returned items/quantities
   only, and the sale itself stays 'completed' - "how much has been
   refunded" is derived from SUM(RefundDetails.quantity) rather than
   duplicated onto Sales/SaleDetails, so there's nothing to keep in sync.

   Refunding a line restores that quantity to Inventory and logs an
   InventoryMovements row (movement_type = 'sale_refund'), the same way
   Sale::void() already does for a full void.

   Safe to re-run - only adds what's missing. Run this against a database
   that already ran pos_store.sql (or run pos_store.sql fresh - it now
   already includes all of this).
   ========================================================================= */

IF OBJECT_ID('dbo.Refunds', 'U') IS NULL
CREATE TABLE dbo.Refunds (
    refund_id     INT IDENTITY(1,1) PRIMARY KEY,
    sale_id       INT NOT NULL,
    invoice_no    NVARCHAR(50) NOT NULL,
    user_id       INT NOT NULL,
    reason        NVARCHAR(255) NULL,
    refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT FK_Refunds_Sale FOREIGN KEY (sale_id) REFERENCES dbo.Sales(sale_id),
    CONSTRAINT FK_Refunds_User FOREIGN KEY (user_id) REFERENCES dbo.Users(user_id)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_Refunds_Sale')
CREATE INDEX IX_Refunds_Sale ON dbo.Refunds(sale_id);
GO

IF OBJECT_ID('dbo.RefundDetails', 'U') IS NULL
CREATE TABLE dbo.RefundDetails (
    refund_detail_id INT IDENTITY(1,1) PRIMARY KEY,
    refund_id        INT NOT NULL,
    sale_detail_id   INT NOT NULL,
    product_id       INT NOT NULL,
    quantity         INT NOT NULL,
    unit_price       DECIMAL(12,2) NOT NULL,
    refund_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT FK_RefundDetails_Refund FOREIGN KEY (refund_id) REFERENCES dbo.Refunds(refund_id) ON DELETE CASCADE,
    CONSTRAINT FK_RefundDetails_SaleDetail FOREIGN KEY (sale_detail_id) REFERENCES dbo.SaleDetails(sale_detail_id),
    CONSTRAINT FK_RefundDetails_Product FOREIGN KEY (product_id) REFERENCES dbo.Products(product_id)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RefundDetails_Refund')
CREATE INDEX IX_RefundDetails_Refund ON dbo.RefundDetails(refund_id);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_RefundDetails_SaleDetail')
CREATE INDEX IX_RefundDetails_SaleDetail ON dbo.RefundDetails(sale_detail_id);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE permission_key = 'sales.refund')
INSERT INTO dbo.Permissions (permission_key, description)
VALUES ('sales.refund', 'Process refunds for completed sales');
GO

/* Backfill onto roles that already have permissions rows - the seed
   INSERT in pos_store.sql only fires for a role with ZERO permissions,
   so on an existing database Administrator/Manager need this granted
   explicitly, the same way migration_default_role_permissions.sql did
   for earlier additions. Administrator gets it unconditionally; Manager
   gets it because Manager can already void a sale (SalesController's
   void action has never required a permission beyond 'sales.view'), so
   this keeps refund and void available to the same roles by default. */
IF NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp
               INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
               INNER JOIN dbo.Permissions p ON p.permission_id = rp.permission_id
               WHERE r.role_name = 'Administrator' AND p.permission_key = 'sales.refund')
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM dbo.Roles r CROSS JOIN dbo.Permissions p
WHERE r.role_name = 'Administrator' AND p.permission_key = 'sales.refund';
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp
               INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
               INNER JOIN dbo.Permissions p ON p.permission_id = rp.permission_id
               WHERE r.role_name = 'Manager' AND p.permission_key = 'sales.refund')
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM dbo.Roles r CROSS JOIN dbo.Permissions p
WHERE r.role_name = 'Manager' AND p.permission_key = 'sales.refund'
AND EXISTS (SELECT 1 FROM dbo.RolePermissions rp2
            INNER JOIN dbo.Permissions p2 ON p2.permission_id = rp2.permission_id
            WHERE rp2.role_id = r.role_id AND p2.permission_key = 'sales.view');
GO
