# Paperbell migration to Windows 10 LTSC

This is the handoff for a future agent restoring Paperbell after the Ubuntu
machine has been replaced by Windows 10 LTSC. Do not assume the old Ubuntu disk
will remain available.

## Source of truth and backup layout

- Git source: `git@github.com:HermanTaniwan/paperbellV2.git`, branch `main`.
- Migration snapshot: Google Drive,
  `Paperbell/Backups/windows-ltsc-migration-<timestamp>/`.
- Verify every file against `SHA256SUMS` before using it.
- `database/paperbell.sql.gz` is the logical MariaDB dump.
- `runtime/storage.tar.gz` contains the active application's runtime storage,
  including OAuth material. Keep it private.
- `system/` contains an inventory and copies of Ubuntu configuration for
  reference only. Do not install Linux service files on Windows.
- `source/paperbellV2.bundle` is an offline Git bundle. Prefer cloning GitHub;
  use the bundle if GitHub is unavailable.

## Target layout

Use `C:\xampp\htdocs\paperbell` as the checkout. The current scripts and
defaults expect XAMPP Apache/MariaDB, PHP, Python, SumatraPDF, Google Drive for
desktop, and the printer/scanner drivers installed for the interactive Windows
user. The Google Drive mapping root expected by default is
`H:\My Drive\Paperbell\Print`.

## Restore order

1. Install all Windows updates supported by LTSC, printer/scanner drivers,
   XAMPP with Apache/PHP/MariaDB, Git, Python, SumatraPDF, and Google Drive for
   desktop. Do not start the Paperbell workers yet.
2. Clone `main` into `C:\xampp\htdocs\paperbell`. If offline, run
   `git clone paperbellV2.bundle C:\xampp\htdocs\paperbell` from the backup.
   Record and compare the restored commit with `manifest.txt`.
3. Create Python environments needed by `tools\prepare_label_pdf.py` and
   `tools\adf_scanner.py`. Install packages from `requirements-ubuntu.txt` as a
   starting point, then ensure `pypdf`, `reportlab`, Pillow, `openpyxl`,
   `pytwain`, and `pywin32` are available to the paths configured in
   `config.php`. Adjust the environment variables if Windows usernames or
   Python paths differ.
4. Extract `runtime/storage.tar.gz` into the checkout so the result is
   `C:\xampp\htdocs\paperbell\storage\...`. Do not expose this directory via a
   public internet-facing server. Preserve `storage\secrets\oauth.key`.
5. Start MariaDB only. Create the `paperbell` database with `utf8mb4`, then
   import the dump, for example:

       gzip -dc paperbell.sql.gz | C:\xampp\mysql\bin\mysql.exe -u root paperbell

   Use a Windows gzip implementation or extract the `.gz` first. Configure
   `PAPERBELL_DB_HOST`, `PAPERBELL_DB_PORT`, `PAPERBELL_DB_NAME`,
   `PAPERBELL_DB_USER`, and `PAPERBELL_DB_PASSWORD` to match the restored
   database. Never commit credentials.
6. Review database-backed printer mappings in the web UI after installing the
   Windows queues. Linux queue names in the inventory are historical clues;
   select the exact Windows printer names. Confirm label size, tray/bin,
   duplex, and color behavior with one non-production test page per printer.
7. Confirm Google Drive has completed syncing and that
   `H:\My Drive\Paperbell\Print` exists. If it uses another drive letter, set
   `PAPERBELL_WINDOWS_PRINT_ROOT` rather than changing stored mappings.
8. Test PHP syntax, database access, the home page, OAuth connectivity,
   mapping lookup, PDF preview/preparation, and scanner discovery. Keep print
   queues paused during these checks.
9. From an elevated PowerShell, install MariaDB as a delayed automatic service:

       powershell -ExecutionPolicy Bypass -File .\tools\install-mariadb-service.ps1

10. As the interactive user who owns the printer and Google Drive sessions,
    install the watchdog/autostart and maintenance tasks:

       powershell -ExecutionPolicy Bypass -File .\install-autostart.ps1
       powershell -ExecutionPolicy Bypass -File .\tools\install-backup-task.ps1
       powershell -ExecutionPolicy Bypass -File .\tools\install-server-health-task.ps1

11. Resume printers one at a time. Verify label printing, Brother B5/tray
    behavior, Epson label alignment, ordinary product PDF printing, and ADF
    scanning. Check `storage\print-worker.log` and the Paperbell health panel.
12. Reboot Windows and verify Apache, `PaperbellMariaDB`, scheduled tasks,
    Google Drive, both workers, printer detection, database access, and the LAN
    URL. Only after this cold-start test is successful should the old Ubuntu
    installation be erased.

## Acceptance checklist for the restoring agent

- Restored Git commit matches the migration manifest or a documented newer
  commit on `origin/main`.
- Database import completes without errors and table/row counts are plausible.
- OAuth secrets exist and marketplace sync succeeds without reauthorization,
  or reauthorization is completed intentionally.
- Stored mapping paths resolve through the Windows Google Drive root.
- The web endpoint is reachable locally and from an authorized LAN device.
- Print and label workers remain healthy across a reboot.
- Each physical printer and the scanner passes a controlled test.
- A fresh Windows-side database backup is produced and can be read.

Do not delete the migration snapshot until the Windows installation has passed
all acceptance checks and at least one additional copy of the database exists.
