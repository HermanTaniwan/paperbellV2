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
    if ($exit !== 0) {
        $rawError = trim($stderr ?: $stdout);
        throw new RuntimeException(readableProcessError($rawError, $failureMessage . " (exit {$exit})"));
    }
    return $stdout;
}

function readableProcessError(string $rawError, string $fallback): string
{
    if ($rawError === '') return $fallback;
    if (!str_contains($rawError, '#< CLIXML')) return $rawError;

    $xmlStart = strpos($rawError, '<Objs');
    if ($xmlStart === false) return $fallback;

    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string(substr($rawError, $xmlStart));
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) return $fallback;

    $messages = $xml->xpath('//*[local-name()="S"][@S="Error"]') ?: [];
    foreach ($messages as $message) {
        $decoded = preg_replace_callback('/_x([0-9a-fA-F]{4})_/', static function (array $match): string {
            return mb_convert_encoding(pack('n', hexdec($match[1])), 'UTF-8', 'UTF-16BE');
        }, html_entity_decode((string)$message, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        foreach (preg_split('/\R/', (string)$decoded) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') return $line;
        }
    }
    return $fallback;
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
    $isL3210 = stripos((string)($job['printer'] ?? ''), 'L3210') !== false;
    $topMarginMm = $isL3210 ? '4' : '2';
    $driverPageMode = $isL3210 ? 'letter' : 'custom';
    runProcess([
        (string)($config['printing']['python'] ?? 'python'),
        $root . '/tools/prepare_label_pdf.py',
        (string)$job['file_path'],
        $output,
        $topMarginMm,
        $driverPageMode,
    ], 'Python penyiapan label tidak dapat dijalankan.');
    if (!is_file($output)) throw new RuntimeException('PDF label siap cetak tidak terbentuk.');
    if ($isL3210) {
        logLine("Job #{$job['id']} memakai halaman driver Letter dengan area label 105 x 182 mm dipusatkan horizontal untuk L3210");
    }
    return $output;
}

function applyLabelPaperSize(array $job): ?string
{
    global $root;
    if (($job['job_type'] ?? '') !== 'label') return null;
    // Epson L3210 menyimpan ukuran Letter lagi di snapshot DEVMODE privat.
    // Mengganti PageMediaSize XML saja membuat driver menerima dua ukuran
    // berbeda dan meraster halaman dengan offset horizontal yang salah.
    if (stripos((string)($job['printer'] ?? ''), 'L3210') !== false) return null;

    $printer = (string)$job['printer'];
    $backup = $root . '/storage/print-labels/print-ticket-job-' . (int)$job['id'] . '.xml';
    $replacement = '<psf:Feature name="psk:PageMediaSize"><psf:Option name="psk:CustomMediaSize"><psf:ScoredProperty name="psk:MediaSizeWidth"><psf:Value xsi:type="xsd:integer">105000</psf:Value></psf:ScoredProperty><psf:ScoredProperty name="psk:MediaSizeHeight"><psf:Value xsi:type="xsd:integer">182000</psf:Value></psf:ScoredProperty></psf:Option></psf:Feature>';
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $backup64 = base64_encode(mb_convert_encoding($backup, 'UTF-16LE', 'UTF-8'));
    $replacement64 = base64_encode(mb_convert_encoding($replacement, 'UTF-16LE', 'UTF-8'));
    $script = strtr(<<<'POWERSHELL'
$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('__PRINTER64__'))
$backup=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('__BACKUP64__'))
$replacement=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('__REPLACEMENT64__'))
$cfg=Get-PrintConfiguration -PrinterName $p -ErrorAction Stop
[IO.File]::WriteAllText($backup,$cfg.PrintTicketXml,[Text.UTF8Encoding]::new($false))
try {
    $custom=[regex]::Replace($cfg.PrintTicketXml,'<psf:Feature name="psk:PageMediaSize">.*?</psf:Feature>',$replacement,[Text.RegularExpressions.RegexOptions]::Singleline)
    if ($custom -eq $cfg.PrintTicketXml) { throw 'Fitur PageMediaSize tidak ditemukan.' }
    Set-PrintConfiguration -PrinterName $p -PrintTicketXml $custom -ErrorAction Stop
    [xml]$actual=(Get-PrintConfiguration -PrinterName $p -ErrorAction Stop).PrintTicketXml
    $ns=[Xml.XmlNamespaceManager]::new($actual.NameTable)
    $ns.AddNamespace('psf','http://schemas.microsoft.com/windows/2003/08/printing/printschemaframework')
    $media=$actual.SelectSingleNode("//psf:Feature[@name='psk:PageMediaSize']",$ns)
    $w=$null
    $h=$null
    if ($null -ne $media) {
        $w=$media.SelectSingleNode(".//psf:ScoredProperty[@name='psk:MediaSizeWidth']/psf:Value",$ns)
        $h=$media.SelectSingleNode(".//psf:ScoredProperty[@name='psk:MediaSizeHeight']/psf:Value",$ns)
    }
    if ($null -eq $w) { $w=$actual.SelectSingleNode("//psf:ParameterInit[@name='psk:PageMediaSizeMediaSizeWidth']/psf:Value",$ns) }
    if ($null -eq $h) { $h=$actual.SelectSingleNode("//psf:ParameterInit[@name='psk:PageMediaSizeMediaSizeHeight']/psf:Value",$ns) }
    if ($null -eq $w -or $null -eq $h -or $w.InnerText -ne '105000' -or $h.InnerText -ne '182000') {
        throw 'Driver menolak ukuran custom 105 x 182 mm.'
    }
} catch {
    Set-PrintConfiguration -PrinterName $p -PrintTicketXml $cfg.PrintTicketXml -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $backup -Force -ErrorAction SilentlyContinue
    throw
}
POWERSHELL, [
        '__PRINTER64__' => $printer64,
        '__BACKUP64__' => $backup64,
        '__REPLACEMENT64__' => $replacement64,
    ]);
    powershellEncoded($script, 'Ukuran custom 105 x 182 mm tidak dapat diterapkan ke printer label.');
    logLine("Job #{$job['id']} ukuran driver label diubah sementara ke 105 x 182 mm");
    return $backup;
}

function restoreLabelPrintTicket(string $printer, string $backup): void
{
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $backup64 = base64_encode(mb_convert_encoding($backup, 'UTF-16LE', 'UTF-8'));
    $script = strtr(<<<'POWERSHELL'
$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('__PRINTER64__'))
$backup=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('__BACKUP64__'))
if (-not (Test-Path -LiteralPath $backup)) { throw 'Backup PrintTicket tidak ditemukan.' }
$ticket=[IO.File]::ReadAllText($backup)
Set-PrintConfiguration -PrinterName $p -PrintTicketXml $ticket -ErrorAction Stop
Remove-Item -LiteralPath $backup -Force
POWERSHELL, [
        '__PRINTER64__' => $printer64,
        '__BACKUP64__' => $backup64,
    ]);
    powershellEncoded($script, 'Konfigurasi printer label sebelumnya tidak dapat dikembalikan.');
}

function labelPrintSettings(string $printer): string
{
    $parts = ['1-', 'simplex', 'monochrome', 'noscale'];
    if (stripos($printer, 'L3210') !== false) {
        $parts[] = 'paper=Letter';
    } elseif (stripos($printer, 'Brother DCP') !== false) {
        $parts[] = 'bin=258'; // MP Tray, sama dengan aplikasi desktop.
    } elseif (stripos($printer, 'WF') !== false) {
        $parts[] = 'bin=261'; // Rear Paper Feed, sama dengan aplikasi desktop.
    }
    // Jangan paksa A6: resi sudah disiapkan sebagai 105 x 182 mm dan driver
    // label memakai ukuran custom yang sama.
    return implode(',', $parts);
}

$db = connectDatabase();

do {
    $job = null;
    $preparedLabelPath = null;
    $temporaryPaperSize = null;
    $temporaryLabelPrintTicket = null;
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
            $temporaryLabelPrintTicket = applyLabelPaperSize($job);
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
        if ($temporaryLabelPrintTicket !== null && is_array($job) && isset($job['printer'])) {
            try {
                restoreLabelPrintTicket((string)$job['printer'], $temporaryLabelPrintTicket);
                logLine("Job #{$job['id']} konfigurasi driver label sebelumnya dikembalikan");
            } catch (Throwable $restoreError) {
                logLine('Konfigurasi driver label gagal dikembalikan: ' . $restoreError->getMessage());
            }
        }
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
