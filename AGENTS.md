# Paperbell repository instructions

## Required Git and Ubuntu deployment workflow

For every requested Paperbell code change, use this workflow unless the user explicitly asks for a different one:

1. Make and validate changes in the current working repository.
2. Inspect the working tree and stage only files that belong to the requested change. Never include `.codex-remote-attachments/`, `.codex-work/`, seed SQL files, or other unrelated user files unless the user explicitly includes them.
3. Commit the scoped changes on `main` with a concise commit message and push them to `origin/main` from the working computer.
4. The active Ubuntu web installation is `/var/www/html/paperbell` on the same Ubuntu machine. It is a plain deployed directory, **not a Git checkout**. Do not run `git pull` there and do not recursively copy the repository over it.
5. Before replacing an active file, verify that the server copy has not changed independently. Compare `git hash-object /var/www/html/paperbell/<path>` with `git rev-parse <pre-deploy-commit>:<path>`. If they differ, stop, inspect the difference, and preserve the server copy before proceeding; never silently overwrite it.
6. Copy only the files changed by the scoped commit from the working repository into the matching paths below `/var/www/html/paperbell`. Do not copy `.git`, local work files, configuration/secrets, SQL seeds, or runtime `storage` contents.
7. When a browser asset changes, update its query-string cache buster in `index.php` in the same change (for example `assets/app.js?v=132`) so clients do not keep stale JavaScript or CSS.
8. Activate the deployment only as needed:
   - Ordinary PHP, JavaScript, and CSS files do not require an Apache restart.
   - Restart only the exact systemd worker whose long-running source changed, for example `paperbell-print-worker.service` for `worker/print-worker.php`.
   - If an Ubuntu installer or systemd definition changes, rerun the relevant installer and verify the affected unit explicitly.
   - Do not restart Apache, MariaDB, CUPS, or unrelated workers without runtime evidence and a specific reason.
9. Verify deployment by checking each active file hash against `git rev-parse HEAD:<path>`, running applicable lint/tests, and requesting the local endpoint through `http://127.0.0.1/paperbell/`. For frontend changes, verify the served HTML references the new cache-buster and the served asset contains the change.
10. Confirm local `HEAD` equals `origin/main`, the working tree is clean, and report the full commit hash plus deployment and service/endpoint results.

## GitHub read/write key routing

The current Ubuntu working machine has these Git/deployment characteristics:

- GitHub write key available on Ubuntu: `/home/herman/.ssh/id_ed25519`
- Working Git checkout: `/home/herman/apps/paperbellV2`
- Active web directory: `/var/www/html/paperbell`

To push from Ubuntu, override the SSH command for that invocation only. Do not change the repository's persistent `core.sshCommand`:

```bash
git -c core.sshCommand="ssh -i /home/herman/.ssh/id_ed25519 -o IdentitiesOnly=yes" push origin main
```

The expected successful output includes `main -> main`. Never copy a private key to the repository, print its contents, or commit it.

## Legacy Windows deployment (only when explicitly requested)

The older Windows host workflow remains available but is not the default deployment target:

- SSH target: `herman@192.168.1.8`
- SSH deployment key: `/home/herman/.ssh/id_ed25519_paperbell_deploy`
- Host repository: `C:\xampp\htdocs\paperbell`
- GitHub read-only key on Windows: `C:/Users/Herman/.ssh/paperbell_github_readonly`

Before pulling on Windows, inspect its branch and working tree, preserve any host changes in a named stash, then use `git pull --ff-only origin main`. Verify the host `HEAD`, clean working tree, affected process, and endpoint. Use PowerShell `-EncodedCommand` for multi-line SSH commands and never print key contents.

For host inspection and deployment, connect from WSL with:

```bash
ssh -o BatchMode=yes -o ConnectTimeout=8 herman@192.168.1.8 "powershell.exe -NoProfile -NonInteractive -EncodedCommand <BASE64>"
```

`<BASE64>` must be the UTF-16LE Base64 encoding of the PowerShell script. Keep read-only inspection, `git pull --ff-only origin main`, and post-deployment verification explicit in that script.
