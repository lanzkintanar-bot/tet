/* =========================================================================
   database/migration_chat_video_calls.sql
   -------------------------------------------------------------------------
   Adds 1-on-1 video/audio calling to the chat widget, on top of the
   existing dbo.ChatConversations / dbo.ChatParticipants tables (see
   migration_chat.sql). Scoped to 'direct' conversations only - a video
   call between everyone in the shared 'General' channel would need
   real multi-party (mesh/SFU) infrastructure, not just this.

     1. dbo.ChatCalls - one row per call attempt. caller_id rings
        callee_id; status moves ringing -> accepted/declined/missed,
        then -> ended. offer_sdp/answer_sdp hold the WebRTC session
        descriptions exchanged during setup (see app/models/Chat.php
        startCall()/answerCall()).
     2. dbo.ChatCallSignals - trickled ICE candidates exchanged while a
        call is connecting. Short-lived: rows are only ever read by the
        other side of that specific call and cascade-delete with it.

   Actual audio/video never touches this server - it flows directly
   between the two browsers (WebRTC), using a public STUN server to
   discover each side's reachable address. This DOES NOT include a TURN
   server: on networks with restrictive/symmetric NAT (common behind
   some corporate firewalls) a direct connection can fail to establish
   even though signaling succeeds - that needs a TURN relay, which is
   infrastructure outside of what this app can provide on its own.

   Safe to re-run - only adds what's missing.
   ========================================================================= */

IF OBJECT_ID('dbo.ChatCalls', 'U') IS NULL
CREATE TABLE dbo.ChatCalls (
    call_id         INT IDENTITY(1,1) PRIMARY KEY,
    conversation_id INT NOT NULL,
    caller_id       INT NOT NULL,
    callee_id       INT NOT NULL,
    status          NVARCHAR(20) NOT NULL DEFAULT 'ringing', -- ringing | accepted | declined | missed | ended
    offer_sdp       NVARCHAR(MAX) NULL,
    answer_sdp      NVARCHAR(MAX) NULL,
    started_at      DATETIME NOT NULL DEFAULT GETDATE(),
    answered_at     DATETIME NULL,
    ended_at        DATETIME NULL,
    ended_by        INT NULL,
    CONSTRAINT FK_ChatCalls_Conversation FOREIGN KEY (conversation_id) REFERENCES dbo.ChatConversations(conversation_id) ON DELETE CASCADE,
    CONSTRAINT FK_ChatCalls_Caller FOREIGN KEY (caller_id) REFERENCES dbo.Users(user_id),
    CONSTRAINT FK_ChatCalls_Callee FOREIGN KEY (callee_id) REFERENCES dbo.Users(user_id)
);
GO

IF OBJECT_ID('dbo.ChatCallSignals', 'U') IS NULL
CREATE TABLE dbo.ChatCallSignals (
    signal_id  INT IDENTITY(1,1) PRIMARY KEY,
    call_id    INT NOT NULL,
    sender_id  INT NOT NULL,
    candidate  NVARCHAR(MAX) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT FK_ChatCallSignals_Call FOREIGN KEY (call_id) REFERENCES dbo.ChatCalls(call_id) ON DELETE CASCADE
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ChatCalls_Callee_Status')
CREATE INDEX IX_ChatCalls_Callee_Status ON dbo.ChatCalls(callee_id, status);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ChatCallSignals_Call')
CREATE INDEX IX_ChatCallSignals_Call ON dbo.ChatCallSignals(call_id, signal_id);
GO
