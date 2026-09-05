<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Paperbell Web',
        'timezone' => 'Asia/Jakarta',
        'base_path' => '',
        'public_url' => rtrim(getenv('PAPERBELL_PUBLIC_URL') ?: '', '/'),
        'poll_seconds' => 10,
    ],
    'mysql' => [
        'host' => getenv('PAPERBELL_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('PAPERBELL_DB_PORT') ?: 3306),
        'database' => getenv('PAPERBELL_DB_NAME') ?: 'paperbell',
        'username' => getenv('PAPERBELL_DB_USER') ?: 'root',
        'password' => getenv('PAPERBELL_DB_PASSWORD') ?: '',
    ],
    'printing' => [
        'sumatra' => getenv('PAPERBELL_SUMATRA_PATH') ?: (PHP_OS_FAMILY==='Windows'?((getenv('LOCALAPPDATA') ?: '') . '/SumatraPDF/SumatraPDF.exe'):''),
        'default_label_printer' => getenv('PAPERBELL_LABEL_PRINTER') ?: 'EPSON L3210 Series',
        'brother_b5_printer' => getenv('PAPERBELL_BROTHER_B5_PRINTER') ?: 'Brother DCP-T830DW B5',
        'python' => getenv('PAPERBELL_PYTHON_PATH') ?: (PHP_OS_FAMILY==='Windows'?'C:/Users/Herman Taniwan/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe':'python3'),
    ],
    'scanner' => [
        // WFScanner's Python 3.11 environment already contains pytwain, Pillow, and pywin32.
        'python' => getenv('PAPERBELL_SCANNER_PYTHON_PATH') ?: 'C:/Users/Herman Taniwan/AppData/Local/Programs/Python/Python311/python.exe',
        'default_source' => getenv('PAPERBELL_SCANNER_SOURCE') ?: 'EPSON WF-C5710/C5790 Series',
    ],
    'server_health' => [
        'powershell' => getenv('PAPERBELL_POWERSHELL_PATH') ?: 'powershell.exe',
        'librehardwaremonitor_library' => __DIR__ . '/storage/librehardwaremonitor/LibreHardwareMonitorLib.dll',
        'cache_seconds' => 60,
        'thresholds' => ['offline_after_seconds'=>300,'cpu'=>['warning'=>80,'critical'=>95],'memory'=>['warning'=>80,'critical'=>90],'disk'=>['warning'=>80,'critical'=>90],'cpu_temperature'=>['warning'=>80,'critical'=>90],'ssd_temperature'=>['warning'=>65,'critical'=>75]],
    ],
    'mapping' => [
        'spreadsheet_id' => getenv('PAPERBELL_MAPPING_SHEET_ID') ?: '1eXwQ_H8ofVroEYlK5X90bvlT66f5a8Q5tnVtTAKNHy4',
        'gid' => getenv('PAPERBELL_MAPPING_SHEET_GID') ?: '0',
    ],
    'auth' => [
        'enabled' => filter_var(getenv('PAPERBELL_AUTH_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL),
        'username' => getenv('PAPERBELL_WEB_USER') ?: 'admin',
        'password' => getenv('PAPERBELL_WEB_PASSWORD') ?: 'paperbell123',
    ],
    'oauth' => [
        'key_file' => __DIR__ . '/storage/secrets/oauth.key',
    ],
];
