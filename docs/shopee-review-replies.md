# Daily Shopee review replies

The Windows task `Paperbell Shopee Review Replies` runs at 09:00 WIB daily.
Install with `powershell.exe -NoProfile -File tools/install-shopee-review-task.ps1`.
It uses the existing encrypted Shopee connection and refreshes tokens as needed.
No Apache or print-worker restart is required.

Only new reviews since first installation are processed: stars 4 or 5, nonempty
text, visible, and no existing seller reply. Multiple product reviews from the
same buyer/order receive one reply. Without an order ID, grouping uses buyer
and review date. Reviews already replied to block the rest of that order.
The replies use Indonesian templates in `ShopeeReviewService::draft`; four-star
or mixed feedback receives an acknowledgement and an invitation to contact the
shop. No AI provider or extra credentials are required.

Run `C:\xampp\php\php.exe tools/reply-shopee-reviews.php --dry-run` to inspect
eligible reviews without sending or changing the checkpoint. Run without the
flag to execute. `--init` creates the initial checkpoint only if none exists.

Runtime files are in `storage/shopee-review-replies/`: state, an exclusive worker
lock, and `events.jsonl`. A pending marker is persisted before each send and the
reply is then read back for verification. An uncertain or failed send remains
pending and is never retried automatically; inspect it against Shopee before
manually resolving its review/group markers. The task returns a nonzero exit
code for failures. Pagination must cover the processing window before sending.

Inspect with `Get-ScheduledTaskInfo -TaskName 'Paperbell Shopee Review Replies'`.
Pause with `Disable-ScheduledTask -TaskName 'Paperbell Shopee Review Replies'`.
The computer must be running and the Windows user must be signed in (a locked
session is fine). Missed starts run when the signed-in session becomes available.
