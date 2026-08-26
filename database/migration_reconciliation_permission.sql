/* =========================================================================
   database/migration_reconciliation_permission.sql
   -------------------------------------------------------------------------
   Adds the 'reconciliation.manage' permission key (Transaction Record &
   End of Day Reconciliation was added after the original Roles &
   Permissions seed, so it never got one - reconciliation.php and
   ReconciliationController.php were left on a hard-coded
   requireRole(['Administrator','Manager']) check instead of the
   granular permission system every other page uses).

   Administrator does not need a row here - Role::hasPermission() always
   returns true for the Administrator role regardless of RolePermissions
   content. Manager is granted this by default to match its previous
   hard-coded access; from here it can be revoked or granted to any other
   role (e.g. Cashier) from the Roles & Permissions page like anything else.

   Safe to re-run. Run this against a database that already ran
   pos_store.sql (or run pos_store.sql fresh - both now already include
   this).
   ========================================================================= */

IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE permission_key = 'reconciliation.manage')
INSERT INTO dbo.Permissions (permission_key, description) VALUES
    ('reconciliation.manage', 'Manage Transaction Record & End of Day Reconciliation');
GO

IF NOT EXISTS (
    SELECT 1 FROM dbo.RolePermissions rp
    INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
    INNER JOIN dbo.Permissions p ON p.permission_id = rp.permission_id
    WHERE r.role_name = 'Manager' AND p.permission_key = 'reconciliation.manage'
)
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT (SELECT role_id FROM dbo.Roles WHERE role_name = 'Manager'), permission_id
FROM dbo.Permissions WHERE permission_key = 'reconciliation.manage';
GO
