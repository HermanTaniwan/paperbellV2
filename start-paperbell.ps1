$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$mysqlAdmin = 'C:\xampp\mysql\bin\mysqladmin.exe'
$mysqlExecutable = 'C:\xampp\mysql\bin\mysqld.exe'
$mysqlConfig = 'C:\xampp\mysql\bin\my.ini'
$mysqlErrorLog = 'C:\xampp\mysql\data\mysql_error.log'
$apacheExecutable = 'C:\xampp\apache\bin\httpd.exe'
$apacheHealthUrl = 'http://127.0.0.1/paperbell/assets/app.js'
$phpConsoleExecutable = 'C:\xampp\php\php.exe'
$phpBackgroundExecutable = 'C:\xampp\php\php-win.exe'
$workerPhpExecutable = if (Test-Path -LiteralPath $phpBackgroundExecutable -PathType Leaf) {
    $phpBackgroundExecutable
}
else {
    $phpConsoleExecutable
}
$logDirectory = Join-Path $root 'storage\logs'
$startupLog = Join-Path $logDirectory 'startup.log'

New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

function Write-StartupLog([string]$Message) {
    Add-Content -LiteralPath $startupLog `
        -Value ('{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) `
        -Encoding UTF8
}

function Get-PaperbellMariaDbService {
    foreach ($serviceName in @('PaperbellMariaDB', 'mysql', 'mariadb')) {
        $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
        if ($service) {
            return $service
        }
    }

    return $null
}

function Test-ApacheReady {
    try {
        $response = Invoke-WebRequest `
            -UseBasicParsing `
            -Method Head `
            -Uri $apacheHealthUrl `
            -TimeoutSec 4
        return [int]$response.StatusCode -ge 200 -and [int]$response.StatusCode -lt 400
    }
    catch {
        return $false
    }
}

function Start-PaperbellApache {
    Start-Process -FilePath $apacheExecutable `
        -WorkingDirectory 'C:\xampp\apache\bin' `
        -WindowStyle Hidden

    for ($attempt = 1; $attempt -le 15; $attempt++) {
        if (Test-ApacheReady) {
            return $true
        }
        Start-Sleep -Seconds 1
    }

    return $false
}

function Test-MariaDbReady {
    & $mysqlAdmin -h 127.0.0.1 -P 3306 -u root --connect-timeout=1 ping --silent 2>$null | Out-Null
    return $LASTEXITCODE -eq 0
}

$apacheProcesses = @(Get-Process -Name httpd -ErrorAction SilentlyContinue)
if ($apacheProcesses.Count -eq 0) {
    if (-not (Start-PaperbellApache)) {
        Write-StartupLog 'ERROR Apache dimulai tetapi belum merespons setelah 15 detik.'
        throw 'Apache belum siap setelah 15 detik.'
    }
}
elseif (-not (Test-ApacheReady)) {
    # Confirm the failure so one slow request does not cause an unnecessary restart.
    Start-Sleep -Seconds 2
    if (-not (Test-ApacheReady)) {
        Write-StartupLog 'Apache terdeteksi aktif tetapi tidak merespons; memulai ulang Apache.'
        & $apacheExecutable -k shutdown 2>$null
        Start-Sleep -Seconds 5
        Get-Process -Name httpd -ErrorAction SilentlyContinue | Stop-Process -Force

        if (-not (Start-PaperbellApache)) {
            Write-StartupLog 'ERROR Apache belum merespons setelah dimulai ulang.'
            throw 'Apache gagal pulih setelah dimulai ulang.'
        }
        Write-StartupLog 'Apache kembali merespons setelah dimulai ulang.'
    }
}

$mysqlStartAttempted = $false
if (-not (Test-MariaDbReady)) {
    $mysqlStartAttempted = $true
    $mysqlService = Get-PaperbellMariaDbService
    if ($mysqlService) {
        if ($mysqlService.Status -ne 'Running') {
            Write-StartupLog "Memulai MariaDB melalui Windows Service '$($mysqlService.Name)'."
            Start-Service -Name $mysqlService.Name
        }
    }
    else {
        Write-StartupLog 'Windows Service MariaDB belum terpasang; memakai launcher proses sebagai fallback.'
        Start-Process -FilePath $mysqlExecutable `
            -ArgumentList "--defaults-file=$mysqlConfig", '--standalone', '--console' `
            -WorkingDirectory 'C:\xampp\mysql\bin' -WindowStyle Hidden
    }
}

# A listening port alone does not mean MariaDB has finished crash recovery and
# is ready to accept queries. Starting the worker before this point makes it
# exit immediately during Windows startup.
$mysqlReady = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    if (Test-MariaDbReady) {
        $mysqlReady = $true
        break
    }
    Start-Sleep -Seconds 1
}

if (-not $mysqlReady) {
    $diagnostic = ''
    if (Test-Path -LiteralPath $mysqlErrorLog) {
        $diagnostic = Get-Content -LiteralPath $mysqlErrorLog -Tail 80 |
            Where-Object { $_ -match '\[ERROR\]|Aria|mysql\.plugin|Aborting' } |
            Select-Object -Last 5 |
            Out-String
    }
    $diagnostic = $diagnostic.Trim()
    Write-StartupLog "ERROR MariaDB belum siap setelah 60 detik. $diagnostic"
    throw "MariaDB belum siap setelah 60 detik; worker tidak dijalankan. Periksa $mysqlErrorLog"
}

if ($mysqlStartAttempted) {
    Write-StartupLog 'MariaDB siap menerima koneksi.'
}

$phpWorkers = Get-CimInstance Win32_Process -Filter "Name = 'php.exe' OR Name = 'php-win.exe'" -ErrorAction SilentlyContinue
$worker = $phpWorkers | Where-Object { $_.CommandLine -like '*print-worker.php*' }
if (-not $worker) {
    Start-Process -FilePath $workerPhpExecutable `
        -ArgumentList "`"$root\worker\print-worker.php`"" `
        -WorkingDirectory $root -WindowStyle Hidden
}

$labelWorker = $phpWorkers | Where-Object { $_.CommandLine -like '*label-worker.php*' }
if (-not $labelWorker) {
    Start-Process -FilePath $workerPhpExecutable `
        -ArgumentList "`"$root\worker\label-worker.php`"" `
        -WorkingDirectory $root -WindowStyle Hidden
}

$spoolerWatchdogScript = Join-Path $root 'worker\print-spooler-watchdog.ps1'
$spoolerWatchdog = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*print-spooler-watchdog.ps1*' }
if (-not $spoolerWatchdog) {
    Start-Process -FilePath 'powershell.exe' `
        -ArgumentList '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', "`"$spoolerWatchdogScript`"" `
        -WorkingDirectory $root -WindowStyle Hidden
}

Write-Host 'Paperbell Web aktif di http://app.paperbell.id/' -ForegroundColor Green
