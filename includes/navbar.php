<?php
/**
 * includes/navbar.php
 * -----------------------------------------------------------------------
 * Top navigation bar for authenticated pages.
 */
if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}
?>
<nav class="navbar pos-navbar navbar-expand px-3">
    <button class="btn pos-sidebar-toggle d-lg-none me-2" id="sidebarToggle" type="button" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
    </button>

    <a class="navbar-brand pos-brand" href="<?= APP_URL ?>/index.php">
        <span class="pos-brand-mark">POS</span><span class="pos-brand-rest">STORE</span>
    </a>

    <div class="ms-auto d-flex align-items-center gap-3">
        <div class="dropdown d-none" id="posShiftBadgeWrap">
            <button class="pos-shift-badge" type="button" id="btnShiftBadge" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Shift active">
                <span class="pos-shift-dot"></span>
                <i class="bi bi-clock-history pos-shift-icon d-md-none"></i>
                <span class="pos-shift-badge-text d-none d-md-inline">Shift #<span id="shiftBadgeNumber">-</span> <span class="pos-shift-badge-sep">&middot;</span> <span id="shiftBadgeDuration">0h 0m</span></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm pos-shift-panel" aria-labelledby="btnShiftBadge">
                <div class="pos-shift-panel-head"><span class="pos-shift-dot"></span><strong>Shift Active</strong><span class="pos-shift-panel-number">#<span id="shiftPanelNumber">-</span></span></div>
                <div class="pos-shift-panel-body">
                    <div class="pos-shift-panel-row"><span>Cashier</span><strong id="shiftPanelCashier">-</strong></div>
                    <div class="pos-shift-panel-row"><span>Duration</span><strong id="shiftPanelDuration">0h 0m</strong></div>
                    <div class="pos-shift-panel-row"><span>Total Sales</span><strong id="shiftPanelTotalSales" class="text-success">₱0</strong></div>
                    <div class="pos-shift-panel-row"><span>Cash Sales</span><strong id="shiftPanelCashSales">₱0</strong></div>
                    <div class="pos-shift-panel-row"><span>Transactions</span><strong id="shiftPanelTxns">0</strong></div>
                    <button type="button" class="pos-shift-end-btn" id="btnOpenEndShift"><i class="bi bi-moon me-1"></i>End Shift</button>
                </div>
                <div class="pos-shift-panel-foot">Started at <span id="shiftPanelStartedAt">-</span></div>
            </div>
        </div>
        <button class="btn pos-icon-btn" type="button" id="themeToggleBtn" aria-label="Toggle dark mode" title="Toggle dark / light mode">
            <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
        </button>
        <button class="btn pos-icon-btn d-none d-sm-inline-flex" type="button" id="fullscreenToggleBtn" aria-label="Toggle full screen" title="Toggle full screen">
            <i class="bi bi-arrows-fullscreen" id="fullscreenToggleIcon"></i>
        </button>
        <div class="dropdown">
            <button class="btn pos-icon-btn position-relative" type="button" id="notifBell" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <span class="pos-notif-dot d-none" id="notifDot"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-sm p-0 pos-notif-panel" aria-labelledby="notifBell">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                    <span class="fw-semibold small">Low Stock Alerts</span>
                    <span class="badge pos-badge-warning" id="notifCountBadge" style="display:none;">0</span>
                </div>
                <div id="notifList" style="max-height: 320px; overflow-y: auto;">
                    <div class="text-center text-muted small py-3">No low-stock items right now.</div>
                </div>
                <?php if (in_array(SessionManager::get('role_name'), ['Administrator', 'Manager'], true)): ?>
                <a href="<?= APP_URL ?>/inventory.php" class="d-block text-center small py-2 border-top">View Inventory</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="dropdown">
            <button class="btn pos-user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="pos-avatar"><?= Security::escape(strtoupper(substr(SessionManager::get('full_name', 'U'), 0, 1))) ?></span>
                <span class="d-none d-md-inline"><?= Security::escape(SessionManager::get('full_name')) ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><span class="dropdown-item-text text-muted small"><?= Security::escape(SessionManager::get('role_name')) ?></span></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <?php if (SessionManager::get('role_name') === 'Administrator'): ?>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/settings.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
