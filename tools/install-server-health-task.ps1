[CmdletBinding()]
param(
    [string]$TaskName = 'Paperbell Server Health',
    [int]$IntervalMinutes = 1
)

$ErrorActionPreference = 'Stop'
if ($IntervalMinutes -lt 1 -or $IntervalMinutes -gt 60) {
    throw 'IntervalMinutes harus antara 1 dan 60.'
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$collector = Join-Path $PSScriptRoot 'collect-server-health.php'
$php = 'C:\xampp\php\php.exe'
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
if (-not (Test-Path -LiteralPath $collector -PathType Leaf)) { throw "Collector tidak ditemukan: $collector" }
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw "PHP tidak ditemukan: $php" }

$action = New-ScheduledTaskAction -Execute $php -Argument ('"{0}"' -f $collector) -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType S4U -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Seconds 50)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Mengumpulkan CPU, RAM, storage, uptime, dan sensor hardware Paperbell setiap menit.' `
    -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName
Write-Output "Task Server Health terpasang untuk $currentUser dengan hak tertinggi."
