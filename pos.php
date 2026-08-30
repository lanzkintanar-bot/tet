<?php
define('POS_APP', true);
require_once __DIR__ . '/config/config.php';

SessionManager::start();
Security::applySecurityHeaders();
SessionManager::requireLogin();
SessionManager::requirePermission('pos.access');

$pageTitle = 'POS Screen';
$csrfToken = Security::generateCsrfToken();
$hideChatWidget = true; // Keep the floating chat button/panel off the POS screen only - see includes/footer.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once __DIR__ . '/includes/header.php'; ?>
</head>
<body>
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="pos-shell">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="pos-main">
            <div class="container-fluid py-4">
                <?php require_once __DIR__ . '/views/pos.php'; ?>
            </div>
        </main>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <!-- Camera barcode/QR decoding for the scanner modal -->
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="<?= Helper::versionedAsset('/js/receipt-template.js') ?>"></script>
    <script src="<?= Helper::versionedAsset('/js/pos.js') ?>"></script>
    <script src="<?= Helper::versionedAsset('/js/pos-scanner.js') ?>"></script>
</body>
</html>
