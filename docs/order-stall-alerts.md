# Order status risk notifications

The bell includes orders that remain in a pre-shipment status for **more than
24 counted hours**. Every hour on Monday-Friday counts, including overnight;
Saturday, Sunday and dates in Paperbell's `store_holidays` list count as zero.
For example, Friday 10:00 to Monday 10:00 is exactly 24 counted hours and is
not yet above the threshold. Store holidays are managed in the existing app.

Eligible statuses:

- Shopee: `READY_TO_SHIP`, `PROCESSED`.
- TikTok/Tokopedia: `AWAITING_SHIPMENT`, `AWAITING_COLLECTION`.

Manual, unpaid, cancellation, shipped and completed orders are excluded.
These are internal reminders based on the requested threshold, not marketplace
cancellation deadline guarantees.

The ordinary marketplace sync records status observations. A status change
resets the timer; an unrelated update to the same status does not. Legacy
orders have no historical status transitions. Their initial timer uses the
latest verified marketplace update as a conservative baseline. Out-of-order
responses cannot overwrite a newer observed status.

`tools/check-order-stalls.php` verifies saved pre-shipment candidates through
read-only marketplace detail APIs. Missing/unverified orders do not produce
alerts. Failed scans preserve the previous snapshot. No label, print, shipping,
approval or cancellation actions are triggered. The bell reads only the saved
snapshot; it never makes these remote calls on click. A normal sync that observes
a changed status removes its old risk from the bell immediately.

The Windows task **Paperbell Order Stall Alerts** runs Monday-Friday at
09:00, 12:00 and 15:00 WIB. The PHP checker also skips configured store holidays.
Install/update with `tools/install-order-stall-task.ps1`. If Windows denies S4U
batch logon, the installer falls back to an interactive task, which requires
the same Windows user to remain logged in. Windows must be powered on; missed
checks run when the task becomes available. No other tasks are restarted.

Read state is stored per app user and status episode. Seeing a reminder clears
the badge for that episode, including later scheduled checks. A new qualifying
status episode can notify again. Resolved risk reminders leave the bell.

Verification:

```powershell
C:\xampp\php\php.exe tests/OrderStallAlertServiceTest.php
C:\xampp\php\php.exe tests/AppNotificationServiceTest.php
node tests/ShippingFeeAlertsTest.js
C:\xampp\php\php.exe tools/check-order-stalls.php
Get-ScheduledTaskInfo -TaskName 'Paperbell Order Stall Alerts'
```

Tests use connection-local temporary tables and do not modify saved orders.
