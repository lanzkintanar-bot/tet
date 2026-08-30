<?php
/**
 * app/controllers/ChatController.php
 * -----------------------------------------------------------------------
 * AJAX endpoint for the site-wide chat widget (includes/chat-widget.php +
 * assets/js/chat.js). Available to every logged-in user regardless of
 * role - access control for individual conversations lives in
 * app/models/Chat.php, not here.
 */

if (!defined('POS_APP') && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    require_once dirname(__DIR__, 2) . '/config/config.php';
}

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class ChatController
{
    private Chat $chatModel;

    public function __construct()
    {
        $this->chatModel = new Chat();
    }

    public function dispatch(): void
    {
        SessionManager::requireLogin();

        if (!$this->chatModel->isAvailable()) {
            Helper::jsonResponse(false, 'Chat isn\'t set up on this database yet. Ask an administrator to run database/migration_chat.sql.', [], 422);
        }

        $action = $_REQUEST['action'] ?? '';

        // Video-call actions need their own tables, shipped separately
        // (migration_chat_video_calls.sql) after the base chat migration -
        // gate them independently so a site that's only run the original
        // migration gets a clear message instead of a raw SQL error the
        // first time someone taps the call button.
        if (strpos($action, 'call_') === 0 && !$this->chatModel->isCallAvailable()) {
            Helper::jsonResponse(false, 'Video calling isn\'t set up on this database yet. Ask an administrator to run database/migration_chat_video_calls.sql.', [], 422);
        }

        switch ($action) {
            case 'conversations':      $this->conversations(); break;
            case 'contacts':           $this->contacts(); break;
            case 'open_direct':        $this->openDirect(); break;
            case 'messages':           $this->messages(); break;
            case 'send':               $this->send(); break;
            case 'typing':             $this->typing(); break;
            case 'mark_read':          $this->markRead(); break;
            case 'unread_count':       $this->unreadCount(); break;
            case 'call_start':         $this->callStart(); break;
            case 'call_incoming':      $this->callIncoming(); break;
            case 'call_answer':        $this->callAnswer(); break;
            case 'call_decline':       $this->callDecline(); break;
            case 'call_end':           $this->callEnd(); break;
            case 'call_status':        $this->callStatus(); break;
            case 'call_ice_candidate': $this->callIceCandidate(); break;
            case 'call_ice_candidates': $this->callIceCandidates(); break;
            default: Helper::jsonResponse(false, 'Unknown action.', [], 400);
        }
    }

    private function userId(): int
    {
        return (int) SessionManager::get('user_id');
    }

    private function roleName(): string
    {
        return (string) SessionManager::get('role_name');
    }

    private function conversations(): void
    {
        Helper::jsonResponse(true, '', ['conversations' => $this->chatModel->listConversationsForUser($this->userId())]);
    }

    private function contacts(): void
    {
        $search = Security::sanitize(trim($_GET['search'] ?? ''));
        Helper::jsonResponse(true, '', ['contacts' => $this->chatModel->contactsFor($this->userId(), $this->roleName(), $search)]);
    }

    private function openDirect(): void
    {
        Security::requireValidCsrfFromRequest();
        $otherUserId = (int) ($_POST['user_id'] ?? 0);
        if ($otherUserId <= 0) {
            Helper::jsonResponse(false, 'Choose someone to message.', [], 422);
        }
        [$conversationId, $error] = $this->chatModel->getOrCreateDirectConversation($this->userId(), $this->roleName(), $otherUserId);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }
        Helper::jsonResponse(true, '', ['conversation_id' => $conversationId]);
    }

    private function messages(): void
    {
        $conversationId = (int) ($_GET['conversation_id'] ?? 0);
        $sinceId = isset($_GET['since_id']) && $_GET['since_id'] !== '' ? (int) $_GET['since_id'] : null;
        if ($conversationId <= 0) {
            Helper::jsonResponse(false, 'Conversation not found.', [], 404);
        }
        [$messages, $error] = $this->chatModel->getMessages($conversationId, $this->userId(), $sinceId);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 403);
        }
        Helper::jsonResponse(true, '', [
            'messages' => $messages,
            'typing' => $this->chatModel->getTypingUsers($conversationId, $this->userId()),
        ]);
    }

    /** Pinged by the composer (throttled client-side) while the user has text in the box. Deliberately lightweight - no CSRF round trip cost beyond the usual check, no response body needed. */
    private function typing(): void
    {
        Security::requireValidCsrfFromRequest();
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        if ($conversationId > 0) {
            $this->chatModel->setTyping($conversationId, $this->userId());
        }
        Helper::jsonResponse(true, 'OK.');
    }

    private function send(): void
    {
        Security::requireValidCsrfFromRequest();

        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            Helper::jsonResponse(false, 'Conversation not found.', [], 404);
        }

        [$attachment, $uploadError] = $this->chatModel->handleAttachmentUpload();
        if ($uploadError) {
            Helper::jsonResponse(false, $uploadError, [], 422);
        }

        // Body is intentionally NOT run through Security::sanitize() here -
        // the chat thread renders it as text content (jQuery .text()),
        // never as HTML, so escaping happens at render time instead of
        // mangling emoji or punctuation on the way in.
        $body = (string) ($_POST['body'] ?? '');

        [$message, $error] = $this->chatModel->sendMessage($conversationId, $this->userId(), $body, $attachment);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }
        Helper::jsonResponse(true, 'Sent.', ['message' => $message]);
    }

    private function markRead(): void
    {
        Security::requireValidCsrfFromRequest();
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        if ($conversationId > 0) {
            $this->chatModel->markRead($conversationId, $this->userId());
        }
        Helper::jsonResponse(true, 'OK.');
    }

    private function unreadCount(): void
    {
        Helper::jsonResponse(true, '', ['unread_count' => $this->chatModel->totalUnreadCount($this->userId())]);
    }

    // -------------------------------------------------------------
    // Video/audio calls (WebRTC signaling - see app/models/Chat.php)
    // -------------------------------------------------------------

    private const CALL_SDP_MAX_LENGTH = 20000;

    private function callStart(): void
    {
        Security::requireValidCsrfFromRequest();
        $conversationId = (int) ($_POST['conversation_id'] ?? 0);
        $offer = (string) ($_POST['offer'] ?? '');
        if ($conversationId <= 0 || $offer === '' || mb_strlen($offer) > self::CALL_SDP_MAX_LENGTH) {
            Helper::jsonResponse(false, 'Could not start the call.', [], 422);
        }
        [$call, $error] = $this->chatModel->startCall($conversationId, $this->userId(), $offer);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }
        Helper::jsonResponse(true, '', $call);
    }

    /** Polled globally (independent of any open thread) so an incoming call is visible wherever the callee currently is in the app. */
    private function callIncoming(): void
    {
        Helper::jsonResponse(true, '', ['calls' => $this->chatModel->getIncomingCalls($this->userId())]);
    }

    /** Polled by both sides while a call is ringing/connecting - the caller watches for the answer SDP, both sides watch for a decline/hangup. */
    private function callStatus(): void
    {
        $callId = (int) ($_GET['call_id'] ?? 0);
        if ($callId <= 0) {
            Helper::jsonResponse(false, 'Call not found.', [], 404);
        }
        [$call, $error] = $this->chatModel->getCallStatus($callId, $this->userId());
        if ($error) {
            Helper::jsonResponse(false, $error, [], 404);
        }
        Helper::jsonResponse(true, '', ['call' => $call]);
    }

    private function callAnswer(): void
    {
        Security::requireValidCsrfFromRequest();
        $callId = (int) ($_POST['call_id'] ?? 0);
        $answer = (string) ($_POST['answer'] ?? '');
        if ($callId <= 0 || $answer === '' || mb_strlen($answer) > self::CALL_SDP_MAX_LENGTH) {
            Helper::jsonResponse(false, 'Could not answer the call.', [], 422);
        }
        [$call, $error] = $this->chatModel->answerCall($callId, $this->userId(), $answer);
        if ($error) {
            Helper::jsonResponse(false, $error, [], 422);
        }
        Helper::jsonResponse(true, '', ['call' => $call]);
    }

    private function callDecline(): void
    {
        Security::requireValidCsrfFromRequest();
        $callId = (int) ($_POST['call_id'] ?? 0);
        if ($callId > 0) {
            $this->chatModel->declineCall($callId, $this->userId());
        }
        Helper::jsonResponse(true, 'OK.');
    }

    private function callEnd(): void
    {
        Security::requireValidCsrfFromRequest();
        $callId = (int) ($_POST['call_id'] ?? 0);
        if ($callId > 0) {
            $this->chatModel->endCall($callId, $this->userId());
        }
        Helper::jsonResponse(true, 'OK.');
    }

    private function callIceCandidate(): void
    {
        Security::requireValidCsrfFromRequest();
        $callId = (int) ($_POST['call_id'] ?? 0);
        $candidate = (string) ($_POST['candidate'] ?? '');
        if ($callId > 0 && $candidate !== '') {
            $this->chatModel->addIceCandidate($callId, $this->userId(), $candidate);
        }
        Helper::jsonResponse(true, 'OK.');
    }

    private function callIceCandidates(): void
    {
        $callId = (int) ($_GET['call_id'] ?? 0);
        $sinceId = (int) ($_GET['since_id'] ?? 0);
        if ($callId <= 0) {
            Helper::jsonResponse(false, 'Call not found.', [], 404);
        }
        Helper::jsonResponse(true, '', ['candidates' => $this->chatModel->getIceCandidates($callId, $this->userId(), $sinceId)]);
    }
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    SessionManager::start();
    (new ChatController())->dispatch();
}
