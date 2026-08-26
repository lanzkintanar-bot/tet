/* =========================================================================
   database/migration_default_role_permissions.sql
   -------------------------------------------------------------------------
   Fixes two related problems on an existing database:

   1. pos.php / POSController.php previously gated the POS Screen with a
      hard-coded requireRole(['Administrator', 'Manager']) check, so no
      other role - including the built-in 'Cashier' role, whose only job
      is to use the POS Screen - could ever get in, regardless of what an
      admin granted via Roles & Permissions. That's now fixed in code to
      use the real permission (pos.access) instead of a hard-coded role
      name list.

   2. Even with that code fix, the Cashier and Manager roles were seeded
      with ZERO permissions by default (only Administrator got any) - so
      a fresh Cashier account was still locked out until an admin visited
      Roles & Permissions and checked boxes by hand. This backfills
      sensible defaults matching each role's own stated description:
      Cashier -> POS screen only; Manager -> sales, inventory, reports
      (not user/role administration or system settings).

   Safe to re-run - only inserts rows that don't already exist, and never
   touches a role's permissions if it already has at least one (so this
   won't silently overwrite permissions you've already customized for
   Cashier or Manager on this database).
   ========================================================================= */

IF NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp
               INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
               WHERE r.role_name = 'Cashier')
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT (SELECT role_id FROM dbo.Roles WHERE role_name = 'Cashier'), permission_id
FROM dbo.Permissions WHERE permission_key = 'pos.access';
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp
               INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
               WHERE r.role_name = 'Manager')
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT (SELECT role_id FROM dbo.Roles WHERE role_name = 'Manager'), permission_id
FROM dbo.Permissions WHERE permission_key IN (
    'dashboard.view', 'pos.access', 'products.manage', 'categories.manage',
    'suppliers.manage', 'customers.manage', 'purchases.manage', 'inventory.manage',
    'ledger.view', 'sales.view', 'sales.export', 'reports.view', 'reports.print',
    'customer_reports.view', 'reconciliation.manage'
);
GO
