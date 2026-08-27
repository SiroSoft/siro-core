<?php
declare(strict_types=1);

namespace Soak;

use Siro\Core\QueueInterface;

/**
 * Simple job for soak test queue workload.
 * Records execution in a JSONL file for external verification.
 */
final class SoakTestJob implements QueueInterface
{
    private const LOG_FILE = __DIR__ . '/../storage/soak_queue_log.jsonl';

    public function handle(array $data = []): void
    {
        $record = [
            'timestamp' => time(),
            'job_id' => $data['id'] ?? 'unknown',
            'dispatched_at' => $data['dispatched_at'] ?? 0,
            'processing_delay' => $data['dispatched_at'] ? time() - (int) $data['dispatched_at'] : 0,
            'memory' => memory_get_usage(true),
        ];

        // Simulate minimal work
        $hash = hash('sha256', json_encode($data));

        file_put_contents(self::LOG_FILE, json_encode($record) . "\n", LOCK_EX | FILE_APPEND);
    }
}
