<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/HostPathResolver.php';

$resolver = new HostPathResolver([
    'windows_print_root' => 'H:/My Drive/Paperbell/Print',
    'ubuntu_print_root' => '/home/herman/GoogleDrive/Paperbell/Print',
]);

$actual = $resolver->resolve('H:\\My Drive\\Paperbell\\Print\\Journal\\ORIGINAL\\Cat A5.pdf');
$expected = PHP_OS_FAMILY === 'Windows'
    ? 'H:\\My Drive\\Paperbell\\Print\\Journal\\ORIGINAL\\Cat A5.pdf'
    : '/home/herman/GoogleDrive/Paperbell/Print/Journal/ORIGINAL/Cat A5.pdf';
if ($actual !== $expected) throw new RuntimeException("Path hasil resolver salah: {$actual}");

$outside = '/var/www/html/paperbell/storage/manual-pdfs/example.pdf';
if ($resolver->resolve($outside) !== $outside) throw new RuntimeException('Path lokal Paperbell tidak boleh berubah.');

$similar = 'H:/My Drive/Paperbell/Printer/example.pdf';
$similarExpected = PHP_OS_FAMILY === 'Windows' ? $similar : str_replace('\\', '/', $similar);
if ($resolver->resolve($similar) !== $similarExpected) throw new RuntimeException('Prefix folder yang mirip tidak boleh diterjemahkan.');

echo "Host path resolver tests passed\n";
