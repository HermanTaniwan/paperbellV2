$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$mysqlAdmin = 'C:\xampp\mysql\bin\mysqladmin.exe'

if (-not (Get-Process -Name httpd -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath 'C:\xampp\apache\bin\httpd.exe' -WorkingDirectory 'C:\xampp\apache\bin' -WindowStyle Hidden
}

$listening = Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue
if (-not $listening) {
    Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' `
        -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini --standalone' `
        -WorkingDirectory 'C:\xampp\mysql\bin' -WindowStyle Hidden
}

# A listening port alone does not mean MariaDB has finished crash recovery and
# is ready to accept queries. Starting the worker before this point makes it
# exit immediately during Windows startup.
$mysqlReady = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    & $mysqlAdmin -h 127.0.0.1 -P 3306 -u root ping --silent 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) {
        $mysqlReady = $true
        break
    }
    Start-Sleep -Seconds 1
}

if (-not $mysqlReady) {
    throw 'MariaDB belum siap setelah 60 detik; print worker tidak dijalankan.'
}

$worker = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like '*print-worker.php*' }
if (-not $worker) {
    Start-Process -FilePath 'C:\xampp\php\php.exe' `
        -ArgumentList "`"$root\worker\print-worker.php`"" `
        -WorkingDirectory $root -WindowStyle Hidden
}

Write-Host 'Paperbell Web aktif di http://app.paperbell.id/' -ForegroundColor Green
