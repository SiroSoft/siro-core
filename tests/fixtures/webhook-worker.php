<?php

declare(strict_types=1);

/**
 * Test-only queue worker for WebhookDeliveryTest.
 *
 * Runs the queue in a SEPARATE process (like production `queue:work`):
 * the worker disables Http SSRF protection so it can deliver to the local
 * test receiver — the main test process never touches that global flag.
 *
 * Usage:
 *   php webhook-worker.php <sqlite-db-file> [max-work-calls]
 *
 * Prints one JSON line: {processed, pending, failed}.
 * The queue DB file must already exist with `jobs` + `failed_jobs` tables
 * (the test creates them); the worker only reads/writes rows.
 *
 * This file is test infrastructure — never ship it as app code.
 */

$dbFile = $argv[1] ?? '';
$maxCalls = (int) ($argv[2] ?? 10);

require __DIR__ . '/../../vendor/autoload.php';

use Siro\Core\Database;
use Siro\Core\Http;
use Siro\Core\Queue;
use Siro\Core\Webhook\WebhookDeliveryJob;

Database::configure(['driver' => 'sqlite', 'database' => $dbFile]);
Http::disableSsrfProtection();
Queue::registerJob(WebhookDeliveryJob::class);

$processed = 0;
for ($i = 0; $i < $maxCalls; $i++) {
    if (!Queue::work()) {
        break;
    }
    $processed++;
}

echo json_encode([
    'processed' => $processed,
    'pending' => Queue::pendingCount(),
    'failed' => Queue::failedCount(),
]) . "\n";
