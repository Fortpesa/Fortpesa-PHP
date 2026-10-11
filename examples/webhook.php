<?php
// Point Settings → Webhooks in your Fortpesa dashboard at this file's public HTTPS URL.
require __DIR__ . '/../autoload.php';

use Fortpesa\Client;
use Fortpesa\Exception\SignatureVerificationException;
use Fortpesa\Model\WebhookEvent;
use Fortpesa\Webhook;

$secret = getenv('FORTPESA_WEBHOOK_SECRET'); // whsec_...

try {
    $event = Webhook::constructEventFromGlobals($secret);
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// Acknowledge fast (10s timeout on Fortpesa's side), then do the work.
http_response_code(200);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// The payload is a notification, not proof: re-fetch before acting.
$fortpesa = new Client(getenv('FORTPESA_API_KEY'));
$tx = $fortpesa->transactions->get($event->uuid());

// Deliveries can repeat: make this idempotent on (uuid + event).
if ($event->is(WebhookEvent::SUCCESS) && $tx->isSuccessful()) {
    // mark order $tx->accountReference as paid
} elseif ($tx->isUnsuccessful()) {
    // mark order as failed / cancelled / expired / reversed
}
