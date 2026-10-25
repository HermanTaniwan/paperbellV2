# Shopee review replies

The Windows task `Paperbell Shopee Review Replies` was disabled on 2026-10-25
at the user's request. It must stay disabled: the user wants AI-written replies
from a desktop Scheduled task, not keyword/template replies. No AI schedule has
been created yet because scheduling tools were unavailable in the setup chat.
See `shopee-review-ai-task.md` for the ready-to-use Scheduled task prompt.

The CLI only reads reviews unless an explicit AI draft file is supplied:

```
C:\xampp\php\php.exe tools/reply-shopee-reviews.php --dry-run
C:\xampp\php\php.exe tools/reply-shopee-reviews.php --reply-file=storage/shopee-review-replies/ai-drafts-YYYYMMDD.json --dry-run
C:\xampp\php\php.exe tools/reply-shopee-reviews.php --reply-file=storage/shopee-review-replies/ai-drafts-YYYYMMDD.json
```

Draft format: a JSON array of objects containing `comment_id` (string) and
`reply` (string, 1-500 characters). The helper never generates any reply text.
It validates eligibility again immediately before sending and reads it back.
Only written, visible, new four/five-star reviews qualify; existing replies
and repeated reviews for the same buyer/order are excluded.

Runtime state and events are in `storage/shopee-review-replies/`, protected by
the existing storage access restrictions. The original activation cutoff is
preserved. A pending marker is persisted before sending; uncertain sends are
never retried automatically. Inspect pending records against Shopee before
resolving them. The task returns a nonzero exit code on errors. The checkpoint
advances only past resolved groups so omitted reviews are not lost. Pagination must cover the
processing window before any replies are sent.
