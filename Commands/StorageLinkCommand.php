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
final class StorageLinkCommand implements \Siro\Core\Commands\CommandInterface
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

        // Store an absolute target: with a relative basePath the raw
        // "proj/storage/public" target would resolve against public/
        // (proj/public/proj/storage/public) and the link would be broken.
        $absoluteStorage = realpath($storageDir);
        if ($absoluteStorage !== false) {
            $storageDir = $absoluteStorage;
        }

        if (is_link($publicDir)) {
            // A real symlink: "already exists" only when it points at the
            // storage dir we serve. A link elsewhere must not be reported
            // as success, and must not be silently replaced.
            $resolved = realpath($publicDir);
            $expected = realpath($storageDir);
            $pointsAtStorage = $resolved !== false && $expected !== false && $resolved === $expected;
            $rawTargetMatches = readlink($publicDir) === $storageDir;
            if ($pointsAtStorage || $rawTargetMatches) {
                $this->write('Link already exists: public/storage');
                return 0;
            }
            $this->write('public/storage is already a symlink elsewhere: ' . (string) readlink($publicDir));
            $this->write('Remove it first, then run: php siro storage:link');
            return 1;
        }

        if (file_exists($publicDir)) {
            // Skeleton default: public/storage is a real directory (with
            // .gitkeep), not a link. Reporting "already exists" + exit 0
            // here is a false success — storage/public/ would never be
            // served. Never delete user data silently: report and exit 1.
            if (is_dir($publicDir)) {
                $this->write('public/storage already exists as a directory, not a symlink.');
                $this->write('Files in storage/public/ are NOT served at /storage/...');
                $this->write('Move or remove the directory first (it may contain files),');
                $this->write('then run: php siro storage:link');
            } else {
                $this->write('public/storage already exists as a file, not a symlink.');
                $this->write('Remove the file first, then run: php siro storage:link');
            }
            return 1;
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
            return @symlink($link, $target);
        } catch (\Throwable) {
            return false;
        }
    }

    private function createJunction(string $target, string $link): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return false;
        }

        $cmd = sprintf('cmd /c mklink /J %s %s 2>&1', escapeshellarg($target), escapeshellarg($link));
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        return $returnCode === 0;
    }
}
