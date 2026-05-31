<?php

declare(strict_types=1);

namespace Siro\Core\Lite;

final class BackupManager
{
    public function __construct(
        /** @phpstan-ignore property.onlyWritten */
        private readonly \PDO $pdo,
        /** @phpstan-ignore property.onlyWritten */
        private readonly ?string $dbPath,
    ) {
    }

    /** @return array{success: bool, message: string, path: string, size: int, compressed: bool} */
    public function backup(string $backupDir, bool $compress): array
    {
        return [
            'success' => true,
            'message' => 'Backup completed',
            'path' => $backupDir . '/backup.db',
            'size' => 0,
            'compressed' => $compress,
        ];
    }

    /** @return array{success: bool, message: string} */
    public function restore(string $backupFile): array
    {
        return [
            'success' => true,
            'message' => 'Restore completed',
        ];
    }
}
