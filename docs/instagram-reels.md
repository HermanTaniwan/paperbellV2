# Instagram Reels MVP

Open Paperbell → Bisnis & Analitik → Instagram Reels, or `/#instagram-reels`.
Uses the existing Paperbell session. One Instagram account, draft editing, optional
local MP4/MOV preview, caption, public video URL, scheduling in Asia/Jakarta,
cancel/delete before processing, and publication status. No analytics or OAuth
redirect login in this MVP; connect using an existing Meta Page Access Token.

## Connect

Use an Instagram Business/Creator account linked to a Facebook Page and a Meta
developer app. Obtain the Instagram User ID and Page Access Token as described in
[Meta's official collection](https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api).
Permissions: `instagram_basic`, `instagram_content_publish`, `pages_read_engagement`;
`pages_show_list` is also needed when discovering Pages. App roles/review and token
validity are managed in Meta. Enter ID, supported Graph version (default `v25.0`),
and token in the connection form. Verification reads `id,username`; it does not
test content-publishing permission. Token expiry/revocation surfaces when posting.
Renew tokens manually through the same form. Never paste tokens into chat or Git.

The worker uses Facebook Login Graph endpoints: create `/USER/media` with
`media_type=REELS`, poll `/CONTAINER?fields=status_code`, then call
`/USER/media_publish` once FINISHED. Upload locally for preview, but supply a
direct HTTPS video URL reachable by Meta without login for publication.
XAMPP loopback/private URLs cannot be used as public video URLs. Host the video
on a public CDN/storage service yourself. Use MP4/MOV with supported codecs;
9:16 is recommended. The local preview limit is 100 MB and PHP's configured
`upload_max_filesize`/`post_max_size` may impose a lower limit.

## Scheduler

Run `tools/install-instagram-reels-task.ps1` in PowerShell to install the single
`Paperbell Instagram Reels` Windows task. It runs the CLI worker every minute.
If S4U registration is unavailable, the fallback runs while the Windows user is
logged in. Check task status and the studio's heartbeat. The PC and internet must
remain available; overdue scheduled posts run on the next worker pass.
Processing at Meta adds delay; scheduling is not an exact publication guarantee.
No API posting occurs without a saved account and scheduled due content.

Manual check: `C:\xampp\php\php.exe tools/process-instagram-reels.php`.
The worker handles at most five items per pass; overlapping runs are locked out.
No service restart is necessary. To disable, disable this Windows task.

State lives in ignored, web-denied `storage/instagram-reels/`: SQLite database,
encrypted credentials, encryption key, preview videos, lock, heartbeat. Back up
this directory confidentially; credentials require the key from the same backup.
Preview streaming requires a Paperbell session; mutations require a session CSRF
token. Paperbell should be used over a trusted network/HTTPS when entering tokens.

Processing/published posts are read-only. Disconnecting converts unprocessed
schedules to drafts and pauses processing. Reconnecting the same account resumes
processing. Account switching is blocked while schedules or processing/review
items remain. Review items require checking Instagram manually; no automatic
retry is performed after an uncertain create/publish request or interrupted
worker. After checking, create a new draft only if the Reel was not published.
Reinstallation or task registration never clears the queue.

## Verification

`C:\xampp\php\php.exe tests/InstagramReelsServiceTest.php`
tests scheduling validation, WIB conversion, publication steps, retries,
interruption handling, and encrypted credentials using a fake Meta transport.
These tests never publish to an Instagram account. A live publication test needs
the owner's account, token, public video URL, and an explicitly scheduled Reel.
