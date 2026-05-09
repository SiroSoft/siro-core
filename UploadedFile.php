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

    /** @param array<string, mixed> $file */
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
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
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
        $path = $directory . '/' . $filename;

        if ($useStorage) {
            $content = file_get_contents($this->path);
            if ($content === false) {
                throw new RuntimeException('Failed to read uploaded file content.');
            }
            Storage::put($path, $content);
            return Storage::url($path);
        }

        $publicDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $directory;

        // Validate final resolved path stays within allowed directory
        $realPublicDir = realpath(dirname($publicDir));
        if ($realPublicDir === false || strpos(realpath($publicDir) ?: '', $realPublicDir) !== 0) {
            throw new RuntimeException('Directory path resolves outside allowed storage area');
        }

        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0775, true);
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

    public function isImage(): bool
    {
        if (!$this->isValid()) {
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

        return $this->getMimeType() === 'application/pdf';
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
        $maxUpload = (int) ini_get('upload_max_filesize');
        $maxPost = (int) ini_get('post_max_size');
        return min($maxUpload, $maxPost) * 1024 * 1024;
    }

    private function generateFilename(): string
    {
        $ext = $this->getClientOriginalExtension();
        $base = bin2hex(random_bytes(16));

        return $ext !== '' ? $base . '.' . $ext : $base;
    }
}
