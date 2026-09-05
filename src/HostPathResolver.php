<?php
declare(strict_types=1);

final class HostPathResolver
{
    private string $windowsPrintRoot;
    private string $ubuntuPrintRoot;

    public function __construct(array $config = [])
    {
        $this->windowsPrintRoot = $this->normalize((string)($config['windows_print_root'] ?? 'H:/My Drive/Paperbell/Print'));
        $this->ubuntuPrintRoot = rtrim($this->normalize((string)($config['ubuntu_print_root'] ?? '/home/herman/GoogleDrive/Paperbell/Print')), '/');
    }

    public function resolve(string $path): string
    {
        $path = trim($path);
        if ($path === '' || PHP_OS_FAMILY === 'Windows') return $path;

        $normalized = $this->normalize($path);
        $rootLength = strlen($this->windowsPrintRoot);
        if (strncasecmp($normalized, $this->windowsPrintRoot, $rootLength) !== 0) return $normalized;

        $suffix = substr($normalized, $rootLength);
        if ($suffix !== '' && $suffix[0] !== '/') return $normalized;
        return $this->ubuntuPrintRoot . ($suffix === '' ? '' : '/' . ltrim($suffix, '/'));
    }

    private function normalize(string $path): string
    {
        return preg_replace('~/+~', '/', str_replace('\\', '/', trim($path))) ?? $path;
    }
}
