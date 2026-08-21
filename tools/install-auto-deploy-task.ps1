[CmdletBinding()]
param(
    [string]$TaskName = 'Paperbell Auto Deploy',
    [int]$IntervalMinutes = 2
)

$ErrorActionPreference = 'Stop'
if ($IntervalMinutes -lt 1 -or $IntervalMinutes -gt 60) {
    throw 'IntervalMinutes harus antara 1 dan 60.'
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$deployScript = Join-Path $PSScriptRoot 'auto-deploy.ps1'
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
if (-not (Test-Path -LiteralPath $deployScript -PathType Leaf)) {
    throw "Script auto-deploy tidak ditemukan: $deployScript"
}

$arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}"' -f $deployScript
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Menarik origin/main secara fast-forward dan mengaktifkan perubahan Paperbell dengan aman.' `
    -Force | Out-Null

Start-ScheduledTask -TaskName $TaskName
Start-Sleep -Seconds 2
$task = Get-ScheduledTask -TaskName $TaskName
$info = Get-ScheduledTaskInfo -TaskName $TaskName
Write-Output "Auto-deploy terpasang untuk $currentUser."
Write-Output "Task: $TaskName | Interval: $IntervalMinutes menit | Status: $($task.State) | Hasil terakhir: $($info.LastTaskResult)"
