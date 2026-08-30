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
    const INCOMING_CALL_POLL_MS = 3000; // global "is anyone calling me" check
    const CALL_PROGRESS_POLL_MS = 1200; // fast poll while a call is ringing/connecting/active
    const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }];

    let unreadTimer = null;
    let threadTimer = null;
    let activeConversationId = null;
    let activeConversationType = null;
    let lastMessageId = 0;
    let pendingAttachment = null;
    let lastTypingPingAt = 0;
    let chatAvailable = true; // flips false on the first "migration not run" response so we stop polling instead of erroring forever

    // Video calling
    let incomingCallTimer = null;
    let callProgressTimer = null;
    let callAvailable = true; // flips false if the video-call migration hasn't been run
    let pc = null;
    let localStream = null;
    let activeCallId = null;
    let activeCallRole = null; // 'caller' | 'callee'
    let iceSinceId = 0;
    let pendingLocalCandidates = [];
    let currentIncomingCall = null; // the call object currently shown on the incoming-call banner
    let ringtoneCtx = null;
    let ringtoneInterval = null;
    let chatPanelWasOpenBeforeCall = false;

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
                    <button type="button" class="pos-chat-conv-row" data-id="${c.conversation_id}" data-title="${escapeHtml(c.title)}" data-type="${escapeHtml(c.type)}">
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
                $('#chatConversationList').removeClass('d-none');
                loadConversations();
                openThread(res.conversation_id, null, 'direct');
            })
            .fail(function (jq) { alert((jq.responseJSON && jq.responseJSON.message) || 'Could not start that conversation.'); });
    }

    // -------------------------------------------------------------
    // Thread view
    // -------------------------------------------------------------
    function renderMessage(m) {
        const rowClass = m.is_mine ? 'mine' : 'theirs';
        // Sender name is only useful in the shared General channel, where
        // more than one other person can post - a 1-on-1 thread already
        // shows who you're talking to in the panel header, so repeating
        // their name on every bubble is redundant there.
        const showSenderName = !m.is_mine && activeConversationType === 'general';
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
                ${showSenderName ? `<div class="pos-chat-bubble-sender">${escapeHtml(m.sender_name)}</div>` : ''}
                <div class="pos-chat-bubble">${bodyHtml}${attachmentHtml}</div>
                <div class="pos-chat-bubble-time">${escapeHtml(shortTime(m.created_at))}</div>
            </div>
        `;
    }

    function scrollThreadToBottom() {
        const el = document.getElementById('chatThreadMessages');
        if (el) el.scrollTop = el.scrollHeight;
    }

    function openThread(conversationId, title, type) {
        activeConversationId = conversationId;
        activeConversationType = type || 'direct';
        lastMessageId = 0;
        clearInterval(threadTimer);

        $('#chatListView').addClass('d-none');
        $('#chatThreadView').removeClass('d-none');
        $('#chatBackBtn').removeClass('d-none');
        $('#chatCallBtn').toggleClass('d-none', activeConversationType !== 'direct');
        if (title) $('#chatPanelTitle').text(title);
        $('#chatThreadMessages').html('<div class="text-center text-muted small py-4">Loading...</div>');
        renderTyping(null);
        clearAttachment();
        hideEmojiPicker();

        loadMessages(true);
        threadTimer = setInterval(function () { loadMessages(false); }, THREAD_POLL_MS);
    }

    function closeThread() {
        clearInterval(threadTimer);
        activeConversationId = null;
        activeConversationType = null;
        renderTyping(null);
        $('#chatThreadView').addClass('d-none');
        $('#chatListView').removeClass('d-none');
        $('#chatBackBtn').addClass('d-none');
        $('#chatCallBtn').addClass('d-none');
        $('#chatPanelTitle').text('Chat');
        loadConversations();
    }

    function renderTyping(names) {
        const $indicator = $('#chatTypingIndicator');
        if (!names || !names.length) { $indicator.addClass('d-none').text(''); return; }
        const label = names.length === 1
            ? `${names[0]} is typing...`
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]} are typing...`;
        $indicator.text(label).removeClass('d-none');
    }

    function loadMessages(isInitialLoad) {
        if (!activeConversationId) return;
        const params = { action: 'messages', conversation_id: activeConversationId };
        if (!isInitialLoad && lastMessageId) params.since_id = lastMessageId;

        $.get(ENDPOINT, params).done(function (res) {
            if (!res.success) return;
            renderTyping(res.typing);

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

    function pingTyping() {
        if (!activeConversationId) return;
        const now = Date.now();
        if (now - lastTypingPingAt < 2000) return; // throttle - one ping per 2s of continuous typing is plenty
        lastTypingPingAt = now;
        $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'typing', conversation_id: activeConversationId } });
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
    // Video calling (WebRTC, signaled by polling the endpoints above -
    // audio/video itself flows directly between the two browsers once
    // connected, never through this server)
    // -------------------------------------------------------------

    function ringtoneStart() {
        ringtoneStop();
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            ringtoneCtx = new Ctx();
            const beep = function () {
                if (!ringtoneCtx) return;
                const osc = ringtoneCtx.createOscillator();
                const gain = ringtoneCtx.createGain();
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.15, ringtoneCtx.currentTime);
                osc.connect(gain).connect(ringtoneCtx.destination);
                osc.start();
                osc.stop(ringtoneCtx.currentTime + 0.25);
            };
            beep();
            ringtoneInterval = setInterval(beep, 1500);
        } catch (e) { /* audio isn't essential to the feature - fail silently */ }
    }

    function ringtoneStop() {
        clearInterval(ringtoneInterval);
        ringtoneInterval = null;
        if (ringtoneCtx) {
            try { ringtoneCtx.close(); } catch (e) { /* ignore */ }
            ringtoneCtx = null;
        }
    }

    function showIncomingCallBanner(call) {
        currentIncomingCall = call;
        $('#callIncomingName').text(call.caller_name);
        $('#callIncomingBanner').removeClass('d-none');
        ringtoneStart();
    }

    function hideIncomingCallBanner() {
        currentIncomingCall = null;
        $('#callIncomingBanner').addClass('d-none');
        ringtoneStop();
    }

    function pollIncomingCalls() {
        if (!callAvailable || activeCallId) return;
        $.get(ENDPOINT, { action: 'call_incoming' })
            .done(function (res) {
                if (!res.success) { callAvailable = false; return; }
                const call = (res.calls || [])[0];
                if (!call) {
                    if (currentIncomingCall) hideIncomingCallBanner();
                    return;
                }
                if (currentIncomingCall && currentIncomingCall.call_id === call.call_id) return;
                showIncomingCallBanner(call);
            })
            .fail(function (jq) { if (jq.status === 422) callAvailable = false; });
    }

    function setCallStatusText(text) {
        $('#callStatusText').text(text || '').toggleClass('d-none', !text);
    }

    function sendIceCandidate(candidate) {
        if (!activeCallId) return;
        $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'call_ice_candidate', call_id: activeCallId, candidate: JSON.stringify(candidate) } });
    }

    function createPeerConnection() {
        const conn = new RTCPeerConnection({ iceServers: ICE_SERVERS });
        conn.ontrack = function (e) {
            const remoteVideo = document.getElementById('callRemoteVideo');
            if (remoteVideo && remoteVideo.srcObject !== e.streams[0]) remoteVideo.srcObject = e.streams[0];
        };
        conn.onicecandidate = function (e) {
            if (!e.candidate) return;
            if (activeCallId) sendIceCandidate(e.candidate);
            else pendingLocalCandidates.push(e.candidate);
        };
        return conn;
    }

    function openCallOverlay(initialStatus) {
        $('#callOverlay').removeClass('d-none');
        // The call overlay is full-screen, but hide the chat panel/toggle
        // outright too rather than relying only on stacking order - no
        // chance of a chat message or the floating button peeking through.
        chatPanelWasOpenBeforeCall = !$('#chatPanel').hasClass('d-none');
        $('#chatPanel').addClass('d-none');
        $('#chatWidgetToggle').addClass('d-none');
        $('#callMuteBtn, #callCameraBtn').addClass('active');
        setCallStatusText(initialStatus);
    }

    function endCallCleanup() {
        clearInterval(callProgressTimer);
        callProgressTimer = null;
        ringtoneStop();
        if (pc) { try { pc.close(); } catch (e) { /* ignore */ } pc = null; }
        if (localStream) { localStream.getTracks().forEach(function (t) { t.stop(); }); localStream = null; }
        activeCallId = null;
        activeCallRole = null;
        iceSinceId = 0;
        pendingLocalCandidates = [];
        $('#callOverlay').addClass('d-none');
        $('#chatWidgetToggle').removeClass('d-none');
        if (chatPanelWasOpenBeforeCall) $('#chatPanel').removeClass('d-none');
        chatPanelWasOpenBeforeCall = false;
        const remoteVideo = document.getElementById('callRemoteVideo');
        const localVideo = document.getElementById('callLocalVideo');
        if (remoteVideo) remoteVideo.srcObject = null;
        if (localVideo) localVideo.srcObject = null;
    }

    function pollCallProgress() {
        if (!activeCallId) return;
        const callId = activeCallId;

        $.get(ENDPOINT, { action: 'call_ice_candidates', call_id: callId, since_id: iceSinceId }).done(function (res) {
            if (!res.success || callId !== activeCallId) return;
            (res.candidates || []).forEach(function (c) {
                iceSinceId = Math.max(iceSinceId, c.signal_id);
                try { pc && pc.addIceCandidate(new RTCIceCandidate(JSON.parse(c.candidate))); } catch (e) { /* ignore a stray bad candidate */ }
            });
        });

        $.get(ENDPOINT, { action: 'call_status', call_id: callId }).done(function (res) {
            if (callId !== activeCallId) return;
            const call = res.success ? res.call : null;
            if (!call) { setCallStatusText('Call ended.'); setTimeout(endCallCleanup, 1000); return; }

            // Ringback tone plays for the caller only while still actually
            // ringing - stop it the moment the call moves past that,
            // whichever way (answered, declined, missed).
            if (call.status !== 'ringing') ringtoneStop();

            if (activeCallRole === 'caller' && call.status === 'accepted' && call.answer_sdp && pc && !pc.currentRemoteDescription) {
                pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(call.answer_sdp)))
                    .then(function () { setCallStatusText(''); })
                    .catch(function () { /* ignore - a late/duplicate answer isn't fatal */ });
            }
            if (call.status === 'declined') { setCallStatusText('Call declined.'); setTimeout(endCallCleanup, 1200); }
            else if (call.status === 'missed') { setCallStatusText('No answer.'); setTimeout(endCallCleanup, 1200); }
            else if (call.status === 'ended') { setCallStatusText('Call ended.'); setTimeout(endCallCleanup, 1000); }
        });
    }

    function startCallPolling() {
        clearInterval(callProgressTimer);
        callProgressTimer = setInterval(pollCallProgress, CALL_PROGRESS_POLL_MS);
    }

    function startCall() {
        if (!activeConversationId || activeConversationType !== 'direct') return;
        if (activeCallId) { alert('You\'re already on a call.'); return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Video calling needs camera/microphone access, which this browser or connection does not allow (HTTPS is required).');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(function (stream) {
            localStream = stream;
            const conversationId = activeConversationId;
            openCallOverlay('Calling...');
            document.getElementById('callLocalVideo').srcObject = localStream;

            pc = createPeerConnection();
            localStream.getTracks().forEach(function (track) { pc.addTrack(track, localStream); });

            return pc.createOffer().then(function (offer) {
                return pc.setLocalDescription(offer).then(function () { return offer; });
            }).then(function (offer) {
                return $.ajax({
                    url: ENDPOINT, method: 'POST', dataType: 'json',
                    data: { action: 'call_start', conversation_id: conversationId, offer: JSON.stringify(offer) },
                });
            });
        }).then(function (res) {
            if (!res.success) { alert(res.message || 'Could not start the call.'); endCallCleanup(); return; }
            activeCallId = res.call_id;
            activeCallRole = 'caller';
            iceSinceId = 0;
            pendingLocalCandidates.forEach(sendIceCandidate);
            pendingLocalCandidates = [];
            setCallStatusText('Calling ' + (res.callee_name || '') + '...');
            ringtoneStart(); // ringback while it's still ringing on the other end - stopped in pollCallProgress() the moment status changes
            startCallPolling();
        }).catch(function (err) {
            if (err && err.responseJSON) { alert(err.responseJSON.message || 'Could not start the call.'); }
            else { alert('Could not access the camera/microphone. Check permissions and try again.'); }
            endCallCleanup();
        });
    }

    function acceptIncomingCall() {
        const call = currentIncomingCall;
        if (!call) return;
        hideIncomingCallBanner();

        navigator.mediaDevices.getUserMedia({ video: true, audio: true }).then(function (stream) {
            localStream = stream;
            openCallOverlay('Connecting...');
            document.getElementById('callLocalVideo').srcObject = localStream;

            pc = createPeerConnection();
            localStream.getTracks().forEach(function (track) { pc.addTrack(track, localStream); });

            return pc.setRemoteDescription(new RTCSessionDescription(JSON.parse(call.offer_sdp)))
                .then(function () { return pc.createAnswer(); })
                .then(function (answer) { return pc.setLocalDescription(answer).then(function () { return answer; }); })
                .then(function (answer) {
                    activeCallId = call.call_id;
                    activeCallRole = 'callee';
                    iceSinceId = 0;
                    pendingLocalCandidates.forEach(sendIceCandidate);
                    pendingLocalCandidates = [];
                    return $.ajax({
                        url: ENDPOINT, method: 'POST', dataType: 'json',
                        data: { action: 'call_answer', call_id: call.call_id, answer: JSON.stringify(answer) },
                    });
                });
        }).then(function (res) {
            if (!res.success) { alert(res.message || 'Could not answer the call.'); endCallCleanup(); return; }
            setCallStatusText('');
            startCallPolling();
        }).catch(function (err) {
            if (err && err.responseJSON) { alert(err.responseJSON.message || 'Could not answer the call.'); }
            else { alert('Could not access the camera/microphone. Check permissions and try again.'); }
            $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'call_decline', call_id: call.call_id } });
            endCallCleanup();
        });
    }

    function declineIncomingCall() {
        const call = currentIncomingCall;
        hideIncomingCallBanner();
        if (call) {
            $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'call_decline', call_id: call.call_id } });
        }
    }

    function hangUpCall() {
        if (activeCallId) {
            $.ajax({ url: ENDPOINT, method: 'POST', dataType: 'json', data: { action: 'call_end', call_id: activeCallId } });
        }
        endCallCleanup();
    }

    // -------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------
    $(function () {
        pollUnread();
        unreadTimer = setInterval(pollUnread, UNREAD_POLL_MS);

        pollIncomingCalls();
        incomingCallTimer = setInterval(pollIncomingCalls, INCOMING_CALL_POLL_MS);

        $('#chatCallBtn').on('click', startCall);
        $('#callAcceptBtn').on('click', acceptIncomingCall);
        $('#callDeclineBtn').on('click', declineIncomingCall);
        $('#callHangupBtn').on('click', hangUpCall);
        $('#callMuteBtn').on('click', function () {
            if (!localStream) return;
            localStream.getAudioTracks().forEach(function (t) { t.enabled = !t.enabled; });
            $(this).toggleClass('active', !!(localStream.getAudioTracks()[0] && localStream.getAudioTracks()[0].enabled));
        });
        $('#callCameraBtn').on('click', function () {
            if (!localStream) return;
            localStream.getVideoTracks().forEach(function (t) { t.enabled = !t.enabled; });
            $(this).toggleClass('active', !!(localStream.getVideoTracks()[0] && localStream.getVideoTracks()[0].enabled));
        });
        window.addEventListener('beforeunload', function () {
            if (activeCallId && navigator.sendBeacon) {
                const csrf = $('meta[name="csrf-token"]').attr('content') || '';
                navigator.sendBeacon(ENDPOINT, new URLSearchParams({ action: 'call_end', call_id: activeCallId, csrf_token: csrf }));
            }
        });

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
            const opening = $picker.hasClass('d-none');
            $picker.toggleClass('d-none', !opening);
            // The contact picker and the conversation list both list people -
            // showing them stacked at the same time duplicates every name
            // that already has a conversation, so only one is visible at once.
            $('#chatConversationList').toggleClass('d-none', opening);
            if (opening) {
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
            openThread(Number($(this).data('id')), $(this).data('title'), $(this).data('type'));
        });

        $('#chatComposerForm').on('submit', function (e) { e.preventDefault(); sendMessage(); });
        $('#chatMessageInput').on('input', pingTyping);

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
