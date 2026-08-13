$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$mysqlAdmin = 'C:\xampp\mysql\bin\mysqladmin.exe'
$mysqlExecutable = 'C:\xampp\mysql\bin\mysqld.exe'
$mysqlConfig = 'C:\xampp\mysql\bin\my.ini'
$mysqlErrorLog = 'C:\xampp\mysql\data\mysql_error.log'
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

function Test-MariaDbReady {
    & $mysqlAdmin -h 127.0.0.1 -P 3306 -u root --connect-timeout=1 ping --silent 2>$null | Out-Null
    return $LASTEXITCODE -eq 0
}

if (-not (Get-Process -Name httpd -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath 'C:\xampp\apache\bin\httpd.exe' -WorkingDirectory 'C:\xampp\apache\bin' -WindowStyle Hidden
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

$worker = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*print-worker.php*' }
if (-not $worker) {
    Start-Process -FilePath 'C:\xampp\php\php.exe' `
        -ArgumentList "`"$root\worker\print-worker.php`"" `
        -WorkingDirectory $root -WindowStyle Hidden
}

$labelWorker = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*label-worker.php*' }
if (-not $labelWorker) {
    Start-Process -FilePath 'C:\xampp\php\php.exe' `
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
