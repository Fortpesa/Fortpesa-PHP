# fortpesa-php

PHP client for the [Fortpesa API](https://fortpesa.co.ke/index): **STK Push**, **transaction status**, **service wallet balance** and **signed webhooks**.

- PHP 7.4+ (tested on 8.3), only `ext-curl` and `ext-json`, no other dependencies
- Whole-KES integers everywhere, like the API
- Safe retries: a POST is only retried when it carries an `Idempotency-Key`, so a flaky network can never double-prompt a customer
- Typed exceptions per error class, with the API `error.code` available for branching
- Webhook verification: HMAC-SHA256 over the raw body, constant-time compare, 5 minute replay window

## Install

```bash
composer require your-vendor/fortpesa-php
```

No Composer (e.g. inside a WordPress/WooCommerce plugin)?

```php
require_once __DIR__ . '/fortpesa-php/autoload.php';
```

## Quick start

```php
use Fortpesa\Client;

$fortpesa = new Client(getenv('FORTPESA_API_KEY')); // pb_live_...  (server-side only!)

$tx = $fortpesa->payments->stk(
    150,                    // whole KES
    '0712345678',           // 07…, 01…, 2547…, +2547… all accepted
    'INV-2041',             // your reference, max 12 chars
    42,                     // Channel ID from the Channels page
    'order-2041-attempt-1'  // idempotency key (optional but recommended)
);

echo $tx->uuid, ' ', $tx->status; // "pending": the customer now sees the M-Pesa prompt
```

`channel_id` is required on every request, and your service wallet needs enough balance to cover the platform fee.

### Waiting for the result

Webhooks are recommended. For scripts, or a checkout page that must wait inline:

```php
$tx = $fortpesa->transactions->waitForCompletion($tx->uuid, 120, 5); // timeout, poll interval (seconds)

if ($tx->isSuccessful()) { /* fulfil */ }
elseif ($tx->isTerminal()) { /* failed | cancelled | expired | reversed, see $tx->failureCode */ }
else { /* still in flight after the timeout */ }
```

### Other calls

```php
$fortpesa->transactions->get($uuid);                       // fresh status (live-confirmed with M-Pesa)
$fortpesa->transactions->list('success', 25);              // newest first; may lag behind get()
$fortpesa->transactions->findByAccountReference('INV-2041');
$fortpesa->wallet->balance()->balance;                     // whole KES
```

## Webhooks

```php
use Fortpesa\Webhook;
use Fortpesa\Exception\SignatureVerificationException;

try {
    $event = Webhook::constructEventFromGlobals(getenv('FORTPESA_WEBHOOK_SECRET')); // whsec_...
} catch (SignatureVerificationException $e) {
    http_response_code(400); exit;
}
http_response_code(200);

// Notification, not proof: re-fetch before fulfilling.
$tx = $fortpesa->transactions->get($event->uuid());
if ($tx->isSuccessful()) { /* mark order paid, idempotently */ }
```

If you can't read `php://input` directly (frameworks, WordPress REST), pass the **raw** body yourself:

```php
$event = Webhook::constructEvent($rawBody, $signatureHeader, $timestampHeader, $secret);
```

Never verify against re-encoded JSON; it changes the bytes and breaks the signature.

## Errors

| Exception | HTTP | Typical `getErrorCode()` |
|---|---|---|
| `InvalidRequestException` | 400, 422 | `INVALID_PHONE`, `INVALID_IDEMPOTENCY_KEY`, provider codes; field errors via `getErrors()` |
| `AuthenticationException` | 401 | `UNAUTHENTICATED`, `INVALID_API_KEY` |
| `InsufficientCreditException` | 402 | `INSUFFICIENT_CREDIT`: top up the service wallet |
| `PermissionException` | 403 | `KYC_REQUIRED`, `MERCHANT_INACTIVE` |
| `NotFoundException` | 404 | `NOT_FOUND` |
| `RateLimitException` | 429 | none (limit is 120 requests/minute) |
| `ServiceUnavailableException` | 503 | `CHANNEL_NOT_APPROVED`, `CIRCUIT_OPEN` |
| `NetworkException` | n/a | no HTTP response was received |

All of them extend `FortpesaException`; the HTTP ones extend `ApiException` (`getHttpStatus()`, `getErrorCode()`, `getErrors()`, `getResponseBody()`). Codes are in `Fortpesa\ErrorCode`. Bad local input (amount < 1, reference > 12 chars, malformed phone…) throws `\InvalidArgumentException` before anything is sent.

## Retries and idempotency

`payments->stk()` always sends an `Idempotency-Key`: yours if given, otherwise a random one. The client retries network errors, `429`, `500`, `502` and `504` (up to `max_retries`, default 2, exponential backoff, `Retry-After` honoured) **only** for GETs and idempotent POSTs. `503 CIRCUIT_OPEN` is not auto-retried; wait about a minute. If the same key is replayed, the API returns the original transaction and `$tx->replayed` is `true`.

Use a deterministic key per payment attempt (`order-{id}-attempt-{n}`) so retries survive process restarts; use a new key for a deliberate fresh payment.

## Options

```php
new Client($apiKey, [
    'base_url'    => 'https://fortpesa.co.ke/api/v1',
    'timeout'     => 30,
    'max_retries' => 2,
    'http_client' => new MyGuzzleAdapter(), // implements Fortpesa\Http\HttpClientInterface
]);
```

## Tests

```bash
php tests/run.php
```
