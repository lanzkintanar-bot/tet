<?php
/**
 * app/controllers/ProfileController.php
 * -----------------------------------------------------------------------
 * Self-service account page: any logged-in user (any role) can view
 * their own details, update their name/email, and change their own
 * password. This is deliberately separate from UserController - that
 * one lets an Administrator manage OTHER people's accounts and roles;
 * this one only ever touches the current session's own user_id, so
 * there's no risk of a role/user_id being passed in from the client.
 */

if (!defined('POS_APP') && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class ProfileController
{
    private User $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function dispatch(): void
    {
        SessionManager::requireLogin();

        switch ($_REQUEST['action'] ?? '') {
            case 'get':              $this->get(); break;
            case 'update':           $this->update(); break;
            case 'change_password':  $this->changePassword(); break;
            case 'generate_qr_badge': $this->generateQrBadge(); break;
            case 'revoke_qr_badge':   $this->revokeQrBadge(); break;
            default: Helper::jsonResponse(false, 'Unknown action.', [], 400);
        }
    }

    private function currentUserId(): int
    {
        return (int) SessionManager::get('user_id');
    }

    private function get(): void
    {
        $user = $this->userModel->findById($this->currentUserId());
        if (!$user) {
            Helper::jsonResponse(false, 'Account not found.', [], 404);
        }
        Helper::jsonResponse(true, '', [
            'user' => $user,
            'can_approve_overrides' => SessionManager::hasPermission('pos.override_price'),
            'qr_badge_issued_at' => $this->userModel->qrBadgeStatus($this->currentUserId()),
        ]);
    }

    private function update(): void
    {
        Security::requireValidCsrfFromRequest();

        $fullName = Security::sanitize(trim($_POST['full_name'] ?? ''));
        $email    = Security::sanitize(trim($_POST['email'] ?? ''));

        if ($fullName === '') {
            Helper::jsonResponse(false, 'Full name is required.', [], 422);
        }

        // Own profile only - role and active status are not editable here.
        $this->userModel->updateProfile($this->currentUserId(), $fullName, $email, null, null);
        SessionManager::set('full_name', $fullName);

        $this->userModel->logActivity($this->currentUserId(), 'PROFILE_UPDATED', 'Updated own profile details');
        Helper::jsonResponse(true, 'Profile updated.');
    }

    private function changePassword(): void
    {
        Security::requireValidCsrfFromRequest();

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $userId  = $this->currentUserId();

        $hash = $this->userModel->getPasswordHash($userId);
        if (!$hash || !$this->userModel->verifyPassword($current, $hash)) {
            Helper::jsonResponse(false, 'Your current password is incorrect.', [], 422);
        }

        [$ok, $error] = $this->userModel->setPassword($userId, $new);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }

        $this->userModel->logActivity($userId, 'PASSWORD_CHANGED', 'Changed own password');
        Helper::jsonResponse(true, 'Password changed.');
    }

    /**
     * Issues a fresh POS "approval badge" QR for the current user,
     * replacing any existing one - shown to the user once, right here;
     * only its hash is ever stored (see database/migration_pos_override.sql).
     * Requires the current password again, same as changing it, so a
     * badge can't be silently (re)issued from an unlocked terminal.
     */
    private function generateQrBadge(): void
    {
        Security::requireValidCsrfFromRequest();

        $userId = $this->currentUserId();
        $password = (string) ($_POST['current_password'] ?? '');
        $hash = $this->userModel->getPasswordHash($userId);
        if (!$hash || !$this->userModel->verifyPassword($password, $hash)) {
            Helper::jsonResponse(false, 'Your current password is incorrect.', [], 422);
        }

        $badge = $this->userModel->generateQrBadge($userId);
        if ($badge === null) {
            Helper::jsonResponse(false, 'QR badges aren\'t set up on this database yet. Ask an administrator to run database/migration_pos_override.sql.', [], 422);
        }

        $this->userModel->logActivity($userId, 'QR_BADGE_GENERATED', 'Generated a new POS approval QR badge');
        Helper::jsonResponse(true, 'Badge generated.', [
            'badge' => $badge,
            'issued_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function revokeQrBadge(): void
    {
        Security::requireValidCsrfFromRequest();

        $userId = $this->currentUserId();
        $this->userModel->revokeQrBadge($userId);
        $this->userModel->logActivity($userId, 'QR_BADGE_REVOKED', 'Revoked their POS approval QR badge');
        Helper::jsonResponse(true, 'Badge revoked.');
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    SessionManager::start();
    (new ProfileController())->dispatch();
}
