<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Fortpesa\Client;
use Fortpesa\ErrorCode;
use Fortpesa\Exception\{ApiException, AuthenticationException, InsufficientCreditException, InvalidRequestException,
    NetworkException, NotFoundException, RateLimitException, ServiceUnavailableException, SignatureVerificationException};
use Fortpesa\Http\{HttpClientInterface, HttpResponse};
use Fortpesa\Model\{Transaction, WebhookEvent};
use Fortpesa\{Phone, Webhook};

/** Queue-based fake transport that records every request. */
final class FakeHttp implements HttpClientInterface
{
    public $queue = [];
    public $requests = [];

    public function push($item): self { $this->queue[] = $item; return $this; }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}

function json(int $status, array $body, array $headers = []): HttpResponse
{
    return new HttpResponse($status, $headers, json_encode($body));
}

function txData(array $over = []): array
{
    return array_merge([
        'uuid' => '9f1c4a5e-2f77-4a0e-9c3b-1d5f6a7b8c9d', 'amount' => 150, 'currency' => 'KES', 'platform_fee' => 6,
        'phone' => '0712345678', 'account_reference' => 'INV-2041', 'status' => 'pending', 'failure_code' => null,
        'channel_id' => 42, 'provider_transaction_id' => 'ptx_8a3f9c21',
        'created_at' => '2026-09-25T14:03:22+00:00', 'settled_at' => null,
    ], $over);
}

function make(FakeHttp $http, array $opts = []): Client
{
    $GLOBALS['slept'] = [];
    return new Client('pb_live_TESTKEY', $opts + [
        'http_client' => $http,
        'sleeper' => function (float $s) { $GLOBALS['slept'][] = $s; },
    ]);
}

$passed = 0; $failed = 0;
function t(string $name, callable $fn): void
{
    global $passed, $failed;
    try { $fn(); $passed++; echo "  ok   $name\n"; }
    catch (Throwable $e) { $failed++; echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
function eq($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($what ? "$what: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function throws(string $class, callable $fn): Throwable
{
    try { $fn(); } catch (Throwable $e) {
        if (!($e instanceof $class)) { throw new RuntimeException("expected $class, got " . get_class($e) . ': ' . $e->getMessage()); }
        return $e;
    }
    throw new RuntimeException("expected $class, nothing thrown");
}

echo "Phone\n";
t('normalises every documented format', function () {
    foreach (['0712345678', '254712345678', '+254712345678', '0712 345 678', '+254-712-345-678'] as $p) {
        eq('254712345678', Phone::normalize($p), $p);
    }
    eq('254112345678', Phone::normalize('0112345678'));
    eq('254112345678', Phone::normalize('+254112345678'));
});
t('rejects bad numbers', function () {
    foreach (['0612345678', '071234567', '12345', 'abc', '+255712345678', ''] as $p) {
        eq(false, Phone::isValid($p), $p);
    }
});

echo "STK push\n";
t('sends correct request and parses the transaction', function () {
    $http = (new FakeHttp)->push(json(201, ['data' => txData()], ['idempotency-replayed' => 'true']));
    $tx = make($http)->payments->stk(150, '0712345678', 'INV-2041', 42, 'order-2041-attempt-1');
    $r = $http->requests[0];
    eq('POST', $r['method']);
    eq('https://fortpesa.co.ke/api/v1/payments/stk', $r['url']);
    eq('Bearer pb_live_TESTKEY', $r['headers']['Authorization']);
    eq('order-2041-attempt-1', $r['headers']['Idempotency-Key']);
    eq('application/json', $r['headers']['Content-Type']);
    eq(['amount' => 150, 'phone' => '254712345678', 'account_reference' => 'INV-2041', 'channel_id' => 42], json_decode($r['body'], true));
    eq('9f1c4a5e-2f77-4a0e-9c3b-1d5f6a7b8c9d', $tx->uuid);
    eq(6, $tx->platformFee);
    eq(42, $tx->channelId);
    eq(true, $tx->replayed, 'replayed');
    eq(false, $tx->isTerminal());
    eq('2026', $tx->getCreatedAt()->format('Y'));
});
t('always sends an auto-generated valid idempotency key', function () {
    $http = (new FakeHttp)->push(json(201, ['data' => txData()]));
    make($http)->payments->stk(10, '0712345678', 'A1', 1);
    $key = $http->requests[0]['headers']['Idempotency-Key'];
    eq(1, preg_match('/^[A-Za-z0-9._:\-]{8,128}$/', $key), 'key format');
});
t('validates input locally and sends nothing', function () {
    $http = new FakeHttp; $c = make($http);
    throws(InvalidArgumentException::class, function () use ($c) { $c->payments->stk(0, '0712345678', 'A', 1); });
    throws(InvalidArgumentException::class, function () use ($c) { $c->payments->stk(10, '0712345678', 'THIRTEEN-CHRS', 1); });
    throws(InvalidArgumentException::class, function () use ($c) { $c->payments->stk(10, '0712345678', '', 1); });
    throws(InvalidArgumentException::class, function () use ($c) { $c->payments->stk(10, '12345', 'A', 1); });
    throws(InvalidArgumentException::class, function () use ($c) { $c->payments->stk(10, '0712345678', 'A', 0); });
    throws(InvalidArgumentException::class, function () use ($c) { $c->payments->stk(10, '0712345678', 'A', 1, 'short'); });
    eq(0, count($http->requests));
});
t('retries a network failure with the same idempotency key (no double prompt)', function () {
    $http = (new FakeHttp)->push(new NetworkException('timeout'))->push(json(201, ['data' => txData()]));
    $tx = make($http)->payments->stk(150, '0712345678', 'INV-2041', 42);
    eq(2, count($http->requests));
    eq($http->requests[0]['headers']['Idempotency-Key'], $http->requests[1]['headers']['Idempotency-Key']);
    eq([0.5], $GLOBALS['slept']);
    eq('pending', $tx->status);
});
t('does not retry 503 CIRCUIT_OPEN or other client errors', function () {
    $http = (new FakeHttp)->push(json(503, ['error' => ['code' => 'CIRCUIT_OPEN', 'message' => 'Provider failing']]));
    $e = throws(ServiceUnavailableException::class, function () use ($http) { make($http)->payments->stk(10, '0712345678', 'A', 1); });
    eq('CIRCUIT_OPEN', $e->getErrorCode());
    eq(1, count($http->requests));
});
t('POST without an idempotency key is never retried (low-level)', function () {
    $http = (new FakeHttp)->push(new NetworkException('timeout'))->push(json(201, ['data' => txData()]));
    throws(NetworkException::class, function () use ($http) { make($http)->request('POST', 'payments/stk', [], ['amount' => 1]); });
    eq(1, count($http->requests));
});
t('gives up after max_retries and surfaces the error', function () {
    $http = (new FakeHttp)->push(new NetworkException('a'))->push(new NetworkException('b'))->push(new NetworkException('c'));
    throws(NetworkException::class, function () use ($http) { make($http)->wallet->balance(); });
    eq(3, count($http->requests));
    eq([0.5, 1.0], $GLOBALS['slept']);
});

echo "Errors\n";
t('maps the documented error codes to exceptions', function () {
    $cases = [
        [400, ['error' => ['code' => 'INVALID_PHONE', 'message' => 'bad']], InvalidRequestException::class, 'INVALID_PHONE'],
        [401, ['error' => ['code' => 'INVALID_API_KEY', 'message' => 'bad key']], AuthenticationException::class, 'INVALID_API_KEY'],
        [402, ['error' => ['code' => 'INSUFFICIENT_CREDIT', 'message' => 'low']], InsufficientCreditException::class, 'INSUFFICIENT_CREDIT'],
        [403, ['error' => ['code' => 'KYC_REQUIRED', 'message' => 'kyc']], Fortpesa\Exception\PermissionException::class, 'KYC_REQUIRED'],
        [404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'nope']], NotFoundException::class, 'NOT_FOUND'],
        [503, ['error' => ['code' => 'CHANNEL_NOT_APPROVED', 'message' => 'chan']], ServiceUnavailableException::class, 'CHANNEL_NOT_APPROVED'],
    ];
    foreach ($cases as [$status, $body, $class, $code]) {
        $http = (new FakeHttp)->push(json($status, $body));
        $e = throws($class, function () use ($http) { make($http)->transactions->get('abc'); });
        eq($code, $e->getErrorCode()); eq($status, $e->getHttpStatus());
        eq(true, $e instanceof ApiException);
    }
});
t('exposes 422 field errors', function () {
    $http = (new FakeHttp)->push(json(422, ['message' => 'The given data was invalid.', 'errors' => ['amount' => ['The amount must be at least 1.']]]));
    $e = throws(InvalidRequestException::class, function () use ($http) { make($http)->transactions->get('abc'); });
    eq(['amount' => ['The amount must be at least 1.']], $e->getErrors());
    eq('The given data was invalid.', $e->getMessage());
});
t('429 on a GET is retried honouring Retry-After, then surfaces as RateLimitException', function () {
    $r = json(429, ['message' => 'Too Many Attempts.'], ['retry-after' => '2']);
    $http = (new FakeHttp)->push($r)->push($r)->push($r);
    throws(RateLimitException::class, function () use ($http) { make($http)->wallet->balance(); });
    eq(3, count($http->requests));
    eq([2.0, 2.0], $GLOBALS['slept']);
});
t('non-JSON error body still yields an ApiException', function () {
    $http = (new FakeHttp)->push(new HttpResponse(418, [], '<html>teapot</html>'));
    $e = throws(ApiException::class, function () use ($http) { make($http)->transactions->get('abc'); });
    eq(418, $e->getHttpStatus());
});
t('the API key is hidden from var_dump/print_r', function () {
    eq(false, strpos(print_r(make(new FakeHttp), true), 'TESTKEY'));
});

echo "Transactions & wallet\n";
t('get() requests the right URL', function () {
    $http = (new FakeHttp)->push(json(200, ['data' => txData(['status' => 'success', 'settled_at' => '2026-09-25T14:03:51+00:00'])]));
    $tx = make($http)->transactions->get('9f1c4a5e-2f77-4a0e-9c3b-1d5f6a7b8c9d');
    eq('GET', $http->requests[0]['method']);
    eq('https://fortpesa.co.ke/api/v1/transactions/9f1c4a5e-2f77-4a0e-9c3b-1d5f6a7b8c9d', $http->requests[0]['url']);
    eq(true, $tx->isSuccessful()); eq(true, $tx->isTerminal()); eq(false, $tx->isUnsuccessful());
});
t('list() builds the query and returns Transaction objects', function () {
    $http = (new FakeHttp)->push(json(200, ['data' => [txData(), txData(['uuid' => 'x2'])]]));
    $list = make($http)->transactions->list('success', 25);
    eq('https://fortpesa.co.ke/api/v1/transactions?status=success&per_page=25', $http->requests[0]['url']);
    eq(2, count($list)); eq('x2', $list[1]->uuid);
    throws(InvalidArgumentException::class, function () { make(new FakeHttp)->transactions->list('bogus'); });
});
t('findByAccountReference filters locally', function () {
    $http = (new FakeHttp)->push(json(200, ['data' => [txData(), txData(['uuid' => 'x2', 'account_reference' => 'OTHER'])]]));
    $found = make($http)->transactions->findByAccountReference('INV-2041');
    eq(1, count($found)); eq('INV-2041', $found[0]->accountReference);
});
t('waitForCompletion polls until terminal', function () {
    $http = (new FakeHttp)
        ->push(json(200, ['data' => txData(['status' => 'pending'])]))
        ->push(json(200, ['data' => txData(['status' => 'processing'])]))
        ->push(json(200, ['data' => txData(['status' => 'success'])]));
    $tx = make($http)->transactions->waitForCompletion('abc', 60, 5);
    eq('success', $tx->status); eq(3, count($http->requests)); eq([5.0, 5.0], $GLOBALS['slept']);
});
t('waitForCompletion returns the in-flight transaction when the timeout elapses', function () {
    $p = json(200, ['data' => txData()]);
    $http = (new FakeHttp)->push($p)->push($p)->push($p);
    $tx = make($http)->transactions->waitForCompletion('abc', 10, 5);
    eq(false, $tx->isTerminal()); eq(3, count($http->requests));
});
t('every non-success terminal status counts as unsuccessful', function () {
    foreach (['failed', 'cancelled', 'expired', 'reversed'] as $s) {
        $tx = Transaction::fromArray(txData(['status' => $s]));
        eq(true, $tx->isUnsuccessful(), $s);
    }
    foreach (['initiating', 'pending', 'processing'] as $s) {
        eq(false, Transaction::fromArray(txData(['status' => $s]))->isTerminal(), $s);
    }
});
t('wallet balance', function () {
    $http = (new FakeHttp)->push(json(200, ['data' => ['merchant_id' => 12, 'balance' => 482, 'currency' => 'KES']]));
    $w = make($http)->wallet->balance();
    eq('https://fortpesa.co.ke/api/v1/wallet/balance', $http->requests[0]['url']);
    eq(482, $w->balance); eq(12, $w->merchantId); eq('KES', $w->currency);
});

echo "Webhooks\n";
$secret = 'whsec_test_secret';
$body = json_encode(['event' => 'transaction.success', 'created_at' => '2026-09-25T14:03:51+00:00',
    'data' => ['id' => 341, 'uuid' => '9f1c4a5e', 'merchant_id' => 12, 'amount' => 150, 'currency' => 'KES', 'platform_fee' => 6,
        'phone' => '0712345678', 'account_reference' => 'INV-2041', 'status' => 'success', 'failure_code' => null,
        'provider_transaction_id' => 'ptx_8a3f9c21', 'settled_at' => '2026-09-25T14:03:51+00:00']]);
$ts = (string) time();
$sig = Webhook::computeSignature($ts, $body, $secret);

t('signature format matches the documented scheme', function () use ($ts, $body, $secret, $sig) {
    eq('sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret), $sig);
});
t('accepts a valid delivery', function () use ($ts, $body, $secret, $sig) {
    $ev = Webhook::constructEvent($body, $sig, $ts, $secret);
    eq(true, $ev->is(WebhookEvent::SUCCESS));
    eq('9f1c4a5e', $ev->uuid());
    eq(true, $ev->transaction()->isSuccessful());
    eq(null, $ev->transaction()->channelId);
});
t('rejects a tampered body', function () use ($ts, $body, $secret, $sig) {
    throws(SignatureVerificationException::class, function () use ($ts, $body, $secret, $sig) {
        Webhook::constructEvent(str_replace('150', '1500', $body), $sig, $ts, $secret);
    });
});
t('rejects a wrong secret', function () use ($ts, $body, $sig) {
    throws(SignatureVerificationException::class, function () use ($ts, $body, $sig) { Webhook::constructEvent($body, $sig, $ts, 'whsec_other'); });
});
t('rejects missing headers', function () use ($ts, $body, $secret, $sig) {
    throws(SignatureVerificationException::class, function () use ($ts, $body, $secret) { Webhook::constructEvent($body, null, $ts, $secret); });
    throws(SignatureVerificationException::class, function () use ($body, $secret, $sig) { Webhook::constructEvent($body, $sig, null, $secret); });
    throws(SignatureVerificationException::class, function () use ($body, $secret, $sig) { Webhook::constructEvent($body, $sig, 'abc', $secret); });
});
t('rejects replays older than 5 minutes but accepts within tolerance', function () use ($body, $secret) {
    $old = (string) (time() - 301);
    throws(SignatureVerificationException::class, function () use ($body, $secret, $old) {
        Webhook::constructEvent($body, Webhook::computeSignature($old, $body, $secret), $old, $secret);
    });
    $ok = (string) (time() - 299);
    Webhook::constructEvent($body, Webhook::computeSignature($ok, $body, $secret), $ok, $secret);
});

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
