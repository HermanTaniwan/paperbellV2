[CmdletBinding()]
param([string]$DailyAt = '09:00')
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$worker = Join-Path $PSScriptRoot 'reply-shopee-reviews.php'
$php = 'C:\xampp\php\php.exe'
if ((Get-TimeZone).Id -ne 'SE Asia Standard Time') { throw 'Task ini memerlukan zona waktu WIB (SE Asia Standard Time).' }
$runAt = [DateTime]::ParseExact($DailyAt, 'HH:mm', [Globalization.CultureInfo]::InvariantCulture)
& $php $worker --init
if ($LASTEXITCODE -ne 0) { throw 'Inisialisasi worker gagal.' }
$action = New-ScheduledTaskAction -Execute $php -Argument ('"{0}"' -f $worker) -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Daily -At $runAt
$currentUser = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
Register-ScheduledTask -TaskName 'Paperbell Shopee Review Replies' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Balas review Shopee baru, bintang 4-5 dengan komentar; satu per pembeli/order, lewati yang sudah dibalas.' -Force -ErrorAction Stop | Out-Null
Get-ScheduledTask -TaskName 'Paperbell Shopee Review Replies' | Select-Object TaskName, State
Get-ScheduledTaskInfo -TaskName 'Paperbell Shopee Review Replies' | Select-Object NextRunTime, LastTaskResult
