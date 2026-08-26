<?php
/**
 * config/session.php
 * -----------------------------------------------------------------------
 * Secure Session Bootstrap
 * -----------------------------------------------------------------------
 * - Locks down cookie params (HttpOnly, Secure, SameSite)
 * - Starts the session
 * - Enforces an idle timeout
 * - Periodically regenerates the session id (mitigates session fixation)
 */

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class SessionManager
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = IS_HTTPS;

        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,           // expires when browser closes (idle timeout handled manually)
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,    // only send cookie over HTTPS when available
            'httponly' => true,        // JS cannot read the session cookie -> mitigates XSS cookie theft
            'samesite' => 'Lax',       // CSRF mitigation for cross-site requests
        ]);

        session_start();

        self::enforceIdleTimeout();
        self::regenerateIdPeriodically();
    }

    /**
     * Logs the user out automatically after SESSION_LIFETIME seconds
     * of inactivity.
     */
    private static function enforceIdleTimeout(): void
    {
        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
            self::destroy();
            // If this was an AJAX call, let the caller detect the empty session;
            // otherwise redirect on next page load via requireLogin().
        }
        $_SESSION['last_activity'] = time();
    }

    /**
     * Regenerates the session ID on a fixed interval while keeping session
     * data intact. Reduces the window for session fixation / hijacking.
     */
    private static function regenerateIdPeriodically(): void
    {
        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = time();
            session_regenerate_id(true);
            return;
        }

        if (time() - $_SESSION['created_at'] > SESSION_REGENERATE_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['created_at'] = time();
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * Call at the top of any protected page. Redirects to login
     * if the user has no active session.
     */
    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }
    }

    /**
     * Restricts a page to one or more roles, e.g. requireRole(['admin', 'manager'])
     */
    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        if (empty($_SESSION['role_name']) || !in_array($_SESSION['role_name'], $roles, true)) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                || strpos($_SERVER['REQUEST_URI'] ?? '', '/app/controllers/') !== false;
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'You do not have permission to use this feature.']);
                exit;
            }
            header('Location: ' . APP_URL . '/access_denied.php');
            exit;
        }
    }

    /**
     * Checks the logged-in user's role against the granular
     * Roles & Permissions matrix (RolePermissions), by permission key
     * (e.g. 'pos.access') - NOT a hard-coded role-name list. Use this
     * instead of requireRole() for anything an admin should be able to
     * grant to a custom or non-Administrator/Manager role (like Cashier)
     * via the Roles & Permissions page, rather than baking specific role
     * names into the page itself.
     */
    public static function hasPermission(string $permissionKey): bool
    {
        if (empty($_SESSION['role_id'])) {
            return false;
        }
        return (new Role())->hasPermission((int) $_SESSION['role_id'], $permissionKey);
    }

    public static function requirePermission(string $permissionKey): void
    {
        self::requireLogin();
        if (!self::hasPermission($permissionKey)) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                || strpos($_SERVER['REQUEST_URI'] ?? '', '/app/controllers/') !== false;
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'You do not have permission to use this feature.']);
                exit;
            }
            header('Location: ' . APP_URL . '/access_denied.php');
            exit;
        }
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /** MAIN user: no branch assignment (branch_id is null in session). */
    public static function isMainUser(): bool
    {
        return self::get('branch_id') === null;
    }

    /** Branch user: assigned to a specific branch. */
    public static function isBranchUser(): bool
    {
        return self::get('branch_id') !== null;
    }

    /**
     * Restricts access to MAIN administration only (branch management,
     * roles, settings, activity logs). Branch users are redirected.
     */
    public static function requireMain(): void
    {
        self::requireLogin();
        if (!self::isMainUser()) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                || strpos($_SERVER['REQUEST_URI'] ?? '', '/app/controllers/') !== false;
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'This feature is only available to MAIN administration.']);
                exit;
            }
            header('Location: ' . APP_URL . '/access_denied.php');
            exit;
        }
    }

    /**
     * Resolves which branch to filter by in queries.
     * Branch users are always scoped to their branch.
     * MAIN users may pass a filter (0/null = all branches).
     */
    public static function resolveBranchFilter(?int $requestedFilter = null): ?int
    {
        if (self::isBranchUser()) {
            return (int) self::get('branch_id');
        }
        if ($requestedFilter === null || $requestedFilter <= 0) {
            return null;
        }
        return $requestedFilter;
    }

    /** branch_id to stamp on new sales: null for MAIN, branch id for branch users. */
    public static function getSaleBranchId(): ?int
    {
        if (self::isBranchUser()) {
            return (int) self::get('branch_id');
        }
        return null;
    }
}
