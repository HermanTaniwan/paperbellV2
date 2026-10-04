<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/src/Database.php';
require $root . '/src/LabelPdfPreparer.php';
require $root . '/src/HostPathResolver.php';
$sumatra = $config['printing']['sumatra'];
$labelPreparer = new LabelPdfPreparer($config['printing'],$root);
$hostPathResolver = new HostPathResolver($config['paths']??[]);
$once = in_array('--once', $argv, true);
$brotherPaperSizeCache = [];
$printerGroup='all';
foreach($argv as $argument){
    if(str_starts_with($argument,'--printer-group='))$printerGroup=substr($argument,16);
}
printerGroupSql($printerGroup);

function printerGroupSql(string $group): string
{
    if($group==='all')return '';
    if(!in_array($group,['wf5790','wf5390','brother','l3210','other'],true))throw new InvalidArgumentException('Kelompok printer tidak valid.');
    return " AND (CASE WHEN LOWER(printer) LIKE '%5790%' THEN 'wf5790' WHEN LOWER(printer) LIKE '%5390%' THEN 'wf5390' WHEN LOWER(printer) LIKE '%brother%' THEN 'brother' WHEN LOWER(printer) LIKE '%l3210%' THEN 'l3210' ELSE 'other' END)='{$group}'";
}

function acquirePrintWorkerLocks(string $directory,string $group): array
{
    printerGroupSql($group);
    if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('Folder kunci worker tidak tersedia.');
    $global=fopen($directory.'/print-worker-global.lock','c');
    if(!$global||!flock($global,($group==='all'?LOCK_EX:LOCK_SH)|LOCK_NB))throw new RuntimeException('Mode worker printer lain masih berjalan.');
    $scoped=fopen($directory.'/print-worker-'.$group.'.lock','c');
    if(!$scoped||!flock($scoped,LOCK_EX|LOCK_NB)){
        flock($global,LOCK_UN);fclose($global);
        throw new RuntimeException('Worker kelompok printer ini sudah berjalan.');
    }
    return [$global,$scoped];
}

function isWindowsPrintHost(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

function logLine(string $message): void
{
    global $root;
    file_put_contents($root . '/storage/print-worker.log', '[' . date('c') . "] {$message}\n", FILE_APPEND | LOCK_EX);
}

function refreshOrderPrintSummary(PDO $db,string $orderSn): void
{
    $stmt=$db->prepare('UPDATE orders o LEFT JOIN (SELECT order_sn,COUNT(*) line_count,COALESCE(SUM(qty),0) item_qty,SUM(printed=0) pending,MAX(printed_at) printed_at FROM order_process WHERE order_sn=? GROUP BY order_sn) s ON s.order_sn=o.order_sn SET o.print_line_count=COALESCE(s.line_count,0),o.print_item_qty=COALESCE(s.item_qty,0),o.unprinted_lines=COALESCE(s.pending,0),o.last_printed_at=s.printed_at WHERE o.order_sn=?');
    $stmt->execute([$orderSn,$orderSn]);
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

function recoverInterruptedJobs(PDO $db): void
{
    global $printerGroup;
    $scope=printerGroupSql($printerGroup);
    $db->beginTransaction();
    try {
        $safe = $db->prepare("UPDATE print_jobs SET status='queued',message='Menunggu worker printer (pemulihan otomatis)',error='',started_at=NULL,completed_at=NULL,submitted_at=NULL,spooler_job_id=NULL WHERE status='processing' AND message='Menyiapkan dokumen'{$scope}");
        $safe->execute();
        $requeued = $safe->rowCount();

        // Once submission to the host spooler has started, automatically retrying could
        // print a duplicate if the previous worker died after handing off data.
        $uncertain = $db->prepare("UPDATE print_jobs SET status='failed',message='Perlu diperiksa sebelum dicetak ulang',error='Worker terputus saat mengirim ke spooler host. Periksa antrean printer sebelum menggunakan Coba lagi.',completed_at=? WHERE status='processing'{$scope}");
        $uncertain->execute([time()]);
        $flagged = $uncertain->rowCount();
        $db->commit();

        if ($requeued > 0) logLine("Pemulihan startup: {$requeued} job aman dikembalikan ke antrean.");
        if ($flagged > 0) logLine("Pemulihan startup: {$flagged} job ditandai perlu diperiksa untuk mencegah cetak duplikat.");
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
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

function isCupsEpsonThrottledPrinter(string $printer): bool
{
    return PHP_OS_FAMILY !== 'Windows' && preg_match('/epson[ _-]*wf[ _-]*c[ _-]*5390/i', $printer) === 1;
}

function cupsEpsonSubmissionGateReason(string $printer,string $printerOutput,string $jobOutput): ?string
{
    if (!isCupsEpsonThrottledPrinter($printer)) return null;
    if (preg_match('/^printer\s+'.preg_quote($printer,'/').'\s+.*\bdisabled\b/mi',$printerOutput)) {
        return 'Antrean CUPS Epson dijeda; job ditahan sampai printer diaktifkan ulang.';
    }
    if (preg_match('/^'.preg_quote($printer,'/').'-\d+\s+/mi',$jobOutput)) {
        return 'Menunggu CUPS Epson kosong (maksimum 1 job aktif).';
    }
    return null;
}

function cupsSubmissionGateReason(string $printer): ?string
{
    if (!isCupsEpsonThrottledPrinter($printer)) return null;
    try {
        $printerOutput=runProcess(['lpstat','-p',$printer],'Gagal membaca status antrean CUPS Epson.');
        $jobOutput=runProcess(['lpstat','-W','not-completed','-o',$printer],'Gagal membaca job aktif CUPS Epson.');
        return cupsEpsonSubmissionGateReason($printer,$printerOutput,$jobOutput);
    } catch (Throwable) {
        return 'Status CUPS Epson belum dapat dibaca; job ditahan agar tidak menambah antrean printer.';
    }
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

function printPrinterForJob(array $job, string $printSettings): string
{
    global $config;
    $requested = trim((string)($job['printer'] ?? ''));
    $epson5790 = trim((string)($config['printing']['epson5790_printer'] ?? ''));
    if (isWindowsPrintHost() && isEpson5790ProductJob($job) && $epson5790 !== '') {
        return $epson5790;
    }
    $brotherB5 = trim((string)($config['printing']['brother_b5_printer'] ?? ''));
    if (($job['job_type'] ?? '') === 'label'
        || $brotherB5 === ''
        || stripos($requested, 'Brother') === false
        || paperSizeFromPrintSettings($printSettings) !== 'B5') {
        return $requested;
    }
    return $brotherB5;
}

function printerPaperSize(string $printer): string
{
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $script = "\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$printer64}')); (Get-PrintConfiguration -PrinterName \$p -ErrorAction Stop).PaperSize.ToString()";
    return trim(powershellEncoded($script, 'Konfigurasi ukuran kertas printer tidak dapat dibaca.'));
}

function setPrinterPaperSize(string $printer, string $paper): void
{
    if (!in_array($paper, ['A4', 'A5', 'A6', 'B5', 'Letter'], true)) {
        throw new InvalidArgumentException('Ukuran kertas printer tidak didukung: ' . $paper);
    }
    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $script = "\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$printer64}')); Set-PrintConfiguration -PrinterName \$p -PaperSize {$paper} -ErrorAction Stop; \$actual=(Get-PrintConfiguration -PrinterName \$p -ErrorAction Stop).PaperSize.ToString(); if (\$actual -ne '{$paper}') { throw \"Ukuran printer tetap \$actual, bukan {$paper}.\" }";
    powershellEncoded($script, 'Konfigurasi ukuran kertas printer tidak dapat diubah.');
}

function applyBrotherProductPaperSize(array $job, string $printSettings): ?string
{
    global $brotherPaperSizeCache;
    if (!isWindowsPrintHost()) return null;
    if (($job['job_type'] ?? '') !== 'product' || stripos((string)($job['printer'] ?? ''), 'Brother') === false) return null;
    $paper = paperSizeFromPrintSettings($printSettings);
    if ($paper === null) return null;

    $printer = (string)$job['printer'];
    if (array_key_exists($printer, $brotherPaperSizeCache)) {
        $previous = (string)$brotherPaperSizeCache[$printer];
        if (strcasecmp($previous, $paper) === 0) return null;
        setPrinterPaperSize($printer, $paper);
        $brotherPaperSizeCache[$printer] = $paper;
        logLine("Job #{$job['id']} ukuran driver Brother diubah dari cache: {$previous} -> {$paper}");
        return $previous;
    }

    $printer64 = base64_encode(mb_convert_encoding($printer, 'UTF-16LE', 'UTF-8'));
    $script = "\$p=[Text.Encoding]::Unicode.GetString([Convert]::FromBase64String('{$printer64}')); \$previous=(Get-PrintConfiguration -PrinterName \$p -ErrorAction Stop).PaperSize.ToString(); \$changed=\$false; if (\$previous -ne '{$paper}') { Set-PrintConfiguration -PrinterName \$p -PaperSize {$paper} -ErrorAction Stop; \$actual=(Get-PrintConfiguration -PrinterName \$p -ErrorAction Stop).PaperSize.ToString(); if (\$actual -ne '{$paper}') { throw \"Ukuran printer tetap \$actual, bukan {$paper}.\" }; \$changed=\$true }; [pscustomobject]@{previous=\$previous;changed=\$changed} | ConvertTo-Json -Compress";
    $result = json_decode(trim(powershellEncoded($script, 'Konfigurasi ukuran kertas Brother tidak dapat disiapkan.')), true);
    if (!is_array($result) || !isset($result['previous'], $result['changed'])) {
        throw new RuntimeException('Respons konfigurasi ukuran kertas Brother tidak valid.');
    }
    $previous = (string)$result['previous'];
    $brotherPaperSizeCache[$printer] = (bool)$result['changed'] ? $paper : $previous;
    if (!(bool)$result['changed']) return null;
    logLine("Job #{$job['id']} ukuran driver Brother diubah sementara: {$previous} -> {$paper}");
    return $previous;
}

function isEpson5790ProductJob(array $job): bool
{
    return ($job['job_type'] ?? '') !== 'label'
        && stripos((string)($job['printer'] ?? ''), '5790') !== false;
}

function epson5790PdfPrintSettings(array $job, string $settings): string
{
    global $config, $root;
    if (!isWindowsPrintHost() || !isEpson5790ProductJob($job)) return $settings;
    $result = json_decode(runProcess([
        $config['printing']['python'], $root.'/tools/pdf_print_paper.py',
        (string)$job['file_path'], $settings,
    ], 'Ukuran halaman PDF Epson WF-5790 tidak dapat dibaca.'), true, 512, JSON_THROW_ON_ERROR);
    $paper = (string)($result['paper'] ?? '');
    if (!in_array($paper, ['A4','A5','A6','B5','Letter'], true)) throw new RuntimeException('Ukuran PDF tidak didukung oleh profil WF-5790.');
    $tokens = array_map('trim', explode(',', $settings));
    $range = $tokens[0];
    $parity = in_array('odd', $tokens, true) ? 'odd' : (in_array('even', $tokens, true) ? 'even' : null);
    $duplex = in_array('duplexlong', $tokens, true) ? 'duplexlong' : (in_array('duplexshort', $tokens, true) ? 'duplexshort' : 'simplex');
    $copies = '1x';
    foreach ($tokens as $token) if (preg_match('/^\d+x$/i', $token)) $copies = $token;
    // Keep Sumatra's WF-5790 settings in the same order as the validated command.
    // Native preparation sets and verifies the paper kind, tray and duplex.
    $parts = [$range];
    if ($parity !== null) $parts[] = $parity;
    array_push($parts, 'paper='.$paper, 'bin=7', 'noscale', $duplex, $copies);
    logLine("Job #{$job['id']} ukuran WF-5790 mengikuti PDF: {$paper}, tray Auto Select");
    return implode(',', $parts);
}

function applyEpson5790ProductPaperSize(array $job, string $settings): ?array
{
    global $root;
    if (!isWindowsPrintHost() || !isEpson5790ProductJob($job)) return null;
    $paper = paperSizeFromPrintSettings($settings);
    if ($paper === null && str_contains($settings, 'paper=Letter')) $paper = 'Letter';
    if ($paper === null) throw new RuntimeException('Ukuran PDF WF-5790 belum disiapkan.');
    $printer = (string)$job['printer'];
    $backup = $root.'/storage/print-labels/epson-devmode-job-'.(int)$job['id'].'.bin';
    $tokens = array_map('trim', explode(',', $settings));
    $duplex = in_array('duplexlong', $tokens, true) ? 'duplexlong' : (in_array('duplexshort', $tokens, true) ? 'duplexshort' : 'simplex');
    $bin = preg_match('/(?:^|,)bin=(\d+)(?:,|$)/', $settings, $match) ? (int)$match[1] : 7;
    $result = json_decode(runProcess([
        'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', $root.'/tools/set-epson-pdf-paper.ps1', '-PrinterName', $printer,
        '-Paper', $paper, '-BackupPath', $backup,
        '-Duplex', $duplex, '-InputBin', (string)$bin,
    ], 'Ukuran native driver WF-5790 tidak dapat disiapkan.'), true, 512, JSON_THROW_ON_ERROR);
    if (!isset($result['paperkind'], $result['input_bin'])) throw new RuntimeException('Respons native driver WF-5790 tidak valid.');
    logLine("Job #{$job['id']} native driver WF-5790 mengikuti PDF: {$result['width_mm']} x {$result['height_mm']} mm");
    return ['backup'=>$backup, 'paperkind'=>(int)$result['paperkind'], 'input_bin'=>(int)$result['input_bin']];
}

function restoreEpson5790ProductPaperSize(string $printer, string $backup): void
{
    global $root;
    runProcess([
        'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', $root.'/tools/set-epson-pdf-paper.ps1', '-PrinterName', $printer,
        '-Restore', '-BackupPath', $backup,
    ], 'Konfigurasi native driver WF-5790 tidak dapat dikembalikan.');
    @unlink($backup);
}

function warmBrotherPaperSizeCache(PDO $db): void
{
    global $brotherPaperSizeCache;
    if (!isWindowsPrintHost()) return;
    try {
        $raw = (string)($db->query("SELECT setting_value FROM printer_settings WHERE setting_key='visible_printers'")->fetchColumn() ?: '');
        $printers = json_decode($raw, true);
        if (!is_array($printers)) return;
        foreach ($printers as $printer) {
            $printer = (string)$printer;
            if (stripos($printer, 'Brother') === false) continue;
            $startedAt = microtime(true);
            $brotherPaperSizeCache[$printer] = printerPaperSize($printer);
            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
            logLine("Cache ukuran driver Brother siap: {$printer}={$brotherPaperSizeCache[$printer]} ({$elapsedMs}ms saat startup)");
        }
    } catch (Throwable $error) {
        logLine('Cache ukuran driver Brother tidak dapat dipanaskan: ' . $error->getMessage());
    }
}

function prepareLabelPdf(array $job): string
{
    global $labelPreparer;
    $isL3210 = stripos((string)($job['printer'] ?? ''), 'L3210') !== false;
    if ($isL3210) {
        $result=$labelPreparer->preparePreview((string)$job['file_path']);
        logLine("Job #{$job['id']} memakai PDF yang sama dengan preview resi A6 L3210");
        return (string)$result['path'];
    }
    $result=$labelPreparer->prepare((string)$job['file_path'],(string)$job['printer']);
    return (string)$result['path'];
}

function applyLabelPaperSize(array $job): ?string
{
    global $root;
    if (!isWindowsPrintHost()) return null;
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
        if (!isWindowsPrintHost()) {
            $parts[] = 'paper=A6';
            $parts[] = 'media-type=PLAIN_NORMAL';
            $parts[] = 'ink=MONO';
        } else {
            // Sumatra updates the job DEVMODE, including the Epson media size.
            $parts[] = 'paper=A6';
        }
    } elseif (stripos($printer, 'Brother DCP') !== false) {
        $parts[] = 'bin=258'; // MP Tray, sama dengan aplikasi desktop.
    } elseif (stripos($printer, 'WF') !== false) {
        $parts[] = 'bin=261'; // Rear Paper Feed, sama dengan aplikasi desktop.
    }
    if(!isWindowsPrintHost()&&stripos($printer,'L3210')===false)$parts[]='paper=Custom.105x182mm';
    // L3210 memakai PDF preview A6 dan ukuran driver A6. Printer
    // lain menerima halaman fisik 105 x 182 mm sebagai ukuran custom.
    return implode(',', $parts);
}

function cupsOptions(string $printSettings,string $printer): array
{
    $options=[];
    $brother=stripos($printer,'Brother')!==false;
    $l3210=stripos($printer,'L3210')!==false;
    foreach(array_filter(array_map('trim',explode(',',$printSettings))) as $token){
        $lower=strtolower($token);
        if(preg_match('/^\d+(?:-\d*)?$/',$token))$options[]='page-ranges='.$token;
        elseif(in_array($lower,['odd','even'],true))$options[]='page-set='.$lower;
        elseif($lower==='simplex')$options[]=$brother?'Duplex=None':'sides=one-sided';
        elseif($lower==='duplexlong')$options[]=$brother?'Duplex=DuplexNoTumble':'sides=two-sided-long-edge';
        elseif($lower==='duplexshort')$options[]=$brother?'Duplex=DuplexTumble':'sides=two-sided-short-edge';
        elseif($lower==='monochrome'&&$l3210)$options[]='Ink=MONO';
        elseif($lower==='monochrome'){$options[]='print-color-mode=monochrome';$options[]='ColorModel=Gray';}
        elseif($lower==='color')$options[]='print-color-mode=color';
        elseif($lower==='noscale'&&$l3210)$options[]='print-scaling=none';
        elseif($lower==='noscale'&&!$brother)$options[]='scaling=100';
        elseif($lower==='paper=a5'&&$brother){$options[]='PageSize=A5';$options[]='InputSlot=Tray1';$options[]='MediaType=Stationery';}
        elseif($lower==='paper=b5'&&$brother){$options[]='PageSize=Custom.182x257mm';$options[]='InputSlot=ByPassTray';$options[]='MediaType=Stationery';}
        elseif($lower==='paper=a5')$options[]='media=iso_a5_148x210mm';
        elseif($lower==='paper=a6'&&$l3210)$options[]='PageSize=A6';
        elseif($lower==='paper=b5')$options[]='media=Custom.182x257mm';
        elseif(str_starts_with($lower,'paper='))$options[]='media='.substr($token,6);
        elseif(str_starts_with($lower,'media-type='))$options[]='MediaType='.substr($token,11);
        elseif(str_starts_with($lower,'ink='))$options[]='Ink='.substr($token,4);
        elseif($lower==='paperkind=13')$options[]='media=Custom.182x257mm';
        elseif($lower==='paperkind=88')$options[]=stripos($printer,'L3210')!==false?'PageSize=B6':'media=B6';
        elseif($lower==='bin=1')$options[]='InputSlot=Tray1';
        elseif($lower==='bin=7')$options[]='InputSlot=Auto';
        elseif($lower==='bin=258')$options[]='InputSlot=ByPassTray';
        elseif($lower==='bin=261')$options[]='InputSlot=Rear';
    }
    if(stripos($printer,'WF')!==false)$options[]='cupsPrintQuality=High';
    return array_values(array_unique($options));
}

function cupsPrintCommand(string $printer,string $printSettings,int $copies,string $path): array
{
    $copies=max(1,$copies);
    $command=['lp','-d',$printer,'-n',(string)$copies];
    // Duplex sheets must leave in document order (1 → 20); simplex sheets
    // are deliberately reversed (20 → 1) so the finished stack reads 1 → 20.
    $settingTokens=array_map('trim',explode(',',strtolower($printSettings)));
    $duplex=in_array('duplexlong',$settingTokens,true)||in_array('duplexshort',$settingTokens,true);
    $command[]='-o';
    $command[]='outputorder='.($duplex?'normal':'reverse');
    // Some CUPS/IPP printer profiles default multiple copies to uncollated
    // output (page 1 x N, then page 2 x N).  State collation explicitly so
    // each pack is printed as one complete document before the next pack.
    // Keep both spellings: Collate is understood by PPD-based queues while
    // multiple-document-handling is the standard IPP attribute used by
    // driverless queues.
    if ($copies > 1) {
        $command[]='-o';
        $command[]='Collate=True';
        $command[]='-o';
        $command[]='multiple-document-handling=separate-documents-collated-copies';
    }
    foreach(cupsOptions($printSettings,$printer) as $option){$command[]='-o';$command[]=$option;}
    $command[]=$path;
    return $command;
}

function submitPdfToHost(string $sumatra,string $printer,string $printSettings,int $copies,string $path):?int
{
    if(isWindowsPrintHost()){
        runProcess([$sumatra,'-print-to',$printer,'-print-settings',$printSettings,'-silent',$path],'Gagal menjalankan SumatraPDF.');
        return null;
    }
    $command=cupsPrintCommand($printer,$printSettings,$copies,$path);
    $output=runProcess($command,'Gagal mengirim dokumen ke CUPS.');
    return preg_match('/request id is\s+\S+-(\d+)/i',$output,$match)?(int)$match[1]:null;
}

function submitEpson5790Pdf(array $job,string $printer,string $settings,string $path):array
{
    global $root,$sumatra;
    $tokens=array_map('trim',explode(',',$settings));
    $duplex=in_array('duplexlong',$tokens,true)?'duplexlong':(in_array('duplexshort',$tokens,true)?'duplexshort':'simplex');
    $paper=paperSizeFromPrintSettings($settings);
    if($paper===null&&in_array('paper=Letter',$tokens,true))$paper='Letter';
    if($paper===null)throw new RuntimeException('Ukuran PDF WF-5790 belum disiapkan.');
    $backup=$root.'/storage/print-labels/epson-devmode-job-'.(int)$job['id'].'.bin';
    $result=json_decode(runProcess([
        'powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass',
        '-File',$root.'/tools/print-epson-pdf.ps1','-PrinterName',$printer,
        '-Paper',$paper,'-BackupPath',$backup,'-Settings',$settings,'-PdfPath',$path,
        '-Sumatra',$sumatra,'-Duplex',$duplex,'-InputBin','7',
    ],'Gagal mengirim PDF WF-5790.'),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($result)||($result['submitted']??false)!==true)throw new RuntimeException('Respons pengiriman WF-5790 tidak valid.');
    if(!empty($result['restore_error']))logLine('Native driver WF-5790 gagal dikembalikan: '.$result['restore_error'].'; backup: '.$backup);
    logLine("Job #{$job['id']} sesi WF-5790: native={$result['apply_ms']}ms, submit={$result['submit_ms']}ms, restore={$result['restore_ms']}ms; {$result['width_mm']} x {$result['height_mm']} mm, Auto Select");
    return $result;
}

if(defined('PAPERBELL_PRINT_WORKER_FUNCTIONS_ONLY'))return;

$workerLocks=acquirePrintWorkerLocks($root.'/storage/worker-locks',$printerGroup);
$db = connectDatabase();
recoverInterruptedJobs($db);
if(in_array($printerGroup,['all','brother'],true))warmBrotherPaperSizeCache($db);
logLine("Worker printer aktif: kelompok {$printerGroup}");

do {
    $job = null;
    $preparedLabelPath = null;
    $temporaryPaperSize = null;
    $temporaryLabelPrintTicket = null;
    $printPrinter = null;
    $processingStartedAt = 0.0;
    $timings = ['prepare' => 0, 'driver' => 0, 'sumatra' => 0, 'correlate' => 0];
    try {
        $heartbeat = $db->prepare("INSERT INTO app_meta(meta_key,meta_value) VALUES('print_worker_heartbeat',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        $heartbeat->execute([(string)time()]);
        if($printerGroup!=='all'){
            $groupHeartbeat=$db->prepare("INSERT INTO app_meta(meta_key,meta_value) VALUES(?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            $groupHeartbeat->execute(['print_worker_heartbeat_'.$printerGroup,(string)time()]);
        }
        $db->beginTransaction();
        $scope=printerGroupSql($printerGroup);
        $candidates = $db->query("SELECT * FROM print_jobs WHERE status='queued'{$scope} ORDER BY id LIMIT 25 FOR UPDATE")->fetchAll();
        $gateMessages=[];
        foreach($candidates as $candidate){
            $candidatePrinter=printPrinterForJob($candidate,(string)$candidate['print_settings']);
            $gateReason=cupsSubmissionGateReason($candidatePrinter);
            if($gateReason===null){$job=$candidate;break;}
            $gateMessages[(int)$candidate['id']]=$gateReason;
        }
        if (!$job) {
            if($gateMessages){
                $waiting=$db->prepare("UPDATE print_jobs SET message=? WHERE id=? AND status='queued' AND message<>?");
                foreach($gateMessages as $id=>$message)$waiting->execute([$message,$id,$message]);
            }
            $db->commit();
            if ($once) break;
            usleep(1000000);
            continue;
        }
        $claim = $db->prepare("UPDATE print_jobs SET status='processing',message='Menyiapkan dokumen',started_at=?,attempts=attempts+1 WHERE id=? AND status='queued'");
        $claim->execute([time(), $job['id']]);
        $db->commit();
        $processingStartedAt = microtime(true);

        if (isWindowsPrintHost()&&!is_file($sumatra)) throw new RuntimeException('SumatraPDF tidak ditemukan.');
        $job['file_path']=$hostPathResolver->resolve((string)$job['file_path']);
        if (!is_file($job['file_path'])) throw new RuntimeException('File PDF tidak ditemukan: ' . $job['file_path']);

        $printPath = (string)$job['file_path'];
        $printSettings = epson5790PdfPrintSettings($job, (string)$job['print_settings']);
        $printPrinter = (string)$job['printer'];
        if ($job['job_type'] === 'label') {
            $stageStartedAt = microtime(true);
            $preparedLabelPath = prepareLabelPdf($job);
            $timings['prepare'] = (int)round((microtime(true) - $stageStartedAt) * 1000);
            $printPath = $preparedLabelPath;
            $printSettings = labelPrintSettings((string)$job['printer']);
            $temporaryLabelPrintTicket = applyLabelPaperSize($job);
        }

        $printPrinter = printPrinterForJob($job, $printSettings);
        if ($printPrinter !== (string)$job['printer']) {
            logLine("Job #{$job['id']} printer dialihkan: {$job['printer']} -> {$printPrinter}");
        }

        $stageStartedAt = microtime(true);
        if ($printPrinter === (string)$job['printer']) {
            $temporaryPaperSize = applyBrotherProductPaperSize($job, $printSettings);
        }
        $epsonSession=isWindowsPrintHost()&&isEpson5790ProductJob(array_replace($job,['printer'=>$printPrinter]));
        $timings['driver'] = (int)round((microtime(true) - $stageStartedAt) * 1000);
        $spoolerName=isWindowsPrintHost()?'Windows spooler':'CUPS';
        $submitting = $db->prepare("UPDATE print_jobs SET message=? WHERE id=? AND status='processing'");
        $submitting->execute(['Mengirim ke '.$spoolerName,$job['id']]);
        $stageStartedAt = microtime(true);
        if($epsonSession){
            $nativeResult=submitEpson5790Pdf($job,$printPrinter,$printSettings,$printPath);
            $spoolerJobId=null;
            $timings['driver']=(int)$nativeResult['apply_ms'];
        }else{
            $spoolerJobId=submitPdfToHost($sumatra,$printPrinter,$printSettings,(int)$job['copies'],$printPath);
        }
        $timings['sumatra'] = $epsonSession ? (int)$nativeResult['submit_ms'] : (int)round((microtime(true) - $stageStartedAt) * 1000);

        // Jangan menahan worker untuk membaca ulang WMI/Get-PrintJob. Pada host
        // ini korelasi ID dapat memakan lebih dari 60 detik setelah printer
        // sudah mulai bekerja. Widget spooler tetap membaca antrean terpisah.
        if(isWindowsPrintHost())$spoolerJobId=null;
        $timings['correlate'] = 0;

        // The host spooler now owns the submitted job. Drop its cached snapshot
        // so the web widget can show it on its very next refresh.
        @unlink($root . '/storage/printer-spooler-cache.json');

        $state = $db->prepare('SELECT status FROM print_jobs WHERE id=?');
        $state->execute([$job['id']]);
        if ($state->fetchColumn() === 'cancel_requested') {
            if(!isWindowsPrintHost()&&$spoolerJobId!==null)runProcess(['cancel',$printPrinter.'-'.$spoolerJobId],'Gagal membatalkan job yang sudah dikirim ke CUPS.');
            $cancelled=$db->prepare("UPDATE print_jobs SET status='cancelled',message='Pembatalan selesai',completed_at=?,submitted_at=?,spooler_job_id=? WHERE id=? AND status='cancel_requested'");
            $cancelledAt=time();$cancelled->execute([$cancelledAt,$cancelledAt,$spoolerJobId,$job['id']]);
            logLine("Job #{$job['id']} cancelled after submission to {$spoolerName}");
            continue;
        }

        $db->beginTransaction();
        $done = $db->prepare("UPDATE print_jobs SET status='submitted',message=?,completed_at=?,submitted_at=?,spooler_job_id=?,error='' WHERE id=? AND status='processing'");
        $submittedAt=time();$done->execute([$spoolerJobId===null?'Diserahkan ke '.$spoolerName:'Diserahkan ke '.$spoolerName.' #'.$spoolerJobId,$submittedAt,$submittedAt,$spoolerJobId,$job['id']]);
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
            refreshOrderPrintSummary($db,(string)$job['order_sn']);
        } elseif ($job['job_type'] === 'label') {
            $mark = $db->prepare('UPDATE order_resi SET resi_printed=1,resi_printed_at=? WHERE order_sn=?');
            $mark->execute([time(), $job['order_sn']]);
        }
        $db->commit();
        $totalMs = $processingStartedAt > 0 ? (int)round((microtime(true) - $processingStartedAt) * 1000) : 0;
        logLine("Job #{$job['id']} submitted to {$spoolerName}: {$printPrinter} [prepare={$timings['prepare']}ms, driver={$timings['driver']}ms, submit={$timings['sumatra']}ms, correlate={$timings['correlate']}ms, total={$totalMs}ms]");
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
        if ($temporaryPaperSize !== null && $printPrinter !== null) {
            try {
                setPrinterPaperSize($printPrinter, $temporaryPaperSize);
                $brotherPaperSizeCache[$printPrinter] = $temporaryPaperSize;
                logLine("Job #{$job['id']} ukuran driver {$printPrinter} dikembalikan ke {$temporaryPaperSize}");
            } catch (Throwable $restoreError) {
                unset($brotherPaperSizeCache[$printPrinter]);
                logLine('Ukuran driver printer gagal dikembalikan: ' . $restoreError->getMessage());
            }
        }
    }
    if ($once) break;
} while (true);
