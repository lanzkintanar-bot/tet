# Applying these changes

Four rounds of changes so far — apply in order (each is its own commit, so all four are already included if you use the zip):

1. **`chat-fixes.patch`** — duplicate names, redundant sender name, offline emoji picker, typing indicator, hide widget on POS screen.
2. **`video-call-feature.patch`** — 1-on-1 video calling.
3. **`call-followup-fixes.patch`** — stuck "call already in progress", chat panel showing behind the call screen, missing ringback tone.
4. **`cashier-payment-report.patch`** — new Cashier & Payment Method report with Excel/PDF export.

- **`POS-latest.zip`** — full project source with all four rounds applied, on top of your current `main` (repo `lanzkintanar-bot/tet`).

## Option A — easiest (upload via GitHub's web UI)
1. Unzip `POS-latest.zip`.
2. On GitHub, go to your repo → **Add file → Upload files**, switch the branch dropdown to a **new branch**, name it `feature/chat-video-call`, and drag in the unzipped files.
3. Open a Pull Request from `feature/chat-video-call` into `main`.

## Option B — from your terminal
```bash
git clone https://github.com/lanzkintanar-bot/tet.git
cd tet
git checkout -b feature/chat-video-call
git am /path/to/chat-fixes.patch
git am /path/to/video-call-feature.patch
git am /path/to/call-followup-fixes.patch
git am /path/to/cashier-payment-report.patch
git push -u origin feature/chat-video-call
```
Then open a PR into `main` as usual. If `git am` complains, run `git am --abort` then `git apply --3way /path/to/<file>.patch` and resolve by hand.

## Before you deploy
Run these migrations against your database, in this order (all safe/idempotent — only add what's missing):
```
database/migration_chat_typing.sql
database/migration_chat_video_calls.sql
database/migration_chat_calls_activity.sql
```
(The new report needs no migration — it only reads from tables that already exist.)

**Important:** video calling needs HTTPS (or `localhost`) for camera/microphone access, same as the barcode scanner's camera tab. On plain `http://` over a LAN IP, browsers refuse camera access and calls won't start.

## What changed

### Chat fixes (round 1)
1. **Duplicate/"redundant" names when clicking New message** — the contact picker and the conversation list were rendered stacked on top of each other, so anyone who already had a conversation with you showed up twice.
2. **Sender name shown redundantly in direct messages** — now only shown in the shared **General** channel, not in 1-on-1 threads.
3. **"Could not load emoji"** — `emoji-picker-element` was fetching its data from `cdn.jsdelivr.net`; now vendored locally under `assets/vendor/emoji-picker-element/`.
4. **"X is typing..." indicator** — throttled client-side ping, new `typing_at` column, shown above the composer.
5. **Chat widget hidden on the POS screen only** — every other page unaffected.

### Video calling (round 2)
- A video-call button appears in the header of any **direct (1-on-1) thread** — not in the shared General channel, which would need real multi-party call infrastructure this app doesn't have.
- Clicking it rings the other person with an incoming-call banner (shown site-wide, even if their chat panel is closed), Accept/Decline.
- Accepting opens a full-screen call with mute/camera toggle and hang up.
- Actual audio/video goes directly between the two browsers (WebRTC) — it never passes through your server.
- **Limitation:** only a public STUN server is configured (no TURN relay). This works reliably on the same LAN or most home/office networks, but two people behind separate restrictive/symmetric NATs (some corporate firewalls, different mobile carriers) may fail to connect. Fixing that needs separate TURN server infrastructure (e.g. self-hosted `coturn` or a paid TURN provider), which is outside what this app can set up on its own — let me know if you want help wiring one in later.

### Follow-up fixes (round 3)
1. **Stuck "A call is already in progress in this conversation"** — an accepted call had no way to expire if one side disconnected without a clean hangup (closed tab, lost network, phone locked, browser crash). It now auto-expires ~20 seconds after both sides stop polling it.
2. **Chat messages showing behind the call screen** — the chat panel's stacking order was actually *above* the call overlay; fixed, and the panel is now also explicitly hidden while a call is active (restored afterward if it was open before).
3. **No ringtone while calling** — added a ringback tone for the caller that plays while it's ringing and stops as soon as the other side answers, declines, or the call times out.

### Cashier & Payment Method report (round 4)
A new section at the bottom of the Reports page, with its own filters independent of the main summary above it:
- **Select a cashier** (dropdown auto-populated from whoever actually rang up a sale in the selected range) or leave it on "All Cashiers".
- **Select a payment method** (Cash, GCash, Maya, Card, Check) or leave it on "All Payment Methods".
- **Date range**: Today / Yesterday / This Week / This Month presets, plus a custom from/to date pair.
- A small summary strip (transactions, revenue, average sale) and a breakdown table (cashier, payment method, transactions, revenue) that update together as you change any filter.
- **Export to Excel** and **Export to PDF** as two separate buttons, each reflecting whatever filters are currently applied.

Revenue is read per actual payment method used (not the sale's overall "payment_method" field), so a split-payment sale (e.g. part cash, part GCash) is correctly divided across both methods in the breakdown rather than lumped into an unhelpful "multiple" bucket.
