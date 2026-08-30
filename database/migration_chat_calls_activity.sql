/* =========================================================================
   database/migration_chat_calls_activity.sql
   -------------------------------------------------------------------------
   Fixes a stuck "A call is already in progress in this conversation"
   error: an accepted call had no way to expire on its own if one side
   disconnected without a clean hangup (closed the tab, lost network,
   phone locked, browser crashed - beforeunload/sendBeacon doesn't fire
   reliably in any of those), leaving the row 'accepted' forever and
   blocking every future call attempt in that conversation.

   last_activity_at is refreshed every time either side polls
   call_status or call_ice_candidates for that call (both already poll
   every ~1.2s while a call is up - see assets/js/chat.js) - it's a
   free liveness signal, no extra client requests needed. An 'accepted'
   call nobody has polled in the last 20 seconds is assumed abandoned
   and gets force-closed (see Chat::expireStaleCalls()).

   Safe to re-run - only adds what's missing.
   ========================================================================= */

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.ChatCalls') AND name = 'last_activity_at'
)
ALTER TABLE dbo.ChatCalls ADD last_activity_at DATETIME NULL;
GO
