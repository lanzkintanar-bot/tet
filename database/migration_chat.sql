/* =========================================================================
   database/migration_chat.sql
   -------------------------------------------------------------------------
   Adds internal chat between Staff/Cashiers and Owner/Manager:

     1. dbo.ChatConversations - a conversation is either:
          - type='general': ONE shared channel every logged-in user can
            read and post to. Exactly one row of this type ever exists
            (seeded below).
          - type='direct': a private 1-on-1 thread between exactly two
            specific users, created on demand the first time either one
            messages the other.
     2. dbo.ChatParticipants - who's "in" a conversation, and where
        their read cursor is (last_read_at), which is how unread counts
        and the badge on the chat button are computed. For 'direct'
        conversations this also doubles as the access-control list -
        only participants may read/post. For 'general', ANY logged-in
        user may read/post regardless of whether a participant row
        exists yet for them - a row is only added lazily, the first
        time they open it, purely to track their personal read cursor.
     3. dbo.ChatMessages - the messages themselves. A message may carry
        a single attachment (image or PDF) alongside or instead of text.

   Existing users are seeded into the general channel with last_read_at
   = now, so nobody is greeted with months of "unread" history the
   moment this ships - anyone created afterwards gets the same
   treatment lazily the first time they open the chat panel (see
   Chat::listConversationsForUser()).

   Safe to re-run - only adds what's missing.
   ========================================================================= */

IF OBJECT_ID('dbo.ChatConversations', 'U') IS NULL
CREATE TABLE dbo.ChatConversations (
    conversation_id INT IDENTITY(1,1) PRIMARY KEY,
    type            NVARCHAR(10) NOT NULL DEFAULT 'direct',
    created_at      DATETIME NOT NULL DEFAULT GETDATE()
);
GO

IF OBJECT_ID('dbo.ChatParticipants', 'U') IS NULL
CREATE TABLE dbo.ChatParticipants (
    conversation_id INT NOT NULL,
    user_id         INT NOT NULL,
    last_read_at    DATETIME NULL,
    joined_at       DATETIME NOT NULL DEFAULT GETDATE(),
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT FK_ChatParticipants_Conversation FOREIGN KEY (conversation_id) REFERENCES dbo.ChatConversations(conversation_id) ON DELETE CASCADE,
    CONSTRAINT FK_ChatParticipants_User FOREIGN KEY (user_id) REFERENCES dbo.Users(user_id)
);
GO

IF OBJECT_ID('dbo.ChatMessages', 'U') IS NULL
CREATE TABLE dbo.ChatMessages (
    message_id       INT IDENTITY(1,1) PRIMARY KEY,
    conversation_id  INT NOT NULL,
    sender_id        INT NOT NULL,
    body             NVARCHAR(2000) NULL,
    attachment_path  NVARCHAR(255) NULL,
    attachment_name  NVARCHAR(255) NULL,
    attachment_mime  NVARCHAR(100) NULL,
    attachment_size  INT NULL,
    created_at       DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT FK_ChatMessages_Conversation FOREIGN KEY (conversation_id) REFERENCES dbo.ChatConversations(conversation_id) ON DELETE CASCADE,
    CONSTRAINT FK_ChatMessages_Sender FOREIGN KEY (sender_id) REFERENCES dbo.Users(user_id)
);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ChatMessages_Conversation')
CREATE INDEX IX_ChatMessages_Conversation ON dbo.ChatMessages(conversation_id, created_at);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ChatParticipants_User')
CREATE INDEX IX_ChatParticipants_User ON dbo.ChatParticipants(user_id);
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ChatConversations WHERE type = 'general')
INSERT INTO dbo.ChatConversations (type) VALUES ('general');
GO

INSERT INTO dbo.ChatParticipants (conversation_id, user_id, last_read_at)
SELECT c.conversation_id, u.user_id, GETDATE()
FROM dbo.ChatConversations c
CROSS JOIN dbo.Users u
WHERE c.type = 'general'
AND NOT EXISTS (
    SELECT 1 FROM dbo.ChatParticipants p
    WHERE p.conversation_id = c.conversation_id AND p.user_id = u.user_id
);
GO
