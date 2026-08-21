[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$logPath = Join-Path $projectRoot 'storage\auto-deploy.log'
$launcher = Join-Path $projectRoot 'start-paperbell.ps1'
$mutex = [Threading.Mutex]::new($false, 'Local\PaperbellAutoDeploy')
$hasLock = $false

function Write-DeployLog([string]$Message) {
    $line = '[{0}] {1}' -f (Get-Date -Format o), $Message
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
}

function Invoke-Git([string[]]$Arguments, [switch]$AllowFailure) {
    $output = & git -C $projectRoot @Arguments 2>&1
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0 -and -not $AllowFailure) {
        throw "git $($Arguments -join ' ') gagal: $($output -join ' ')"
    }
    return [pscustomobject]@{ ExitCode = $exitCode; Output = @($output) }
}

function Stop-PaperbellProcess([string]$Pattern) {
    $processes = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like $Pattern }
    foreach ($process in $processes) {
        Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
        Write-DeployLog "Proses $($process.ProcessId) dihentikan: $Pattern"
    }
}

try {
    $hasLock = $mutex.WaitOne(0)
    if (-not $hasLock) { exit 0 }

    if (-not (Test-Path -LiteralPath $logPath)) {
        New-Item -ItemType File -Path $logPath -Force | Out-Null
    }

    $branch = (Invoke-Git -Arguments @('branch', '--show-current')).Output -join ''
    if ($branch.Trim() -ne 'main') {
        Write-DeployLog "Deploy dilewati: branch aktif bukan main ($($branch.Trim()))."
        exit 0
    }

    $dirty = (Invoke-Git -Arguments @('status', '--porcelain')).Output
    if ($dirty.Count -gt 0) {
        Write-DeployLog 'Deploy dilewati: working tree host memiliki perubahan lokal.'
        exit 0
    }

    Invoke-Git -Arguments @('fetch', '--quiet', 'origin', 'main') | Out-Null
    $before = ((Invoke-Git -Arguments @('rev-parse', 'HEAD')).Output -join '').Trim()
    $target = ((Invoke-Git -Arguments @('rev-parse', 'origin/main')).Output -join '').Trim()
    if ($before -eq $target) { exit 0 }

    $ancestor = Invoke-Git -Arguments @('merge-base', '--is-ancestor', $before, $target) -AllowFailure
    if ($ancestor.ExitCode -ne 0) {
        Write-DeployLog "Deploy dilewati: origin/main bukan fast-forward dari $before."
        exit 0
    }

    $changed = (Invoke-Git -Arguments @('diff', '--name-only', $before, $target)).Output
    Invoke-Git -Arguments @('pull', '--ff-only', '--quiet', 'origin', 'main') | Out-Null
    $after = ((Invoke-Git -Arguments @('rev-parse', 'HEAD')).Output -join '').Trim()
    if ($after -ne $target) {
        throw "HEAD setelah pull ($after) tidak sama dengan origin/main ($target)."
    }

    $restartPrintWorker = $changed -match '^(config\.php|src/(Database|LabelPdfPreparer)\.php|tools/prepare_label_pdf\.py|assets/label-unboxing\.jpeg|worker/print-worker\.php)$'
    $restartLabelWorker = $changed -match '^(config\.php|src/(Database|LabelPdfPreparer|MarketplaceLabelService|MarketplaceOAuthService|OAuthVault)\.php|worker/label-worker\.php)$'
    $restartWatchdog = $changed -match '^worker/print-spooler-watchdog\.ps1$'

    if ($restartPrintWorker) { Stop-PaperbellProcess '*print-worker.php*' }
    if ($restartLabelWorker) { Stop-PaperbellProcess '*label-worker.php*' }
    if ($restartWatchdog) { Stop-PaperbellProcess '*print-spooler-watchdog.ps1*' }

    if ($restartPrintWorker -or $restartLabelWorker -or $restartWatchdog -or ($changed -contains 'start-paperbell.ps1')) {
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $launcher
        if ($LASTEXITCODE -ne 0) { throw 'Launcher Paperbell gagal setelah deployment.' }
    }

    Write-DeployLog "Deploy berhasil: $before -> $after; file berubah: $($changed.Count)."
} catch {
    Write-DeployLog "ERROR: $($_.Exception.Message)"
    exit 1
} finally {
    if ($hasLock) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
