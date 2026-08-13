param(
    [switch]$Once
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$logPath = Join-Path $root 'storage\print-spooler-watchdog.log'
$printerName = if ($env:PAPERBELL_L3210_PRINTER) {
    $env:PAPERBELL_L3210_PRINTER
}
else {
    'EPSON L3210 Series'
}
$pollSeconds = 3
$retainedGraceSeconds = 90
$drainedByteLimit = 1024
$retainedSince = @{}
$lastError = ''

function Write-WatchdogLog([string]$Message) {
    Add-Content -LiteralPath $logPath `
        -Value ('{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message) `
        -Encoding UTF8
}

function Restart-PrinterUsb([string]$TargetPrinter) {
    $device = Get-PnpDevice -PresentOnly -ErrorAction SilentlyContinue |
        Where-Object {
            $_.Class -eq 'Printer' -and
            $_.FriendlyName -eq $TargetPrinter -and
            $_.InstanceId -like 'USBPRINT\*'
        } |
        Select-Object -First 1

    if (-not $device) {
        Write-WatchdogLog "Perangkat USB tidak ditemukan untuk $TargetPrinter."
        return $false
    }

    $output = & pnputil.exe /restart-device $device.InstanceId 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-WatchdogLog "Reset USB gagal untuk $TargetPrinter (exit $LASTEXITCODE): $($output -join ' ')"
        return $false
    }

    Write-WatchdogLog "USB $TargetPrinter berhasil di-reset."
    return $true
}

do {
    try {
        $jobs = @(Get-PrintJob -PrinterName $printerName -ErrorAction Stop)
        $activeIds = @{}
        $now = Get-Date

        foreach ($job in $jobs) {
            $id = [string]$job.Id
            $activeIds[$id] = $true
            $isRetained = ([string]$job.JobStatus) -match 'Retained'
            $isDrained = [long]$job.Size -le $drainedByteLimit

            if (-not ($isRetained -and $isDrained)) {
                $retainedSince.Remove($id)
                continue
            }

            if (-not $retainedSince.ContainsKey($id)) {
                $retainedSince[$id] = $now
                Write-WatchdogLog "Job Windows #$id mulai terdeteksi Retained setelah data terkirim."
                continue
            }

            $retainedFor = ($now - [datetime]$retainedSince[$id]).TotalSeconds
            if ($retainedFor -lt $retainedGraceSeconds) {
                continue
            }

            Write-WatchdogLog "Memulihkan job Windows #$id yang Retained selama $([int]$retainedFor) detik."
            [void](Restart-PrinterUsb $printerName)
            Start-Sleep -Seconds 5

            $staleJob = Get-PrintJob -PrinterName $printerName -Id $job.Id -ErrorAction SilentlyContinue
            if ($staleJob -and
                ([string]$staleJob.JobStatus) -match 'Retained' -and
                [long]$staleJob.Size -le $drainedByteLimit) {
                Remove-PrintJob -PrinterName $printerName -Id $job.Id -Confirm:$false -ErrorAction Stop
                Write-WatchdogLog "Job Windows #$id dilepas agar antrean berikutnya dapat berjalan."
            }
            $retainedSince.Remove($id)
        }

        foreach ($id in @($retainedSince.Keys)) {
            if (-not $activeIds.ContainsKey([string]$id)) {
                $retainedSince.Remove([string]$id)
            }
        }
        $lastError = ''
    }
    catch {
        $message = $_.Exception.Message
        if ($message -ne $lastError) {
            Write-WatchdogLog "ERROR: $message"
            $lastError = $message
        }
    }

    if (-not $Once) {
        Start-Sleep -Seconds $pollSeconds
    }
} while (-not $Once)
