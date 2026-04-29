<?php

declare(strict_types=1);

namespace Siro\Core\Commands;

use RuntimeException;

/**
 * Create a symbolic link from public/storage to storage/.
 *
 * Enables serving uploaded files via the web server
 * at /storage/... URLs.
 *
 * @package Siro\Core\Commands
 */
final class StorageLinkCommand
{
    use CommandSupport;

    public function __construct(private readonly string $basePath)
    {
    }

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $publicDir = $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'storage';
        $storageDir = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public';

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        if (is_link($publicDir) || file_exists($publicDir)) {
            $this->write('Link already exists: public/storage');
            return 0;
        }

        $created = $this->createSymlink($publicDir, $storageDir);

        if (!$created) {
            // Fallback: create a route file or copy
            $this->write('Could not create symlink. Trying directory junction...');
            $created = $this->createJunction($publicDir, $storageDir);
        }

        if ($created) {
            $this->write('Link created: public/storage -> storage/');
            $this->write('Uploaded files are now accessible at: /storage/...');
            return 0;
        }

        $this->write('Could not create symlink. On Windows, try running as Administrator.');
        $this->write('Alternative: configure your web server to serve files from storage/');
        return 1;
    }

    private function createSymlink(string $target, string $link): bool
    {
        try {
            return symlink($link, $target);
        } catch (\Throwable) {
            return false;
        }
    }

    private function createJunction(string $target, string $link): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }

        $cmd = sprintf('mklink /J %s %s', escapeshellarg($target), escapeshellarg($link));
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }
}
