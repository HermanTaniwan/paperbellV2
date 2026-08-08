[CmdletBinding()]
param(
    [string]$Database = $(if ($env:PAPERBELL_DB_NAME) { $env:PAPERBELL_DB_NAME } else { 'paperbell' }),
    [string]$HostName = $(if ($env:PAPERBELL_DB_HOST) { $env:PAPERBELL_DB_HOST } else { '127.0.0.1' }),
    [int]$Port = $(if ($env:PAPERBELL_DB_PORT) { [int]$env:PAPERBELL_DB_PORT } else { 3306 }),
    [string]$UserName = $(if ($env:PAPERBELL_DB_USER) { $env:PAPERBELL_DB_USER } else { 'root' }),
    [string]$Password = $(if ($env:PAPERBELL_DB_PASSWORD) { $env:PAPERBELL_DB_PASSWORD } else { '' }),
    [int]$RetentionDays = 30,
    [string]$DriveBackupDirectory = 'H:\My Drive\Paperbell Backups'
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$backupDirectory = Join-Path $projectRoot 'storage\backups'
$logDirectory = Join-Path $projectRoot 'storage\logs'
$backupLog = Join-Path $logDirectory 'backup.log'
$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'

New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

function Write-BackupLog([string]$Message) {
    Add-Content -LiteralPath $backupLog `
        -Value ('{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) `
        -Encoding UTF8
}

if (-not (Test-Path -LiteralPath $mysqldump -PathType Leaf)) {
    throw "mysqldump tidak ditemukan: $mysqldump"
}

New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null

$timestamp = Get-Date -Format 'yyyy-MM-dd_HHmmss'
$sqlPath = Join-Path $backupDirectory "$Database-$timestamp.sql"
$zipPath = Join-Path $backupDirectory "$Database-$timestamp.zip"

$dumpArguments = @(
    "--host=$HostName"
    "--port=$Port"
    "--user=$UserName"
    '--single-transaction'
    '--routines'
    '--triggers'
    '--events'
    '--default-character-set=utf8mb4'
    "--result-file=$sqlPath"
    $Database
)

try {
    if ($Password) {
        $env:MYSQL_PWD = $Password
    }

    & $mysqldump @dumpArguments
    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump gagal dengan exit code $LASTEXITCODE"
    }

    $dumpFile = Get-Item -LiteralPath $sqlPath
    if ($dumpFile.Length -eq 0) {
        throw 'mysqldump menghasilkan file kosong'
    }

    Compress-Archive -LiteralPath $sqlPath -DestinationPath $zipPath -CompressionLevel Optimal
    if (-not (Test-Path -LiteralPath $zipPath -PathType Leaf)) {
        throw 'Kompresi backup gagal'
    }

    Remove-Item -LiteralPath $sqlPath

    if (-not (Test-Path -LiteralPath 'H:\' -PathType Container)) {
        throw 'Google Drive H: tidak tersedia'
    }

    New-Item -ItemType Directory -Path $DriveBackupDirectory -Force | Out-Null
    $month = Get-Date -Format 'yyyy-MM'
    $driveZipPath = Join-Path $DriveBackupDirectory "$Database-$month.zip"
    Copy-Item -LiteralPath $zipPath -Destination $driveZipPath -Force

    $localArchive = Get-Item -LiteralPath $zipPath
    $driveArchive = Get-Item -LiteralPath $driveZipPath
    if ($driveArchive.Length -ne $localArchive.Length -or $driveArchive.Length -eq 0) {
        throw 'Ukuran backup di Google Drive tidak sesuai dengan file lokal'
    }

    $cutoff = (Get-Date).AddDays(-$RetentionDays)
    Get-ChildItem -LiteralPath $backupDirectory -File -Filter "$Database-*.zip" |
        Where-Object { $_.LastWriteTime -lt $cutoff } |
        Remove-Item -Force

    $localMessage = "Backup lokal berhasil: {0} ({1:N0} bytes)" -f $localArchive.FullName, $localArchive.Length
    $driveMessage = "Backup Google Drive berhasil: {0} ({1:N0} bytes)" -f $driveArchive.FullName, $driveArchive.Length
    Write-Output $localMessage
    Write-Output $driveMessage
    Write-BackupLog "$localMessage | $driveMessage"
}
catch {
    Write-BackupLog "ERROR $($_.Exception.Message)"
    if (Test-Path -LiteralPath $sqlPath) {
        Remove-Item -LiteralPath $sqlPath -Force
    }
    throw
}
finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}
