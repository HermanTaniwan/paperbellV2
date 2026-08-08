<?php
declare(strict_types=1);

final class ScannerService
{
    private string $python;
    private string $script;
    private string $jobsDir;
    private string $defaultSource;

    public function __construct(array $config, string $projectRoot)
    {
        $this->python = (string)($config['python'] ?? 'python');
        $this->script = $projectRoot . '/tools/adf_scanner.py';
        $this->jobsDir = $projectRoot . '/storage/scanner/jobs';
        $this->defaultSource = (string)($config['default_source'] ?? 'EPSON WF-C5710/C5790 Series');
        if (!is_dir($this->jobsDir) && !mkdir($this->jobsDir, 0775, true) && !is_dir($this->jobsDir)) {
            throw new RuntimeException('Folder job scanner tidak dapat dibuat.');
        }
    }

    public function overview(): array
    {
        $jobs = $this->jobs(12);
        $active = null;
        foreach ($jobs as $job) {
            if (in_array($job['status'] ?? '', ['queued', 'starting', 'scanning', 'analyzing'], true)) {
                $active = $job;
                break;
            }
        }

        $sources = [];
        $sourceError = '';
        try {
            $sources = $this->sources();
        } catch (Throwable $e) {
            $sourceError = $e->getMessage();
        }

        return [
            'available' => $sourceError === '' && count($sources) > 0,
            'sources' => $sources,
            'source_error' => $sourceError,
            'default_source' => in_array($this->defaultSource, $sources, true)
                ? $this->defaultSource
                : ($sources[0] ?? ''),
            'active' => $active,
            'jobs' => $jobs,
            'defaults' => [
                'dpi' => 200,
                'paper' => 'A5 landscape',
                'color' => 'Color',
                'sides' => 'Single-sided',
                'blank_threshold' => 0.18,
            ],
        ];
    }

    public function sources(): array
    {
        if (!is_file($this->script)) throw new RuntimeException('Runner ADF scanner tidak ditemukan.');
        if (!is_file($this->python)) throw new RuntimeException('Python scanner tidak ditemukan: ' . $this->python);

        $pipes = [];
        $process = proc_open(
            [$this->python, $this->script, 'list-sources'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($this->script),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) throw new RuntimeException('Tidak dapat menjalankan pemeriksaan TWAIN.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string)$stderr) ?: 'Pemeriksaan TWAIN gagal.');
        }
        $decoded = json_decode((string)$stdout, true);
        if (!is_array($decoded) || !isset($decoded['sources']) || !is_array($decoded['sources'])) {
            throw new RuntimeException('Respons TWAIN tidak valid.');
        }
        return array_values(array_map('strval', $decoded['sources']));
    }

    public function start(array $input, string $createdBy): array
    {
        $lockPath = dirname($this->jobsDir) . '/start.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Scanner sedang dikunci proses lain.');
        try {
            foreach ($this->jobs(20) as $job) {
                if (in_array($job['status'] ?? '', ['queued', 'starting', 'scanning', 'analyzing'], true)) {
                    throw new RuntimeException('Masih ada proses scan aktif. Tunggu hingga selesai sebelum memulai lagi.');
                }
            }

            $sources = $this->sources();
            $source = trim((string)($input['source'] ?? $this->defaultSource));
            if (!in_array($source, $sources, true)) throw new InvalidArgumentException('TWAIN source yang dipilih tidak tersedia.');
            $threshold = (float)($input['blank_threshold'] ?? 0.18);
            if ($threshold < 0.01 || $threshold > 5.0) {
                throw new InvalidArgumentException('Batas blank harus di antara 0.01% dan 5.00%.');
            }

            $id = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
            $jobDir = $this->jobsDir . '/' . $id;
            if (!mkdir($jobDir, 0775, true) && !is_dir($jobDir)) throw new RuntimeException('Folder hasil scan tidak dapat dibuat.');
            $state = [
                'id' => $id,
                'status' => 'queued',
                'message' => 'Menyiapkan proses scanner…',
                'error' => '',
                'created_at' => date(DATE_ATOM),
                'updated_at' => date(DATE_ATOM),
                'created_by' => $createdBy,
                'source' => $source,
                'settings' => [
                    'source' => $source,
                    'dpi' => 200,
                    'paper' => 'A5 landscape',
                    'color' => 'Color',
                    'sides' => 'Single-sided',
                    'blank_threshold' => round($threshold, 2),
                ],
                'captured_pages' => 0,
                'total_pages' => 0,
                'total_sheets' => 0,
                'printed_pages' => 0,
                'blank_pages' => 0,
                'blank_page_numbers' => [],
                'pages' => [],
                'pdf_available' => false,
                'report_available' => false,
            ];
            $this->writeState($jobDir, $state);
            try {
                $pid = $this->launch($jobDir);
                $state['process_id'] = $pid;
                $this->writeState($jobDir, $state);
            } catch (Throwable $e) {
                $state['status'] = 'failed';
                $state['error'] = $e->getMessage();
                $state['message'] = 'Runner scanner tidak dapat dijalankan.';
                $state['completed_at'] = date(DATE_ATOM);
                $this->writeState($jobDir, $state);
                throw $e;
            }
            return $this->decorate($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function job(string $id): array
    {
        $jobDir = $this->jobDir($id);
        $path = $jobDir . '/status.json';
        if (!is_file($path)) throw new RuntimeException('Job scanner tidak ditemukan.');
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) throw new RuntimeException('Status job scanner rusak.');
        return $this->decorate($decoded);
    }

    public function cancel(string $id): array
    {
        $jobDir = $this->jobDir($id);
        $path = $jobDir . '/status.json';
        if (!is_file($path)) throw new RuntimeException('Job scanner tidak ditemukan.');
        $state = json_decode((string)file_get_contents($path), true);
        if (!is_array($state)) throw new RuntimeException('Status job scanner rusak.');
        if (!in_array($state['status'] ?? '', ['queued', 'starting', 'scanning', 'analyzing'], true)) {
            return $this->decorate($state);
        }

        $pid = (int)($state['process_id'] ?? 0);
        if ($pid > 0) {
            $needle = 'adf_scanner.py scan --job-dir';
            $command = '$p=Get-CimInstance Win32_Process -Filter "ProcessId = ' . $pid . '" -ErrorAction SilentlyContinue;'
                . 'if($p -and $p.CommandLine -like ' . $this->powerShellQuote('*' . $needle . '*')
                . ' -and $p.CommandLine -like ' . $this->powerShellQuote('*' . $id . '*')
                . '){Stop-Process -Id ' . $pid . ' -Force -ErrorAction SilentlyContinue}';
            $pipes = [];
            $process = proc_open(
                ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $command],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname($this->script),
                null,
                ['bypass_shell' => true]
            );
            if (is_resource($process)) {
                stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
                fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
            }
        }
        $state['status'] = 'cancelled';
        $state['message'] = 'Scan dibatalkan.';
        $state['error'] = '';
        $state['completed_at'] = date(DATE_ATOM);
        $state['updated_at'] = date(DATE_ATOM);
        $this->writeState($jobDir, $state);
        return $this->decorate($state);
    }

    public function file(string $id, string $type, int $page = 0): array
    {
        $jobDir = $this->jobDir($id);
        if ($type === 'pdf') {
            $path = $jobDir . '/scan.pdf';
            $mime = 'application/pdf';
            $name = 'scan_' . $id . '.pdf';
        } elseif ($type === 'report') {
            $path = $jobDir . '/report.csv';
            $mime = 'text/csv; charset=utf-8';
            $name = 'report_' . $id . '.csv';
        } elseif ($type === 'page' && $page > 0) {
            $path = $jobDir . '/page_' . str_pad((string)$page, 4, '0', STR_PAD_LEFT) . '.png';
            $mime = 'image/png';
            $name = basename($path);
        } else {
            throw new InvalidArgumentException('Jenis file scanner tidak valid.');
        }
        if (!is_file($path)) throw new RuntimeException('File hasil scanner tidak ditemukan.');
        $realJob = realpath($jobDir);
        $realFile = realpath($path);
        if ($realJob === false || $realFile === false || !str_starts_with(strtolower($realFile), strtolower($realJob . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Lokasi file scanner tidak valid.');
        }
        return ['path' => $realFile, 'mime' => $mime, 'name' => $name];
    }

    private function jobs(int $limit): array
    {
        $paths = glob($this->jobsDir . '/*/status.json') ?: [];
        usort($paths, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $jobs = [];
        foreach (array_slice($paths, 0, $limit) as $path) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) $jobs[] = $this->decorate($decoded);
        }
        return $jobs;
    }

    private function decorate(array $job): array
    {
        $id = (string)($job['id'] ?? '');
        $job['is_active'] = in_array($job['status'] ?? '', ['queued', 'starting', 'scanning', 'analyzing'], true);
        $job['pdf_url'] = !empty($job['pdf_available']) ? 'api.php?action=scanner_file&type=pdf&id=' . rawurlencode($id) : '';
        $job['report_url'] = !empty($job['report_available']) ? 'api.php?action=scanner_file&type=report&id=' . rawurlencode($id) : '';
        foreach ($job['pages'] ?? [] as &$page) {
            $page['preview_url'] = 'api.php?action=scanner_file&type=page&id=' . rawurlencode($id) . '&page=' . (int)($page['number'] ?? 0);
        }
        unset($page);
        return $job;
    }

    private function launch(string $jobDir): int
    {
        if (!is_file($this->python)) throw new RuntimeException('Python scanner tidak ditemukan: ' . $this->python);
        $arguments = [$this->script, 'scan', '--job-dir', $jobDir];
        $argumentList = implode(',', array_map($this->powerShellQuote(...), $arguments));
        $command = '$p=Start-Process -FilePath ' . $this->powerShellQuote($this->python)
            . ' -ArgumentList @(' . $argumentList . ') -WorkingDirectory ' . $this->powerShellQuote(dirname($this->script))
            . ' -WindowStyle Hidden -PassThru; [Console]::Out.Write($p.Id)';
        $pipes = [];
        $process = proc_open(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($this->script),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) throw new RuntimeException('Tidak dapat membuka launcher scanner.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $pid = (int)trim((string)$stdout);
        if ($exitCode !== 0 || $pid < 1) {
            throw new RuntimeException(trim((string)$stderr) ?: 'Proses scanner tidak berhasil dijalankan.');
        }
        return $pid;
    }

    private function powerShellQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function writeState(string $jobDir, array $state): void
    {
        $temporary = $jobDir . '/status.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $jobDir . '/status.json')) {
            @unlink($temporary);
            throw new RuntimeException('Status scanner tidak dapat disimpan.');
        }
    }

    private function jobDir(string $id): string
    {
        if (!preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/', $id)) throw new InvalidArgumentException('ID job scanner tidak valid.');
        return $this->jobsDir . '/' . $id;
    }
}
