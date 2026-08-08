param(
    [Parameter(Mandatory = $true)][string]$TitleBase64,
    [Parameter(Mandatory = $true)][string]$MessageBase64
)

$ErrorActionPreference = 'SilentlyContinue'
$encoding = [Text.Encoding]::Unicode
$title = $encoding.GetString([Convert]::FromBase64String($TitleBase64))
$message = $encoding.GetString([Convert]::FromBase64String($MessageBase64))

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$notification = New-Object System.Windows.Forms.NotifyIcon
$notification.Icon = [System.Drawing.SystemIcons]::Error
$notification.BalloonTipIcon = [System.Windows.Forms.ToolTipIcon]::Error
$notification.BalloonTipTitle = $title
$notification.BalloonTipText = $message
$notification.Text = 'Paperbell Printer Alert'
$notification.Visible = $true
[System.Media.SystemSounds]::Exclamation.Play()
$notification.ShowBalloonTip(8000)
Start-Sleep -Seconds 9
$notification.Dispose()
