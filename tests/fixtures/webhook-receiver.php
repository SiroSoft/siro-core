<?php

declare(strict_types=1);

/**
 * Test-only webhook receiver for WebhookDeliveryTest.
 *
 * Run as a `php -S` router script:
 *   WEBHOOK_TEST_SECRET=s3cr3t WEBHOOK_TEST_LOG=/tmp/hook.log \
 *     php -S 127.0.0.1:18099 -t tests/fixtures tests/fixtures/webhook-receiver.php
 *
 * Behaviour:
 *  - reads the raw body, verifies `X-Siro-Signature` with
 *    `WebhookSigner::verify()` using WEBHOOK_TEST_SECRET;
 *  - appends one JSON line per request to WEBHOOK_TEST_LOG
 *    ({event, body, signature, valid});
 *  - valid signature → 200 'ok'; invalid → 401;
 *  - WEBHOOK_TEST_ALWAYS_FAIL=1 → always 500 (retry/dead-letter tests).
 *
 * This file is test infrastructure — never ship it as app code.
 */

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}

$logFile = (string) (getenv('WEBHOOK_TEST_LOG') ?: '');
$secret = (string) (getenv('WEBHOOK_TEST_SECRET') ?: '');
$alwaysFail = getenv('WEBHOOK_TEST_ALWAYS_FAIL') === '1';

$rawBody = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_SIRO_SIGNATURE'] ?? '');
$event = (string) ($_SERVER['HTTP_X_SIRO_EVENT'] ?? '');

$valid = false;
if ($secret !== '' && class_exists(\Siro\Core\Webhook\WebhookSigner::class)) {
    $valid = \Siro\Core\Webhook\WebhookSigner::verify($rawBody, $signature, $secret);
}

if ($logFile !== '') {
    file_put_contents(
        $logFile,
        json_encode(['event' => $event, 'body' => $rawBody, 'signature' => $signature, 'valid' => $valid]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if ($alwaysFail) {
    http_response_code(500);
    echo 'fail';
    return;
}

if (!$valid) {
    http_response_code(401);
    echo 'bad signature';
    return;
}

http_response_code(200);
echo 'ok';
