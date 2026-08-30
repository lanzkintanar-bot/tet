<?php
/**
 * app/models/Chat.php
 * -----------------------------------------------------------------------
 * Internal chat between Staff/Cashiers and Owner/Manager (see
 * database/migration_chat.sql for the schema and the reasoning behind
 * it). Two kinds of conversation:
 *   - 'general': one shared channel every logged-in user can read/post
 *     to, regardless of whether a ChatParticipants row exists for them
 *     yet (that row only tracks their personal read cursor, added
 *     lazily the first time they open the panel).
 *   - 'direct': a private 1-on-1 thread between exactly two users,
 *     created on demand. Cashier/Staff accounts may only start one with
 *     an Administrator or Manager (this is a support channel, not a
 *     general-purpose staff-to-staff DM tool); Administrators and
 *     Managers may start one with anyone.
 */

if (!defined('POS_APP')) {
    die('Direct access not permitted.');
}

class Chat
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    private static ?bool $chatTablesAvailable = null;

    public function isAvailable(): bool
    {
        if (self::$chatTablesAvailable === null) {
            try {
                $stmt = $this->db->query("SELECT OBJECT_ID('dbo.ChatConversations', 'U') AS id");
                self::$chatTablesAvailable = !empty($stmt->fetch()['id']);
            } catch (\Throwable $e) {
                self::$chatTablesAvailable = false;
            }
        }
        return self::$chatTablesAvailable;
    }

    private static ?bool $callTablesAvailable = null;

    /** Separate from isAvailable() so chat keeps working on a DB where migration_chat_video_calls.sql hasn't been run yet - only the call-specific actions need this. */
    public function isCallAvailable(): bool
    {
        if (self::$callTablesAvailable === null) {
            try {
                $stmt = $this->db->query("SELECT OBJECT_ID('dbo.ChatCalls', 'U') AS id");
                self::$callTablesAvailable = !empty($stmt->fetch()['id']);
            } catch (\Throwable $e) {
                self::$callTablesAvailable = false;
            }
        }
        return self::$callTablesAvailable;
    }

    private function generalConversationId(): int
    {
        $stmt = $this->db->query("SELECT TOP 1 conversation_id FROM ChatConversations WHERE type = 'general'");
        $row = $stmt->fetch();
        return $row ? (int) $row['conversation_id'] : 0;
    }

    /** Adds a read-cursor row for $userId in $conversationId if one doesn't exist yet - marked "caught up" at the moment they join, so they're never dropped into months of backlog counted as unread. */
    private function ensureParticipant(int $conversationId, int $userId, bool $caughtUp = true): void
    {
        $stmt = $this->db->prepare(
            "IF NOT EXISTS (SELECT 1 FROM ChatParticipants WHERE conversation_id = :cid AND user_id = :uid)
             INSERT INTO ChatParticipants (conversation_id, user_id, last_read_at) VALUES (:cid2, :uid2, :read_at)"
        );
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':cid2', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':read_at', $caughtUp ? date('Y-m-d H:i:s') : null, $caughtUp ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    }

    private function isCashierRole(string $roleName): bool
    {
        return !in_array($roleName, ['Administrator', 'Manager'], true);
    }

    /** Users the given account is allowed to start a direct conversation with. */
    public function contactsFor(int $userId, string $roleName, string $search = ''): array
    {
        $sql = "SELECT u.user_id, u.full_name, u.username, r.role_name
                FROM Users u
                INNER JOIN Roles r ON r.role_id = u.role_id
                WHERE u.user_id != :self AND u.is_active = 1";
        if ($this->isCashierRole($roleName)) {
            $sql .= " AND r.role_name IN ('Administrator', 'Manager')";
        }
        if ($search !== '') {
            $sql .= " AND u.full_name LIKE :search";
        }
        $sql .= " ORDER BY u.full_name";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':self', $userId, PDO::PARAM_INT);
        if ($search !== '') {
            $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Every conversation (general + direct) this user can currently see, newest activity first, each with its unread count and a one-line preview. */
    public function listConversationsForUser(int $userId): array
    {
        $generalId = $this->generalConversationId();
        if ($generalId) {
            $this->ensureParticipant($generalId, $userId);
        }

        $sql = "SELECT c.conversation_id, c.type,
                       p.last_read_at,
                       other.user_id AS other_user_id, other.full_name AS other_user_name,
                       (SELECT TOP 1 m.body FROM ChatMessages m WHERE m.conversation_id = c.conversation_id ORDER BY m.created_at DESC) AS last_body,
                       (SELECT TOP 1 m.attachment_name FROM ChatMessages m WHERE m.conversation_id = c.conversation_id ORDER BY m.created_at DESC) AS last_attachment_name,
                       (SELECT TOP 1 m.created_at FROM ChatMessages m WHERE m.conversation_id = c.conversation_id ORDER BY m.created_at DESC) AS last_at,
                       (SELECT COUNT(*) FROM ChatMessages m WHERE m.conversation_id = c.conversation_id
                            AND m.sender_id != :self1
                            AND m.created_at > ISNULL(p.last_read_at, '1900-01-01')) AS unread_count
                FROM ChatConversations c
                INNER JOIN ChatParticipants p ON p.conversation_id = c.conversation_id AND p.user_id = :self2
                LEFT JOIN ChatParticipants op ON op.conversation_id = c.conversation_id AND c.type = 'direct' AND op.user_id != :self3
                LEFT JOIN Users other ON other.user_id = op.user_id
                ORDER BY last_at DESC, c.conversation_id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':self1', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':self2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':self3', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['unread_count'] = (int) $row['unread_count'];
            $row['title'] = $row['type'] === 'general' ? 'General' : ($row['other_user_name'] ?? 'Direct message');
        }
        unset($row);

        return $rows;
    }

    /** Sum of unread across every conversation - for the navbar badge, polled far more often than the full list. */
    public function totalUnreadCount(int $userId): int
    {
        $generalId = $this->generalConversationId();
        if ($generalId) {
            $this->ensureParticipant($generalId, $userId);
        }

        $stmt = $this->db->prepare(
            "SELECT ISNULL(SUM(x.unread), 0) AS total FROM (
                SELECT (SELECT COUNT(*) FROM ChatMessages m
                        WHERE m.conversation_id = p.conversation_id
                        AND m.sender_id != :self
                        AND m.created_at > ISNULL(p.last_read_at, '1900-01-01')) AS unread
                FROM ChatParticipants p WHERE p.user_id = :self2
             ) x"
        );
        $stmt->bindValue(':self', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':self2', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetch()['total'];
    }

    /**
     * Finds (or creates) the direct conversation between these two users.
     * Enforces the Cashier/Staff-can-only-DM-Admin/Manager rule.
     * @return array [conversationId|null, error|null]
     */
    public function getOrCreateDirectConversation(int $userId, string $userRoleName, int $otherUserId): array
    {
        if ($otherUserId === $userId) {
            return [null, 'You can\'t message yourself.'];
        }

        $otherStmt = $this->db->prepare(
            "SELECT u.user_id, u.is_active, r.role_name FROM Users u INNER JOIN Roles r ON r.role_id = u.role_id WHERE u.user_id = :id"
        );
        $otherStmt->bindValue(':id', $otherUserId, PDO::PARAM_INT);
        $otherStmt->execute();
        $other = $otherStmt->fetch();
        if (!$other || !$other['is_active']) {
            return [null, 'That user is not available.'];
        }

        if ($this->isCashierRole($userRoleName) && $this->isCashierRole($other['role_name'])) {
            return [null, 'You can only message an Administrator or Manager directly.'];
        }

        $findStmt = $this->db->prepare(
            "SELECT p1.conversation_id
             FROM ChatParticipants p1
             INNER JOIN ChatParticipants p2 ON p2.conversation_id = p1.conversation_id AND p2.user_id = :other
             INNER JOIN ChatConversations c ON c.conversation_id = p1.conversation_id AND c.type = 'direct'
             WHERE p1.user_id = :self"
        );
        $findStmt->bindValue(':self', $userId, PDO::PARAM_INT);
        $findStmt->bindValue(':other', $otherUserId, PDO::PARAM_INT);
        $findStmt->execute();
        $existing = $findStmt->fetch();
        if ($existing) {
            return [(int) $existing['conversation_id'], null];
        }

        $this->db->beginTransaction();
        try {
            $insertStmt = $this->db->prepare("INSERT INTO ChatConversations (type) OUTPUT INSERTED.conversation_id VALUES ('direct')");
            $insertStmt->execute();
            $conversationId = (int) $insertStmt->fetch()['conversation_id'];

            // The starter is caught up as of now (they're the one opening
            // it); the other side has never read it, so the first message
            // correctly counts as unread for them.
            $this->ensureParticipant($conversationId, $userId, true);
            $this->ensureParticipant($conversationId, $otherUserId, false);

            $this->db->commit();
            return [$conversationId, null];
        } catch (Exception $e) {
            $this->db->rollBack();
            return [null, 'Could not start that conversation. Please try again.'];
        }
    }

    /** Whether $userId may read/post in $conversationId right now. */
    private function canAccess(int $conversationId, int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT type FROM ChatConversations WHERE conversation_id = :id");
        $stmt->bindValue(':id', $conversationId, PDO::PARAM_INT);
        $stmt->execute();
        $conversation = $stmt->fetch();
        if (!$conversation) {
            return false;
        }
        if ($conversation['type'] === 'general') {
            return true;
        }

        $pStmt = $this->db->prepare("SELECT 1 FROM ChatParticipants WHERE conversation_id = :cid AND user_id = :uid");
        $pStmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $pStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $pStmt->execute();
        return (bool) $pStmt->fetchColumn();
    }

    /**
     * Messages in a conversation, oldest first. Pass $sinceId to get only
     * what's arrived after that message (polling deltas instead of
     * re-fetching the whole thread every few seconds).
     * @return array [messages|null, error|null]
     */
    public function getMessages(int $conversationId, int $userId, ?int $sinceId = null): array
    {
        if (!$this->canAccess($conversationId, $userId)) {
            return [null, 'You don\'t have access to this conversation.'];
        }
        if ($conversationId !== $this->generalConversationId()) {
            $this->ensureParticipant($conversationId, $userId, false);
        }

        $sql = "SELECT m.message_id, m.sender_id, u.full_name AS sender_name, m.body,
                       m.attachment_path, m.attachment_name, m.attachment_mime, m.attachment_size, m.created_at
                FROM ChatMessages m
                INNER JOIN Users u ON u.user_id = m.sender_id
                WHERE m.conversation_id = :cid";
        if ($sinceId !== null) {
            $sql .= " AND m.message_id > :since";
        }
        $sql .= " ORDER BY m.message_id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        if ($sinceId !== null) {
            $stmt->bindValue(':since', $sinceId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $messages = $stmt->fetchAll();

        foreach ($messages as &$m) {
            $m['is_mine'] = (int) $m['sender_id'] === $userId;
            $m['attachment_url'] = $m['attachment_path'] ? CHAT_UPLOAD_URL . $m['attachment_path'] : null;
            unset($m['attachment_path']);
        }
        unset($m);

        return [$messages, null];
    }

    /** Stamps $userId as actively typing in $conversationId right now. Cheap and short-lived on purpose - getTypingUsers() only trusts it for a few seconds. */
    public function setTyping(int $conversationId, int $userId): void
    {
        if (!$this->canAccess($conversationId, $userId)) {
            return;
        }
        $this->ensureParticipant($conversationId, $userId, false);
        $stmt = $this->db->prepare("UPDATE ChatParticipants SET typing_at = GETDATE() WHERE conversation_id = :cid AND user_id = :uid");
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Names of everyone else currently (within the last few seconds) typing in $conversationId, for the "X is typing..." line above the composer. */
    public function getTypingUsers(int $conversationId, int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.full_name FROM ChatParticipants p
             INNER JOIN Users u ON u.user_id = p.user_id
             WHERE p.conversation_id = :cid AND p.user_id != :self
             AND p.typing_at IS NOT NULL AND p.typing_at > DATEADD(SECOND, -5, GETDATE())"
        );
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':self', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return array_column($stmt->fetchAll(), 'full_name');
    }

    public function markRead(int $conversationId, int $userId): void
    {
        if (!$this->canAccess($conversationId, $userId)) {
            return;
        }
        $this->ensureParticipant($conversationId, $userId, true);
        $stmt = $this->db->prepare("UPDATE ChatParticipants SET last_read_at = GETDATE() WHERE conversation_id = :cid AND user_id = :uid");
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array|null $attachment ['path'=>,'name'=>,'mime'=>,'size'=>] already-validated, or null for a text-only message
     * @return array [message|null, error|null]
     */
    public function sendMessage(int $conversationId, int $userId, string $body, ?array $attachment): array
    {
        if (!$this->canAccess($conversationId, $userId)) {
            return [null, 'You don\'t have access to this conversation.'];
        }

        $body = trim($body);
        if ($body === '' && !$attachment) {
            return [null, 'Write a message or attach a file.'];
        }
        if (mb_strlen($body) > 2000) {
            return [null, 'Message is too long (2000 characters max).'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO ChatMessages (conversation_id, sender_id, body, attachment_path, attachment_name, attachment_mime, attachment_size, created_at)
             OUTPUT INSERTED.message_id
             VALUES (:cid, :uid, :body, :apath, :aname, :amime, :asize, GETDATE())"
        );
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':body', $body !== '' ? $body : null, $body !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':apath', $attachment['path'] ?? null, $attachment ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':aname', $attachment['name'] ?? null, $attachment ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':amime', $attachment['mime'] ?? null, $attachment ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':asize', $attachment['size'] ?? null, $attachment ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->execute();
        $messageId = (int) $stmt->fetch()['message_id'];

        // Sending counts as having read up to this point too.
        $this->ensureParticipant($conversationId, $userId, true);
        $this->markRead($conversationId, $userId);

        [$messages] = $this->getMessages($conversationId, $userId, $messageId - 1);
        return [$messages[0] ?? null, null];
    }

    // -------------------------------------------------------------
    // Attachment upload
    // -------------------------------------------------------------

    /** @return array [['path'=>,'name'=>,'mime'=>,'size'=>]|null, error|null] */
    public function handleAttachmentUpload(): array
    {
        if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        $file = $_FILES['attachment'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'File upload failed (error code ' . $file['error'] . ').'];
        }
        if ($file['size'] > MAX_CHAT_UPLOAD_SIZE) {
            $maxMb = round(MAX_CHAT_UPLOAD_SIZE / (1024 * 1024), 1);
            return [null, "File is too large. Maximum size is {$maxMb}MB."];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($realMime, ALLOWED_CHAT_FILE_TYPES, true)) {
            return [null, 'Only images (JPEG, PNG, WEBP, GIF) or PDF files are allowed.'];
        }

        $extensions = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/gif' => 'gif', 'application/pdf' => 'pdf',
        ];
        $extension = $extensions[$realMime] ?? 'bin';
        $filename = 'chat_' . bin2hex(random_bytes(12)) . '.' . $extension;

        if (!is_dir(CHAT_UPLOAD_PATH)) {
            mkdir(CHAT_UPLOAD_PATH, 0755, true);
        }
        if (!move_uploaded_file($file['tmp_name'], CHAT_UPLOAD_PATH . $filename)) {
            return [null, 'Could not save the uploaded file. Check that the uploads folder is writable.'];
        }

        return [[
            'path' => $filename,
            'name' => Security::sanitize(basename($file['name'])),
            'mime' => $realMime,
            'size' => (int) $file['size'],
        ], null];
    }

    // -------------------------------------------------------------
    // Video/audio calls (1-on-1 only - see migration_chat_video_calls.sql)
    // -------------------------------------------------------------

    private const CALL_RING_TIMEOUT_SECONDS = 45;
    private const CALL_STALE_ACTIVE_SECONDS = 20; // how long an 'accepted' call can go unpolled by either side before it's assumed abandoned
    private const CALL_SDP_MAX_LENGTH = 20000;

    /**
     * Self-healing cleanup, run at the top of every call read/write - no
     * cron job needed:
     *   - a 'ringing' call nobody answered in time becomes 'missed'.
     *   - an 'accepted' call neither side has polled recently (see
     *     touchCallActivity()) is assumed abandoned - e.g. a tab closed,
     *     the network dropped, or the browser crashed without a clean
     *     hangup - and gets force-closed so it stops blocking new calls
     *     in that conversation.
     */
    private function expireStaleRingingCalls(): void
    {
        $stmt = $this->db->prepare(
            "UPDATE ChatCalls SET status = 'missed', ended_at = GETDATE()
             WHERE status = 'ringing' AND started_at < DATEADD(SECOND, :ringTimeout, GETDATE())"
        );
        $stmt->bindValue(':ringTimeout', -self::CALL_RING_TIMEOUT_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        $stmt = $this->db->prepare(
            "UPDATE ChatCalls SET status = 'ended', ended_at = GETDATE()
             WHERE status = 'accepted'
             AND (last_activity_at IS NULL OR last_activity_at < DATEADD(SECOND, :activeTimeout, GETDATE()))
             AND (answered_at IS NULL OR answered_at < DATEADD(SECOND, :activeTimeout2, GETDATE()))"
        );
        $stmt->bindValue(':activeTimeout', -self::CALL_STALE_ACTIVE_SECONDS, PDO::PARAM_INT);
        $stmt->bindValue(':activeTimeout2', -self::CALL_STALE_ACTIVE_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Marks $callId as still in use - called every time either side polls it, which happens every ~1.2s while a call is up (see pollCallProgress() in chat.js), so this is a free liveness signal with no extra requests. */
    private function touchCallActivity(int $callId): void
    {
        $stmt = $this->db->prepare("UPDATE ChatCalls SET last_activity_at = GETDATE() WHERE call_id = :id");
        $stmt->bindValue(':id', $callId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** @return array{caller_id:int,callee_id:int}|null */
    private function callParticipants(int $callId): ?array
    {
        $stmt = $this->db->prepare("SELECT caller_id, callee_id FROM ChatCalls WHERE call_id = :id");
        $stmt->bindValue(':id', $callId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? ['caller_id' => (int) $row['caller_id'], 'callee_id' => (int) $row['callee_id']] : null;
    }

    private function formatCall(array $row, int $viewerId): array
    {
        return [
            'call_id' => (int) $row['call_id'],
            'conversation_id' => (int) $row['conversation_id'],
            'caller_id' => (int) $row['caller_id'],
            'callee_id' => (int) $row['callee_id'],
            'is_caller' => (int) $row['caller_id'] === $viewerId,
            'status' => $row['status'],
            'offer_sdp' => $row['offer_sdp'] ?? null,
            'answer_sdp' => $row['answer_sdp'] ?? null,
        ];
    }

    /**
     * Starts a call from $callerId to whoever else is in $conversationId.
     * Only valid for 'direct' conversations - a video call to everyone in
     * 'General' would need real multi-party infrastructure this app
     * doesn't have.
     * @return array [call|null, error|null]
     */
    public function startCall(int $conversationId, int $callerId, string $offerSdp): array
    {
        if (!$this->canAccess($conversationId, $callerId)) {
            return [null, "You don't have access to this conversation."];
        }
        if (mb_strlen($offerSdp) > self::CALL_SDP_MAX_LENGTH) {
            return [null, 'Could not start the call (invalid session data).'];
        }

        $convStmt = $this->db->prepare("SELECT type FROM ChatConversations WHERE conversation_id = :id");
        $convStmt->bindValue(':id', $conversationId, PDO::PARAM_INT);
        $convStmt->execute();
        $conversation = $convStmt->fetch();
        if (!$conversation || $conversation['type'] !== 'direct') {
            return [null, 'Video calls are only available in direct conversations.'];
        }

        $otherStmt = $this->db->prepare(
            "SELECT u.user_id, u.full_name FROM ChatParticipants p
             INNER JOIN Users u ON u.user_id = p.user_id
             WHERE p.conversation_id = :cid AND p.user_id != :self"
        );
        $otherStmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $otherStmt->bindValue(':self', $callerId, PDO::PARAM_INT);
        $otherStmt->execute();
        $other = $otherStmt->fetch();
        if (!$other) {
            return [null, 'The other person in this conversation could not be found.'];
        }
        $calleeId = (int) $other['user_id'];

        $this->expireStaleRingingCalls();

        $existingStmt = $this->db->prepare(
            "SELECT TOP 1 call_id FROM ChatCalls WHERE conversation_id = :cid AND status IN ('ringing', 'accepted')"
        );
        $existingStmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $existingStmt->execute();
        if ($existingStmt->fetch()) {
            return [null, 'A call is already in progress in this conversation.'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO ChatCalls (conversation_id, caller_id, callee_id, status, offer_sdp, started_at)
             OUTPUT INSERTED.call_id
             VALUES (:cid, :caller, :callee, 'ringing', :offer, GETDATE())"
        );
        $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $stmt->bindValue(':caller', $callerId, PDO::PARAM_INT);
        $stmt->bindValue(':callee', $calleeId, PDO::PARAM_INT);
        $stmt->bindValue(':offer', $offerSdp, PDO::PARAM_STR);
        $stmt->execute();
        $callId = (int) $stmt->fetch()['call_id'];

        return [[
            'call_id' => $callId,
            'callee_id' => $calleeId,
            'callee_name' => $other['full_name'],
        ], null];
    }

    /** Ringing calls for $userId to answer - polled globally (chat panel open or not) so an incoming call is never missed just because the widget happens to be closed. */
    public function getIncomingCalls(int $userId): array
    {
        $this->expireStaleRingingCalls();

        $stmt = $this->db->prepare(
            "SELECT c.call_id, c.conversation_id, c.caller_id, u.full_name AS caller_name, c.offer_sdp
             FROM ChatCalls c
             INNER JOIN Users u ON u.user_id = c.caller_id
             WHERE c.callee_id = :uid AND c.status = 'ringing'
             ORDER BY c.started_at DESC"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['call_id'] = (int) $row['call_id'];
            $row['conversation_id'] = (int) $row['conversation_id'];
            $row['caller_id'] = (int) $row['caller_id'];
        }
        unset($row);
        return $rows;
    }

    /**
     * Called by both sides while a call is ringing/connecting/active - the
     * caller uses it to notice the answer SDP the moment the callee picks
     * up, and both sides use it to notice a decline/hangup on the other end.
     * @return array [call|null, error|null]
     */
    public function getCallStatus(int $callId, int $userId): array
    {
        $this->expireStaleRingingCalls();

        $participants = $this->callParticipants($callId);
        if (!$participants || !in_array($userId, [$participants['caller_id'], $participants['callee_id']], true)) {
            return [null, 'Call not found.'];
        }

        $this->touchCallActivity($callId);

        $stmt = $this->db->prepare("SELECT * FROM ChatCalls WHERE call_id = :id");
        $stmt->bindValue(':id', $callId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return [$this->formatCall($row, $userId), null];
    }

    /** @return array [call|null, error|null] */
    public function answerCall(int $callId, int $userId, string $answerSdp): array
    {
        if (mb_strlen($answerSdp) > self::CALL_SDP_MAX_LENGTH) {
            return [null, 'Could not answer the call (invalid session data).'];
        }
        $this->expireStaleRingingCalls();

        $participants = $this->callParticipants($callId);
        if (!$participants || $participants['callee_id'] !== $userId) {
            return [null, 'Call not found.'];
        }

        $stmt = $this->db->prepare(
            "UPDATE ChatCalls SET status = 'accepted', answer_sdp = :answer, answered_at = GETDATE()
             WHERE call_id = :id AND status = 'ringing'"
        );
        $stmt->bindValue(':answer', $answerSdp, PDO::PARAM_STR);
        $stmt->bindValue(':id', $callId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            return [null, 'This call is no longer available.'];
        }

        return $this->getCallStatus($callId, $userId);
    }

    public function declineCall(int $callId, int $userId): void
    {
        $participants = $this->callParticipants($callId);
        if (!$participants || $participants['callee_id'] !== $userId) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE ChatCalls SET status = 'declined', ended_at = GETDATE(), ended_by = :uid
             WHERE call_id = :id AND status = 'ringing'"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $callId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Either side can hang up, whether the call is still ringing (cancel) or already connected. */
    public function endCall(int $callId, int $userId): void
    {
        $participants = $this->callParticipants($callId);
        if (!$participants || !in_array($userId, [$participants['caller_id'], $participants['callee_id']], true)) {
            return;
        }
        $stmt = $this->db->prepare(
            "UPDATE ChatCalls SET status = 'ended', ended_at = GETDATE(), ended_by = :uid
             WHERE call_id = :id AND status IN ('ringing', 'accepted')"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $callId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Trickled ICE candidate from $userId's side of $callId, for the other side to pick up via getIceCandidates(). */
    public function addIceCandidate(int $callId, int $userId, string $candidateJson): void
    {
        if (mb_strlen($candidateJson) > self::CALL_SDP_MAX_LENGTH) {
            return;
        }
        $participants = $this->callParticipants($callId);
        if (!$participants || !in_array($userId, [$participants['caller_id'], $participants['callee_id']], true)) {
            return;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO ChatCallSignals (call_id, sender_id, candidate, created_at) VALUES (:cid, :uid, :candidate, GETDATE())"
        );
        $stmt->bindValue(':cid', $callId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':candidate', $candidateJson, PDO::PARAM_STR);
        $stmt->execute();
    }

    /** ICE candidates from the OTHER side of $callId since $sinceId - each side only ever needs the ones it didn't send itself. */
    public function getIceCandidates(int $callId, int $userId, int $sinceId): array
    {
        $participants = $this->callParticipants($callId);
        if (!$participants || !in_array($userId, [$participants['caller_id'], $participants['callee_id']], true)) {
            return [];
        }
        $this->touchCallActivity($callId);
        $stmt = $this->db->prepare(
            "SELECT signal_id, candidate FROM ChatCallSignals
             WHERE call_id = :cid AND sender_id != :self AND signal_id > :since
             ORDER BY signal_id ASC"
        );
        $stmt->bindValue(':cid', $callId, PDO::PARAM_INT);
        $stmt->bindValue(':self', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $sinceId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
