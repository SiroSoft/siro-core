<?php

declare(strict_types=1);

namespace Siro\Core;

use RuntimeException;

/**
 * Uploaded file handler.
 *
 * Wraps a PHP $_FILES entry with validation and storage methods.
 * Supports file info, MIME type detection, and secure storage.
 *
 * @package Siro\Core
 */
final class UploadedFile
{
    private readonly string $path;
    private readonly string $originalName;
    private readonly string $mimeType;
    private readonly int $size;
    private readonly int $error;

    /** Whitelist of allowed extensions for upload. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'json', 'xml', 'doc', 'docx', 'zip'];

    /** @param array<string, string|int> $file */
    public function __construct(array $file)
    {
        $this->path = (string) ($file['tmp_name'] ?? '');
        $this->originalName = (string) ($file['name'] ?? '');
        $this->mimeType = (string) ($file['type'] ?? '');
        $this->size = (int) ($file['size'] ?? 0);
        $this->error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->path);
    }

    public function getClientOriginalName(): string
    {
        return $this->originalName;
    }

    public function getClientOriginalExtension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * Get MIME type using finfo.
     */
    public function getMimeType(): string
    {
        if ($this->isValid()) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo === false) {
                return $this->mimeType;
            }
            $type = finfo_file($finfo, $this->path);
            finfo_close($finfo);
            return $type !== false ? $type : $this->mimeType;
        }

        return $this->mimeType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getPathname(): string
    {
        return $this->path;
    }

    public function store(string $directory, ?string $name = null, bool $useStorage = false): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException('Cannot store an invalid uploaded file.');
        }

        // BLOCK path traversal attacks - sanitize directory parameter
        $directory = trim($directory, '/\\');

        // Reject dangerous path components
        if (preg_match('/\.\.|^\/|^\\\\|:/', $directory)) {
            throw new RuntimeException('Invalid directory path: contains illegal characters');
        }

        // Only allow alphanumeric, hyphens, underscores, and forward slashes
        if (!preg_match('/^[a-zA-Z0-9_\-\/]+$/', $directory)) {
            throw new RuntimeException('Invalid directory path: only alphanumeric, hyphens, underscores, and slashes allowed');
        }

        // Sanitize filename if provided
        if ($name !== null) {
            // Remove path components from filename to prevent traversal
            $name = basename($name);

            // Only allow safe characters in filename
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $name)) {
                throw new RuntimeException('Invalid filename: only alphanumeric, hyphens, underscores, and dots allowed');
            }
        }

        $filename = $name ?? $this->generateFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true) && $ext !== '') {
            throw new \RuntimeException('File type not allowed: .' . $ext . ' (allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS) . ')');
        }
        $path = $directory . '/' . $filename;

        if ($useStorage) {
            $content = file_get_contents($this->path);
            if ($content === false) {
                throw new RuntimeException('Failed to read uploaded file content.');
            }
            Storage::put($path, $content);
            return Storage::url($path);
        }

        $basePath = defined('BASE_PATH') && is_string(BASE_PATH) ? BASE_PATH : (defined('SIRO_BASE_PATH') && is_string(SIRO_BASE_PATH) ? SIRO_BASE_PATH : dirname(__DIR__, 2));
        $storagePublicRoot = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public';
        $realStoragePublicRoot = realpath($storagePublicRoot);
        if ($realStoragePublicRoot === false) {
            throw new RuntimeException('Storage public directory not found at: ' . $storagePublicRoot);
        }

        $publicDir = $storagePublicRoot . DIRECTORY_SEPARATOR . $directory;

        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0775, true);
        }

        $realPublicDir = realpath($publicDir);
        if ($realPublicDir === false || !str_starts_with($realPublicDir, $realStoragePublicRoot)) {
            throw new RuntimeException('Directory path resolves outside allowed storage area');
        }

        $destPath = $publicDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($this->path, $destPath)) {
            throw new RuntimeException(sprintf('Failed to move uploaded file to %s', $destPath));
        }

        return '/storage/' . $directory . '/' . $filename;
    }

    public function storeAs(string $directory, string $name): string
    {
        return $this->store($directory, $name);
    }

    private const MIME_MAP = [
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'jpg' => ['image/jpeg', 'image/jpg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv'],
        'json' => ['application/json'],
        'xml' => ['application/xml'],
        'zip' => ['application/zip'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    public function isImage(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (!$this->mimeMatchesExtension(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            return false;
        }

        $mime = $this->getMimeType();
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    public function isPdf(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        if (!$this->mimeMatchesExtension(['application/pdf'])) {
            return false;
        }

        return $this->getMimeType() === 'application/pdf';
    }

    /** @param array<int, string> $allowedMimes */
    private function mimeMatchesExtension(array $allowedMimes): bool
    {
        $ext = $this->getClientOriginalExtension();
        if ($ext === '' || !isset(self::MIME_MAP[$ext])) {
            return false;
        }

        $expectedMimes = self::MIME_MAP[$ext];
        $actualMime = $this->getMimeType();

        return in_array($actualMime, $expectedMimes, true);
    }

    public function hash(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }

        $path = $this->getPathname();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $hash = hash_file('sha256', $path);
        return $hash !== false ? $hash : null;
    }

    public function extension(): string
    {
        return $this->getClientOriginalExtension();
    }

    public function name(): string
    {
        return pathinfo($this->originalName, PATHINFO_FILENAME) ?: $this->originalName;
    }

    public static function maxSize(): int
    {
        return min(self::parseIniSize((string) ini_get('upload_max_filesize')), self::parseIniSize((string) ini_get('post_max_size')));
    }

    private static function parseIniSize(string $value): int
    {
        $value = trim((string) $value);
        if ($value === '') return 0;
        $unit = strtolower(substr($value, -1));
        $size = (int) $value;
        return match ($unit) {
            'g' => $size * 1024 * 1024 * 1024,
            'm' => $size * 1024 * 1024,
            'k' => $size * 1024,
            default => $size,
        };
    }

    private function generateFilename(): string
    {
        $ext = $this->getClientOriginalExtension();
        if ($ext !== '') {
            $allowedExts = array_keys(self::MIME_MAP);
            if (!in_array($ext, $allowedExts, true)) {
                $mime = $this->getMimeType();
                $ext = 'bin';
                foreach (self::MIME_MAP as $mapExt => $mapMimes) {
                    if (in_array($mime, $mapMimes, true)) {
                        $ext = $mapExt;
                        break;
                    }
                }
            }
        }
        $base = bin2hex(random_bytes(16));

        return $ext !== '' ? $base . '.' . $ext : $base;
    }
}
