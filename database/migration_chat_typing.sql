/* =========================================================================
   database/migration_chat_typing.sql
   -------------------------------------------------------------------------
   Adds "X is typing..." support to the chat widget. Reuses
   dbo.ChatParticipants as the place to record it: typing_at is stamped to
   "now" whenever a user is actively typing in that conversation, and
   read back by the other participant(s) as long as it's recent (see
   Chat::setTyping() / Chat::getTypingUsers()). No separate table needed -
   it's short-lived, per-participant state, exactly like last_read_at.

   Safe to re-run - only adds what's missing.
   ========================================================================= */

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.ChatParticipants') AND name = 'typing_at'
)
ALTER TABLE dbo.ChatParticipants ADD typing_at DATETIME NULL;
GO
