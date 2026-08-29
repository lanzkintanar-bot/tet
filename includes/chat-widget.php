<?php
/**
 * includes/chat-widget.php
 * -----------------------------------------------------------------------
 * Site-wide floating chat button + panel (assets/js/chat.js drives it).
 * Internal support chat between Staff/Cashiers and Owner/Manager: one
 * shared "General" channel everyone can post to, plus private 1-on-1
 * conversations. See database/migration_chat.sql and app/models/Chat.php.
 */
if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}
?>
<button type="button" class="pos-chat-toggle" id="chatWidgetToggle" aria-label="Open chat">
    <i class="bi bi-chat-dots-fill"></i>
    <span class="pos-chat-toggle-badge d-none" id="chatWidgetBadge">0</span>
</button>

<div class="pos-chat-panel d-none" id="chatPanel" role="dialog" aria-label="Chat">
    <div class="pos-chat-panel-header">
        <button type="button" class="pos-chat-back-btn d-none" id="chatBackBtn" aria-label="Back to conversations"><i class="bi bi-arrow-left"></i></button>
        <span class="pos-chat-panel-title" id="chatPanelTitle">Chat</span>
        <button type="button" class="pos-chat-close-btn" id="chatCloseBtn" aria-label="Close chat"><i class="bi bi-x-lg"></i></button>
    </div>

    <div class="pos-chat-list-view" id="chatListView">
        <button type="button" class="pos-chat-new-btn" id="chatNewMessageBtn"><i class="bi bi-pencil-square me-1"></i>New message</button>
        <div class="pos-chat-contact-picker d-none" id="chatContactPicker">
            <input type="text" class="form-control form-control-sm mb-2" id="chatContactSearch" placeholder="Search people...">
            <div id="chatContactResults"></div>
        </div>
        <div class="pos-chat-conversations" id="chatConversationList">
            <div class="text-center text-muted small py-4">Loading...</div>
        </div>
    </div>

    <div class="pos-chat-thread-view d-none" id="chatThreadView">
        <div class="pos-chat-messages" id="chatThreadMessages"></div>
        <div class="pos-chat-attachment-preview d-none" id="chatAttachmentPreview">
            <i class="bi bi-paperclip"></i><span id="chatAttachmentName"></span>
            <button type="button" id="chatRemoveAttachment" aria-label="Remove attachment"><i class="bi bi-x"></i></button>
        </div>
        <form class="pos-chat-composer" id="chatComposerForm">
            <div class="pos-chat-composer-row">
                <label class="pos-chat-icon-btn" title="Attach a file or image">
                    <i class="bi bi-paperclip"></i>
                    <input type="file" id="chatAttachmentInput" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" hidden>
                </label>
                <button type="button" class="pos-chat-icon-btn" id="chatEmojiBtn" title="Emoji" aria-label="Emoji"><i class="bi bi-emoji-smile"></i></button>
                <input type="text" class="form-control form-control-sm" id="chatMessageInput" placeholder="Type a message..." maxlength="2000" autocomplete="off">
                <button type="submit" class="pos-chat-send-btn" id="chatSendBtn" aria-label="Send"><i class="bi bi-send-fill"></i></button>
            </div>
        </form>
        <emoji-picker class="pos-chat-emoji-picker d-none" id="chatEmojiPicker"></emoji-picker>
    </div>
</div>
