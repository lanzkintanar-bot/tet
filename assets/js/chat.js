/**
 * assets/js/chat.js
 * -----------------------------------------------------------------------
 * Loaded globally (includes/footer.php) on every authenticated page.
 * Drives the floating chat widget (includes/chat-widget.php): a shared
 * "General" channel plus private 1-on-1 conversations. See
 * app/models/Chat.php for the access rules (Cashier/Staff can only DM
 * an Administrator or Manager).
 */
(function ($) {
    'use strict';

    const ENDPOINT = (window.APP_URL || '') + '/app/controllers/ChatController.php';
    const UNREAD_POLL_MS = 20000;   // badge on the floating button, always running
    const THREAD_POLL_MS = 4000;    // new messages in whatever thread is currently open

    let unreadTimer = null;
    let threadTimer = null;
    let activeConversationId = null;
    let lastMessageId = 0;
    let pendingAttachment = null;
    let chatAvailable = true; // flips false on the first "migration not run" response so we stop polling instead of erroring forever

    function escapeHtml(str) { return $('<div>').text(str == null ? '' : str).html(); }

    function initials(name) {
        const parts = (name || '?').trim().split(/\s+/);
        return ((parts[0] || '')[0] || '?').toUpperCase();
    }

    function shortTime(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        const now = new Date();
        const sameDay = d.toDateString() === now.toDateString();
        return sameDay
            ? d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
            : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }

    // -------------------------------------------------------------
    // Unread badge (always polling, panel open or not)
    // -------------------------------------------------------------
    function pollUnread() {
        if (!chatAvailable) return;
        $.get(ENDPOINT, { action: 'unread_count' })
            .done(function (res) {
                if (!res.success) { chatAvailable = false; return; }
                const count = res.unread_count || 0;
                const $badge = $('#chatWidgetBadge');
                if (count > 0) {
                    $badge.text(count > 99 ? '99+' : count).removeClass('d-none');
                } else {
                    $badge.addClass('d-none');
                }
            })
            .fail(function (jq) {
                if (jq.status === 422) chatAvailable = false; // migration not run - stop hammering the endpoint
            });
    }

    // -------------------------------------------------------------
    // Conversation list
    // -------------------------------------------------------------
    function loadConversations() {
        const $list = $('#chatConversationList').html('<div class="text-center text-muted small py-4">Loading...</div>');
        $.get(ENDPOINT, { action: 'conversations' }).done(function (res) {
            if (!res.success) { $list.html('<div class="text-center text-muted small py-4">' + escapeHtml(res.message || 'Could not load conversations.') + '</div>'); return; }

            const conversations = res.conversations || [];
            if (!conversations.length) {
                $list.html('<div class="text-center text-muted small py-4">No conversations yet.</div>');
                return;
            }

            $list.empty();
            conversations.forEach(function (c) {
                const isGeneral = c.type === 'general';
                const preview = c.last_body || (c.last_attachment_name ? '📎 ' + c.last_attachment_name : 'No messages yet');
                const $row = $(`
                    <button type="button" class="pos-chat-conv-row" data-id="${c.conversation_id}" data-title="${escapeHtml(c.title)}">
                        <div class="pos-chat-avatar${isGeneral ? ' general' : ''}">${isGeneral ? '<i class="bi bi-people-fill"></i>' : escapeHtml(initials(c.title))}</div>
                        <div class="pos-chat-conv-info">
                            <div class="pos-chat-conv-title">${escapeHtml(c.title)}</div>
                            <div class="pos-chat-conv-preview">${escapeHtml(preview)}</div>
                        </div>
                        ${c.unread_count > 0 ? `<span class="pos-chat-conv-unread">${c.unread_count > 99 ? '99+' : c.unread_count}</span>` : ''}
                    </button>
                `);
                $list.append($row);
            });
        });
    }

    // -------------------------------------------------------------
    // Contact picker (start a new direct conversation)
    // -------------------------------------------------------------
    function loadContacts(search) {
        const $results = $('#chatContactResults').html('<div class="text-center text-muted small py-3">Loading...</div>');
        $.get(ENDPOINT, { action: 'contacts', search: search || '' }).done(function (res) {
            if (!res.success) { $results.empty(); return; }
            const contacts = res.contacts || [];
            if (!contacts.length) {
                $results.html('<div class="text-center text-muted small py-3">No one to message.</div>');
                return;
            }
            $results.empty();
            contacts.forEach(function (u) {
                const $row = $(`
                    <button type="button" class="pos-chat-contact-row" data-id="${u.user_id}">
                        <div class="pos-chat-avatar">${escapeHtml(initials(u.full_name))}</div>
                        <div class="pos-chat-conv-info">
                            <div class="pos-chat-conv-title">${escapeHtml(u.full_name)}</div>
                            <div class="pos-chat-contact-role">${escapeHtml(u.role_name)}</div>
                        </div>
                    </button>
                `);
                $results.append($row);
            });
        });
    }

    function openDirectConversation(otherUserId) {
        $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'open_direct', user_id: otherUserId } })
            .done(function (res) {
                if (!res.success) { alert(res.message || 'Could not start that conversation.'); return; }
                $('#chatContactPicker').addClass('d-none');
                loadConversations();
                openThread(res.conversation_id, null);
            })
            .fail(function (jq) { alert((jq.responseJSON && jq.responseJSON.message) || 'Could not start that conversation.'); });
    }

    // -------------------------------------------------------------
    // Thread view
    // -------------------------------------------------------------
    function renderMessage(m) {
        const rowClass = m.is_mine ? 'mine' : 'theirs';
        let attachmentHtml = '';
        if (m.attachment_url) {
            if ((m.attachment_mime || '').indexOf('image/') === 0) {
                attachmentHtml = `<a href="${escapeHtml(m.attachment_url)}" target="_blank" rel="noopener"><img class="pos-chat-bubble-image" src="${escapeHtml(m.attachment_url)}" alt="${escapeHtml(m.attachment_name || '')}"></a>`;
            } else {
                attachmentHtml = `<div class="pos-chat-bubble-file"><i class="bi bi-file-earmark-text"></i><a href="${escapeHtml(m.attachment_url)}" target="_blank" rel="noopener">${escapeHtml(m.attachment_name || 'File')}</a></div>`;
            }
        }
        const bodyHtml = m.body ? `<div>${escapeHtml(m.body)}</div>` : '';
        return `
            <div class="pos-chat-bubble-row ${rowClass}">
                ${!m.is_mine ? `<div class="pos-chat-bubble-sender">${escapeHtml(m.sender_name)}</div>` : ''}
                <div class="pos-chat-bubble">${bodyHtml}${attachmentHtml}</div>
                <div class="pos-chat-bubble-time">${escapeHtml(shortTime(m.created_at))}</div>
            </div>
        `;
    }

    function scrollThreadToBottom() {
        const el = document.getElementById('chatThreadMessages');
        if (el) el.scrollTop = el.scrollHeight;
    }

    function openThread(conversationId, title) {
        activeConversationId = conversationId;
        lastMessageId = 0;
        clearInterval(threadTimer);

        $('#chatListView').addClass('d-none');
        $('#chatThreadView').removeClass('d-none');
        $('#chatBackBtn').removeClass('d-none');
        if (title) $('#chatPanelTitle').text(title);
        $('#chatThreadMessages').html('<div class="text-center text-muted small py-4">Loading...</div>');
        clearAttachment();
        hideEmojiPicker();

        loadMessages(true);
        threadTimer = setInterval(function () { loadMessages(false); }, THREAD_POLL_MS);
    }

    function closeThread() {
        clearInterval(threadTimer);
        activeConversationId = null;
        $('#chatThreadView').addClass('d-none');
        $('#chatListView').removeClass('d-none');
        $('#chatBackBtn').addClass('d-none');
        $('#chatPanelTitle').text('Chat');
        loadConversations();
    }

    function loadMessages(isInitialLoad) {
        if (!activeConversationId) return;
        const params = { action: 'messages', conversation_id: activeConversationId };
        if (!isInitialLoad && lastMessageId) params.since_id = lastMessageId;

        $.get(ENDPOINT, params).done(function (res) {
            if (!res.success) return;
            const messages = res.messages || [];
            if (!messages.length) return;

            const $thread = $('#chatThreadMessages');
            if (isInitialLoad) $thread.empty();

            messages.forEach(function (m) {
                $thread.append(renderMessage(m));
                lastMessageId = Math.max(lastMessageId, m.message_id);
            });
            scrollThreadToBottom();

            markRead();
        });
    }

    function markRead() {
        if (!activeConversationId) return;
        $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'mark_read', conversation_id: activeConversationId } })
            .done(function () { pollUnread(); });
    }

    // -------------------------------------------------------------
    // Composer: text + attachment + emoji
    // -------------------------------------------------------------
    function clearAttachment() {
        pendingAttachment = null;
        $('#chatAttachmentInput').val('');
        $('#chatAttachmentPreview').addClass('d-none');
        $('#chatAttachmentName').text('');
    }

    function hideEmojiPicker() {
        $('#chatEmojiPicker').addClass('d-none');
    }

    function sendMessage() {
        if (!activeConversationId) return;
        const body = $('#chatMessageInput').val().trim();
        if (!body && !pendingAttachment) return;

        const formData = new FormData();
        formData.append('action', 'send');
        formData.append('conversation_id', activeConversationId);
        formData.append('body', body);
        if (pendingAttachment) formData.append('attachment', pendingAttachment);

        const $sendBtn = $('#chatSendBtn').prop('disabled', true);
        $.ajax({ url: ENDPOINT, method: 'POST', data: formData, processData: false, contentType: false, dataType: 'json' })
            .done(function (res) {
                if (!res.success) { alert(res.message || 'Could not send that message.'); return; }
                $('#chatMessageInput').val('');
                clearAttachment();
                hideEmojiPicker();
                if (res.message) {
                    $('#chatThreadMessages').append(renderMessage(res.message));
                    lastMessageId = Math.max(lastMessageId, res.message.message_id);
                    scrollThreadToBottom();
                }
            })
            .fail(function (jq) { alert((jq.responseJSON && jq.responseJSON.message) || 'Could not send that message.'); })
            .always(function () { $sendBtn.prop('disabled', false); });
    }

    // -------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------
    $(function () {
        pollUnread();
        unreadTimer = setInterval(pollUnread, UNREAD_POLL_MS);

        $('#chatWidgetToggle').on('click', function () {
            const $panel = $('#chatPanel');
            const opening = $panel.hasClass('d-none');
            $panel.toggleClass('d-none');
            if (opening && !activeConversationId) loadConversations();
        });
        $('#chatCloseBtn').on('click', function () { $('#chatPanel').addClass('d-none'); });
        $('#chatBackBtn').on('click', closeThread);

        $('#chatNewMessageBtn').on('click', function () {
            const $picker = $('#chatContactPicker');
            $picker.toggleClass('d-none');
            if (!$picker.hasClass('d-none')) {
                $('#chatContactSearch').val('').trigger('focus');
                loadContacts('');
            }
        });
        let contactDebounce = null;
        $('#chatContactSearch').on('input', function () {
            const term = $(this).val();
            clearTimeout(contactDebounce);
            contactDebounce = setTimeout(function () { loadContacts(term); }, 250);
        });
        $('#chatContactResults').on('click', '.pos-chat-contact-row', function () {
            openDirectConversation(Number($(this).data('id')));
        });

        $('#chatConversationList').on('click', '.pos-chat-conv-row', function () {
            openThread(Number($(this).data('id')), $(this).data('title'));
        });

        $('#chatComposerForm').on('submit', function (e) { e.preventDefault(); sendMessage(); });

        $('#chatAttachmentInput').on('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            pendingAttachment = file;
            $('#chatAttachmentName').text(file.name);
            $('#chatAttachmentPreview').removeClass('d-none');
        });
        $('#chatRemoveAttachment').on('click', clearAttachment);

        $('#chatEmojiBtn').on('click', function () {
            $('#chatEmojiPicker').toggleClass('d-none');
        });
        document.addEventListener('emoji-click', function (e) {
            if ($('#chatEmojiPicker').hasClass('d-none')) return;
            const $input = $('#chatMessageInput');
            const emoji = e.detail.unicode || '';
            $input.val($input.val() + emoji);
            hideEmojiPicker();
            $input.trigger('focus');
        });
        $(document).on('click', function (e) {
            const $picker = $('#chatEmojiPicker');
            if ($picker.hasClass('d-none')) return;
            if (!$(e.target).closest('#chatEmojiPicker, #chatEmojiBtn').length) hideEmojiPicker();
        });
    });
})(jQuery);
