[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$job = Join-Path $PSScriptRoot 'process-instagram-reels.php'
$php = 'C:\xampp\php\php.exe'
$name = 'Paperbell Instagram Reels'
$arguments = '"{0}"' -f $job
$existing = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
if ($existing -and ($existing.Actions.Arguments -notcontains $arguments -or $existing.Actions.Execute -notcontains $php)) { throw 'Existing task has a different action.' }
$action = New-ScheduledTaskAction -Execute $php -Argument $arguments -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType S4U -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 6)
try {
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Publish scheduled Instagram Reels. No action without a connected account and due content.' -Force -ErrorAction Stop | Out-Null
} catch {
    $principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Publish scheduled Instagram Reels while this Windows user is logged in.' -Force -ErrorAction Stop | Out-Null
    Write-Warning 'Runs while the current Windows user is logged in.'
}
Get-ScheduledTask -TaskName $name | Select-Object TaskName,State
Get-ScheduledTaskInfo -TaskName $name | Select-Object NextRunTime,LastTaskResult
