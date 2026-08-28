# Paperbell repository instructions

## Required Git and host deployment workflow

For every requested Paperbell code change, use this workflow unless the user explicitly asks for a different one:

1. Make and validate changes in the current working repository.
2. Inspect the working tree and stage only files that belong to the requested change. Never include `.codex-remote-attachments/`, `.codex-work/`, seed SQL files, or other unrelated user files unless the user explicitly includes them.
3. Commit the scoped changes on `main` with a concise commit message and push them to `origin/main` from the working computer.
4. Deploy the pushed commit to the Paperbell host over SSH:
   - SSH target: `herman@192.168.1.8`
   - SSH key: `%USERPROFILE%\.ssh\id_ed25519_paperbell_deploy`
   - Host repository: `C:\xampp\htdocs\paperbell`
5. Before pulling on the host, inspect its branch and working tree. Never overwrite host changes. If the host is dirty, compare the affected files with `origin/main` and preserve them in a named stash before continuing.
6. Update the host using `git pull --ff-only origin main`, then verify that host `HEAD` matches the pushed commit and that the host working tree is clean.
7. Activate the deployed change only as needed:
   - Restart only the exact long-running worker affected by the change; do not restart Apache, MariaDB, or unrelated workers without a reason.
   - If `install-autostart.ps1` changes, run it again on the host and verify the scheduled task.
   - Ordinary PHP web files do not require an Apache restart unless runtime evidence shows otherwise.
8. Verify the relevant host process or endpoint after deployment and report the commit hash and deployment result.

Use PowerShell `-EncodedCommand` for multi-line SSH commands to the Windows host. Keep the dedicated deployment key private and never print its contents.

## GitHub read/write key routing

This repository deliberately uses different SSH credentials for pushing from WSL and pulling on the Windows host.

- GitHub write key in WSL: `/home/herman/.ssh/paperbell_github_write`
- GitHub read-only key on Windows: `C:/Users/Herman/.ssh/paperbell_github_readonly`
- SSH deployment key in WSL: `/home/herman/.ssh/id_ed25519_paperbell_deploy`

The repository's local `core.sshCommand` points to the Windows read-only key so `git pull` works when Git runs on the host. Do not replace that setting with the WSL path: `.git/config` is shared with the Windows checkout and Windows cannot resolve `/home/herman/...`.

To push from the WSL working computer, override the SSH command for that invocation only:

```bash
git -c core.sshCommand="ssh -i /home/herman/.ssh/paperbell_github_write -o IdentitiesOnly=yes" push origin main
```

The expected successful output includes `main -> main`. If Git reports that the key is read-only, first confirm that the command above is using `paperbell_github_write`, not `paperbell_github_readonly`. Never copy a private key to the repository, print its contents, or commit it.

For host inspection and deployment, connect from WSL with:

```bash
ssh -o BatchMode=yes -o ConnectTimeout=8 herman@192.168.1.8 "powershell.exe -NoProfile -NonInteractive -EncodedCommand <BASE64>"
```

`<BASE64>` must be the UTF-16LE Base64 encoding of the PowerShell script. Keep read-only inspection, `git pull --ff-only origin main`, and post-deployment verification explicit in that script. The host's existing read-only GitHub key is sufficient for fetch and pull; the WSL write key is only needed for push.
