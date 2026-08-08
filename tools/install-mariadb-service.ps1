#Requires -RunAsAdministrator

[CmdletBinding()]
param(
    [string]$ServiceName = 'PaperbellMariaDB'
)

$ErrorActionPreference = 'Stop'

$mysqlExecutable = 'C:\xampp\mysql\bin\mysqld.exe'
$mysqlAdmin = 'C:\xampp\mysql\bin\mysqladmin.exe'
$mysqlConfig = 'C:\xampp\mysql\bin\my.ini'
$backupScript = Join-Path $PSScriptRoot 'backup-mysql.ps1'

foreach ($requiredFile in @($mysqlExecutable, $mysqlAdmin, $mysqlConfig, $backupScript)) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "File yang diperlukan tidak ditemukan: $requiredFile"
    }
}

$existingService = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $existingService) {
    & $mysqlExecutable --install $ServiceName "--defaults-file=$mysqlConfig"
    if ($LASTEXITCODE -ne 0) {
        throw "Instalasi service $ServiceName gagal dengan exit code $LASTEXITCODE"
    }
}

Set-Service -Name $ServiceName -StartupType Automatic
& sc.exe config $ServiceName start= delayed-auto | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "Gagal mengatur delayed start untuk service $ServiceName"
}

# Restart maksimal dua kali. Kegagalan selanjutnya dibiarkan berhenti agar
# kerusakan storage tidak ditimpa oleh loop restart tanpa batas.
& sc.exe failure $ServiceName reset= 86400 actions= 'restart/60000/restart/60000/""/0' | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "Gagal mengatur recovery policy untuk service $ServiceName"
}

$runningProcess = Get-Process -Name mysqld -ErrorAction SilentlyContinue
if ($runningProcess -and (Get-Service -Name $ServiceName).Status -ne 'Running') {
    Write-Output 'Membuat backup terakhir sebelum memindahkan MariaDB ke Windows Service...'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $backupScript
    if ($LASTEXITCODE -ne 0) {
        throw 'Backup pra-migrasi gagal; proses MariaDB tidak dihentikan.'
    }

    & $mysqlAdmin -h 127.0.0.1 -P 3306 -u root shutdown
    if ($LASTEXITCODE -ne 0) {
        throw 'MariaDB aktif tidak dapat dihentikan secara normal.'
    }

    $runningProcess | Wait-Process -Timeout 30 -ErrorAction SilentlyContinue
}

Start-Service -Name $ServiceName

$ready = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    & $mysqlAdmin -h 127.0.0.1 -P 3306 -u root --connect-timeout=1 ping --silent 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $ready = $true
        break
    }
    Start-Sleep -Seconds 1
}

if (-not $ready) {
    throw "Service $ServiceName terpasang tetapi MariaDB belum siap setelah 60 detik."
}

Write-Output "Service $ServiceName aktif, Automatic (Delayed Start), dan MariaDB siap di port 3306."
