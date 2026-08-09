<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/Database.php';
$sumatra = $config['printing']['sumatra'];
$once = in_array('--once', $argv, true);

function logLine(string $message): void
{
    global $root;
    file_put_contents($root . '/storage/print-worker.log', '[' . date('c') . "] {$message}\n", FILE_APPEND | LOCK_EX);
}

function connectDatabase(): PDO
{
    global $config;
    $lastError = '';
    while (true) {
        try {
            return Database::mysql($config['mysql']);
        } catch (Throwable $e) {
            if ($e->getMessage() !== $lastError) {
                logLine('Database belum tersedia, mencoba lagi: ' . $e->getMessage());
                $lastError = $e->getMessage();
            }
            Database::resetMysql();
            sleep(2);
        }
    }
}

function runProcess(array $command, string $failureMessage): string
{
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException($failureMessage);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException(trim($stderr ?: $stdout ?: "Process exit {$exit}"));
    return $stdout;
}

function printerSpoolerJobIds(string $printer): array
{
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $script = "\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$printer64}')); @(Get-PrintJob -PrinterName \$p -ErrorAction Stop | Select-Object -ExpandProperty ID) | ConvertTo-Json -Compress";
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $raw = runProcess(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-EncodedCommand',$encoded], 'Status Windows spooler tidak dapat dibaca.');
    $decoded = json_decode(trim($raw), true);
    if (is_int($decoded)) return [$decoded];
    return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
}

function powershellEncoded(string $script, string $failureMessage): string
{
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    return runProcess([
        'powershell.exe',
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-EncodedCommand',
        $encoded,
    ], $failureMessage);
}

function paperSizeFromPrintSettings(string $settings): ?string
{
    if (!preg_match('/(?:^|,)paper=(A4|A5|A6|B5)(?:,|$)/i', $settings, $match)) return null;
    return strtoupper($match[1]);
}

function printerPaperSize(string $printer): string
{
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $script = "\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$printer64}')); (Get-PrintConfiguration -PrinterName \$p -ErrorAction Stop).PaperSize.ToString()";
    return trim(powershellEncoded($script, 'Konfigurasi ukuran kertas printer tidak dapat dibaca.'));
}

function setPrinterPaperSize(string $printer, string $paper): void
{
    if (!in_array($paper, ['A4', 'A5', 'A6', 'B5'], true)) {
        throw new InvalidArgumentException('Ukuran kertas printer tidak didukung: ' . $paper);
    }
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $script = "\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$printer64}')); Set-PrintConfiguration -PrinterName \$p -PaperSize {$paper} -ErrorAction Stop; \$actual=(Get-PrintConfiguration -PrinterName \$p -ErrorAction Stop).PaperSize.ToString(); if (\$actual -ne '{$paper}') { throw \"Ukuran printer tetap \$actual, bukan {$paper}.\" }";
    powershellEncoded($script, 'Konfigurasi ukuran kertas printer tidak dapat diubah.');
}

function applyBrotherProductPaperSize(array $job, string $printSettings): ?string
{
    if (($job['job_type'] ?? '') !== 'product' || stripos((string)($job['printer'] ?? ''), 'Brother') === false) return null;
    $paper = paperSizeFromPrintSettings($printSettings);
    if ($paper === null) return null;

    $printer = (string)$job['printer'];
    $previous = printerPaperSize($printer);
    if (strcasecmp($previous, $paper) !== 0) {
        setPrinterPaperSize($printer, $paper);
        logLine("Job #{$job['id']} ukuran driver Brother diubah sementara: {$previous} -> {$paper}");
    }
    return $previous;
}

function prepareLabelPdf(array $job): string
{
    global $root, $config;
    $dir = $root . '/storage/print-labels';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder sementara cetak label tidak dapat dibuat.');
    }
    $output = $dir . '/label_job_' . (int)$job['id'] . '.pdf';
    runProcess([
        (string)($config['printing']['python'] ?? 'python'),
        $root . '/tools/prepare_label_pdf.py',
        (string)$job['file_path'],
        $output,
    ], 'Python penyiapan label tidak dapat dijalankan.');
    if (!is_file($output)) throw new RuntimeException('PDF label siap cetak tidak terbentuk.');
    return $output;
}

function labelPrintSettings(string $printer): string
{
    $parts = ['1-', 'simplex', 'noscale'];
    if (stripos($printer, 'Brother DCP') !== false) {
        $parts[] = 'bin=258'; // MP Tray, sama dengan aplikasi desktop.
    } elseif (stripos($printer, 'WF') !== false) {
        $parts[] = 'bin=261'; // Rear Paper Feed, sama dengan aplikasi desktop.
    }
    $parts[] = 'monochrome';
    $parts[] = 'paper=A6';
    return implode(',', $parts);
}

$db = connectDatabase();

do {
    $job = null;
    $preparedLabelPath = null;
    $temporaryPaperSize = null;
    try {
        $heartbeat = $db->prepare("INSERT INTO app_meta(meta_key,meta_value) VALUES('print_worker_heartbeat',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $heartbeat->execute([(string)time()]);
        $db->beginTransaction();
        $job = $db->query("SELECT * FROM print_jobs WHERE status='queued' ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) {
            $db->commit();
            if ($once) break;
            usleep(500000);
            continue;
        }
        $claim = $db->prepare("UPDATE print_jobs SET status='processing',message='Mengirim ke printer',started_at=?,attempts=attempts+1 WHERE id=? AND status='queued'");
        $claim->execute([time(), $job['id']]);
        $db->commit();

        if (!is_file($sumatra)) throw new RuntimeException('SumatraPDF tidak ditemukan.');
        if (!is_file($job['file_path'])) throw new RuntimeException('File PDF tidak ditemukan: ' . $job['file_path']);

        $printPath = (string)$job['file_path'];
        $printSettings = (string)$job['print_settings'];
        if ($job['job_type'] === 'label') {
            $preparedLabelPath = prepareLabelPdf($job);
            $printPath = $preparedLabelPath;
            $printSettings = labelPrintSettings((string)$job['printer']);
        }

        $temporaryPaperSize = applyBrotherProductPaperSize($job, $printSettings);

        try {$spoolerBefore = printerSpoolerJobIds((string)$job['printer']);} catch (Throwable $spoolerError) {logLine('Korelasi spooler sebelum print gagal: '.$spoolerError->getMessage());$spoolerBefore=[];}
        runProcess([
            $sumatra,
            '-print-to',
            $job['printer'],
            '-print-settings',
            $printSettings,
            '-silent',
            '-exit-on-print',
            $printPath,
        ], 'Gagal menjalankan SumatraPDF.');

        $spoolerJobId = null;
        for ($spoolerAttempt = 0; $spoolerAttempt < 5 && $spoolerJobId === null; $spoolerAttempt++) {
            try {
                $spoolerAfter = printerSpoolerJobIds((string)$job['printer']);
                $newIds = array_values(array_diff($spoolerAfter, $spoolerBefore));
                if ($newIds) $spoolerJobId = max($newIds);
            } catch (Throwable $spoolerError) {
                logLine('Korelasi spooler setelah print gagal: '.$spoolerError->getMessage());
                break;
            }
            if ($spoolerJobId === null) usleep(200000);
        }

        // Windows now owns the submitted job. Drop the cached spooler snapshot
        // so the web widget can show it on its very next refresh.
        @unlink($root . '/storage/printer-spooler-cache.json');

        $state = $db->prepare('SELECT status FROM print_jobs WHERE id=?');
        $state->execute([$job['id']]);
        if ($state->fetchColumn() === 'cancel_requested') {
            logLine("Job #{$job['id']} cancellation recorded after submission");
            continue;
        }

        $db->beginTransaction();
        $done = $db->prepare("UPDATE print_jobs SET status='submitted',message=?,completed_at=?,submitted_at=?,spooler_job_id=?,error='' WHERE id=? AND status='processing'");
        $submittedAt=time();$done->execute([$spoolerJobId===null?'Diserahkan ke Windows spooler':'Diserahkan ke Windows spooler #'.$spoolerJobId,$submittedAt,$submittedAt,$spoolerJobId,$job['id']]);
        if ($job['job_type'] === 'product' && $job['order_process_id']) {
            $tokens = array_map('strtolower', array_map('trim', explode(',', (string)$job['print_settings'])));
            if (in_array('odd', $tokens, true)) {
                $mark = $db->prepare('UPDATE order_process SET printed_odd=1,printed=IF(printed_even=1,1,0),printed_at=? WHERE id=?');
            } elseif (in_array('even', $tokens, true)) {
                $mark = $db->prepare('UPDATE order_process SET printed_even=1,printed=IF(printed_odd=1,1,0),printed_at=? WHERE id=?');
            } else {
                $mark = $db->prepare('UPDATE order_process SET printed=1,printed_odd=1,printed_even=1,printed_at=? WHERE id=?');
            }
            $mark->execute([time(), $job['order_process_id']]);
        } elseif ($job['job_type'] === 'label') {
            $mark = $db->prepare('UPDATE order_resi SET resi_printed=1,resi_printed_at=? WHERE order_sn=?');
            $mark->execute([time(), $job['order_sn']]);
        }
        $db->commit();
        logLine("Job #{$job['id']} submitted to Windows: {$job['printer']}");
    } catch (Throwable $e) {
        try {
            if ($db->inTransaction()) $db->rollBack();
        } catch (Throwable) {
            // The connection itself may have disappeared during a DB restart.
        }

        if ($e instanceof PDOException) {
            Database::resetMysql();
            $db = connectDatabase();
        }

        if (is_array($job) && isset($job['id'])) {
            try {
                $fail = $db->prepare("UPDATE print_jobs SET status='failed',message='Gagal',error=?,completed_at=? WHERE id=?");
                $fail->execute([$e->getMessage(), time(), $job['id']]);
            } catch (Throwable $updateError) {
                logLine('ERROR saat menyimpan status job: ' . $updateError->getMessage());
            }
        }
        logLine('ERROR: ' . $e->getMessage());
    } finally {
        if ($temporaryPaperSize !== null && is_array($job) && isset($job['printer'])) {
            try {
                $currentPaperSize = printerPaperSize((string)$job['printer']);
                if (strcasecmp($currentPaperSize, $temporaryPaperSize) !== 0) {
                    setPrinterPaperSize((string)$job['printer'], $temporaryPaperSize);
                    logLine("Job #{$job['id']} ukuran driver Brother dikembalikan: {$currentPaperSize} -> {$temporaryPaperSize}");
                }
            } catch (Throwable $restoreError) {
                logLine('Ukuran driver Brother gagal dikembalikan: ' . $restoreError->getMessage());
            }
        }
        if ($preparedLabelPath !== null && is_file($preparedLabelPath)) @unlink($preparedLabelPath);
    }
    if ($once) break;
} while (true);
