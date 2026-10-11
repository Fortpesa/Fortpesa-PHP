<?php
// php examples/stk-push.php
require __DIR__ . '/../autoload.php'; // or: require 'vendor/autoload.php';

use Fortpesa\Client;
use Fortpesa\ErrorCode;
use Fortpesa\Exception\ApiException;
use Fortpesa\Exception\InsufficientCreditException;
use Fortpesa\Exception\NetworkException;

$fortpesa = new Client(getenv('FORTPESA_API_KEY'));

try {
    // 150 KES, order INV-2041, collected on channel 42.
    // A deterministic idempotency key means a retry can never double-prompt the customer.
    $tx = $fortpesa->payments->stk(150, '0712345678', 'INV-2041', 42, 'order-2041-attempt-1');
    echo "Prompt sent. Transaction {$tx->uuid} is {$tx->status}\n";

    // Fine for scripts; in production prefer webhooks (see examples/webhook.php).
    $tx = $fortpesa->transactions->waitForCompletion($tx->uuid, 120, 5);

    if ($tx->isSuccessful()) {
        echo "Paid. Fulfil the order.\n";
    } elseif ($tx->isTerminal()) {
        echo "Not paid: {$tx->status} ({$tx->failureCode})\n";
    } else {
        echo "Still waiting after the timeout. Keep polling or wait for the webhook.\n";
    }
} catch (InsufficientCreditException $e) {
    echo "Service wallet too low. Top up in the dashboard.\n";
} catch (ApiException $e) {
    echo "API error {$e->getHttpStatus()} [{$e->getErrorCode()}]: {$e->getMessage()}\n";
    if ($e->getErrorCode() === ErrorCode::CHANNEL_NOT_APPROVED) {
        echo "Check the Channel ID on the Channels page.\n";
    }
} catch (NetworkException $e) {
    echo "Network problem: {$e->getMessage()}. Re-run with the SAME idempotency key.\n";
}
