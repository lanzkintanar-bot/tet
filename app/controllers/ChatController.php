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

        switch ($_REQUEST['action'] ?? '') {
            case 'conversations':  $this->conversations(); break;
            case 'contacts':       $this->contacts(); break;
            case 'open_direct':    $this->openDirect(); break;
            case 'messages':       $this->messages(); break;
            case 'send':           $this->send(); break;
            case 'mark_read':      $this->markRead(); break;
            case 'unread_count':   $this->unreadCount(); break;
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
        Helper::jsonResponse(true, '', ['messages' => $messages]);
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
}

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    SessionManager::start();
    (new ChatController())->dispatch();
}
