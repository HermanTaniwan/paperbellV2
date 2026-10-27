[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$job = Join-Path $PSScriptRoot 'check-order-stalls.php'
$php = 'C:\xampp\php\php.exe'
$name = 'Paperbell Order Stall Alerts'
if ((Get-TimeZone).Id -ne 'SE Asia Standard Time') { throw 'Host timezone must be Asia/Jakarta.' }
$arguments = '"{0}"' -f $job
$existing = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
if ($existing -and $existing.Actions.Arguments -notcontains $arguments) { throw 'Existing task has a different action.' }
$action = New-ScheduledTaskAction -Execute $php -Argument $arguments -WorkingDirectory $projectRoot
$triggers = @('09:00','12:00','15:00') | ForEach-Object { New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday,Tuesday,Wednesday,Thursday,Friday -At $_ }
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType S4U -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
try {
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Description 'Check pre-shipment orders at 09:00, 12:00 and 15:00 WIB. Skip weekends and configured store holidays; read-only marketplace checks.' -Force -ErrorAction Stop | Out-Null
} catch {
    # An ordinary Windows account may register its own interactive task but
    # lack the batch-logon permission required for S4U.
    $principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $name -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Description 'Paperbell order-risk checks at 09:00, 12:00 and 15:00 WIB while this Windows user is logged in. Weekends and store holidays excluded.' -Force -ErrorAction Stop | Out-Null
    Write-Warning 'S4U unavailable. This task runs while the current Windows user is logged in.'
}
Get-ScheduledTask -TaskName $name | Select-Object TaskName,State
