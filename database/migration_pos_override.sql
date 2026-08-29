/* =========================================================================
   database/migration_pos_override.sql
   -------------------------------------------------------------------------
   Adds manager/owner approval for price overrides and discounts at the
   POS register:

     1. Permission 'pos.override_price' - accounts that hold it can edit
        a cart item's price or apply a discount directly; accounts that
        don't (e.g. Cashier/Staff) instead see an approval popup that a
        manager or admin clears, either by typing their username and
        password or by scanning their personal QR badge.
     2. dbo.UserQrTokens - one QR "approval badge" per user, used only to
        clear that popup at the register (NOT for signing in). Same
        selector/validator-hash pattern as dbo.UserTokens (remember-me),
        so the raw badge value is never stored - only its hash - and a
        badge can be regenerated or revoked any time from Profile
        without needing a password reset.

   Safe to re-run - only adds what's missing.
   ========================================================================= */

IF OBJECT_ID('dbo.UserQrTokens', 'U') IS NULL
CREATE TABLE dbo.UserQrTokens (
    token_id       INT IDENTITY(1,1) PRIMARY KEY,
    user_id        INT NOT NULL UNIQUE,
    selector       NVARCHAR(32)  NOT NULL UNIQUE,
    validator_hash NVARCHAR(64)  NOT NULL,
    created_at     DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT FK_UserQrTokens_User FOREIGN KEY (user_id) REFERENCES dbo.Users(user_id) ON DELETE CASCADE
);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.Permissions WHERE permission_key = 'pos.override_price')
INSERT INTO dbo.Permissions (permission_key, description)
VALUES ('pos.override_price', 'Approve price overrides and discounts at the register');
GO

/* Backfill onto Administrator/Manager, same reasoning as prior migrations:
   the pos_store.sql seed INSERT only fires for a role with ZERO
   permissions, so an existing database needs this granted explicitly. */
IF NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp
               INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
               INNER JOIN dbo.Permissions p ON p.permission_id = rp.permission_id
               WHERE r.role_name = 'Administrator' AND p.permission_key = 'pos.override_price')
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM dbo.Roles r CROSS JOIN dbo.Permissions p
WHERE r.role_name = 'Administrator' AND p.permission_key = 'pos.override_price';
GO

IF NOT EXISTS (SELECT 1 FROM dbo.RolePermissions rp
               INNER JOIN dbo.Roles r ON r.role_id = rp.role_id
               INNER JOIN dbo.Permissions p ON p.permission_id = rp.permission_id
               WHERE r.role_name = 'Manager' AND p.permission_key = 'pos.override_price')
INSERT INTO dbo.RolePermissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM dbo.Roles r CROSS JOIN dbo.Permissions p
WHERE r.role_name = 'Manager' AND p.permission_key = 'pos.override_price'
AND EXISTS (SELECT 1 FROM dbo.RolePermissions rp2
            INNER JOIN dbo.Permissions p2 ON p2.permission_id = rp2.permission_id
            WHERE rp2.role_id = r.role_id AND p2.permission_key = 'pos.access');
GO
