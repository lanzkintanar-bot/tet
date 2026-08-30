<?php
/**
 * includes/footer.php
 * -----------------------------------------------------------------------
 * Shared closing scripts, included near the end of <body> on
 * authenticated pages.
 */
if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}
?>
<footer class="pos-footer text-center text-muted small py-3">
    &copy; <?= date('Y') ?> <?= Security::escape(APP_NAME) ?> · v<?= Security::escape(APP_VERSION) ?>
</footer>

<?php require_once __DIR__ . '/shift-modals.php'; ?>
<?php
// The chat widget is site-wide except on the POS screen itself, where the
// floating button/panel would sit on top of a cashier's already-busy
// scanning/checkout UI. Any page can opt out the same way by setting
// $hideChatWidget = true before requiring this file.
if (empty($hideChatWidget)) {
    require_once __DIR__ . '/chat-widget.php';
}
?>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (empty($hideChatWidget)): ?>
<!-- Emoji picker for the chat widget composer - vendored locally (see assets/vendor/emoji-picker-element) instead of loaded from a CDN so it still works on networks that can't reach jsdelivr -->
<script type="module" src="<?= Helper::versionedAsset('/vendor/emoji-picker-element/index.js') ?>"></script>
<?php endif; ?>
<!-- App scripts -->
<script src="<?= Helper::versionedAsset('/js/app.js') ?>"></script>
<script src="<?= Helper::versionedAsset('/js/notifications.js') ?>"></script>
<script src="<?= Helper::versionedAsset('/js/pos-shift.js') ?>"></script>
<?php if (empty($hideChatWidget)): ?>
<script src="<?= Helper::versionedAsset('/js/chat.js') ?>"></script>
<?php endif; ?>
