<?php
declare(strict_types=1);

namespace Fortpesa;

use Fortpesa\Exception\ApiException;
use Fortpesa\Exception\FortpesaException;
use Fortpesa\Exception\NetworkException;
use Fortpesa\Http\CurlHttpClient;
use Fortpesa\Http\HttpClientInterface;
use Fortpesa\Http\HttpResponse;
use Fortpesa\Resource\Payments;
use Fortpesa\Resource\Transactions;
use Fortpesa\Resource\Wallet;

/**
 * Fortpesa API client.
 *
 *     $fortpesa = new \Fortpesa\Client(getenv('FORTPESA_API_KEY'));
 *     $tx = $fortpesa->payments->stk(150, '0712345678', 'INV-2041', 42);
 *
 * Options: base_url, timeout (seconds, default 30), max_retries (default 2),
 *          http_client (HttpClientInterface), sleeper (callable(float $seconds)).
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://fortpesa.co.ke/api/v1';

    /** @var Payments */
    public $payments;
    /** @var Transactions */
    public $transactions;
    /** @var Wallet */
    public $wallet;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;
    /** @var int */
    private $maxRetries;
    /** @var HttpClientInterface */
    private $http;
    /** @var callable|null */
    private $sleeper;

    public function __construct(string $apiKey, array $options = [])
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('A Fortpesa API key is required.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $this->timeout = max(1, (int) ($options['timeout'] ?? 30));
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->http = $options['http_client'] ?? new CurlHttpClient();
        $this->sleeper = $options['sleeper'] ?? null;

        $this->payments = new Payments($this);
        $this->transactions = new Transactions($this);
        $this->wallet = new Wallet($this);
    }

    /** Never print the key, even in var_dump()/print_r(). */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => substr($this->apiKey, 0, 8) . '…'];
    }

    /**
     * Low-level request, returns ['status' => int, 'headers' => array, 'body' => array].
     * Retries (network errors, 429, 500, 502, 504) only when repeating the request cannot
     * cause a side effect: GETs, and POSTs that carry an Idempotency-Key.
     *
     * @throws ApiException|NetworkException|FortpesaException
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $reqHeaders = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'fortpesa-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ], $headers);

        $body = null;
        if ($json !== null) {
            $body = json_encode($json);
            if ($body === false) {
                throw new FortpesaException('Could not encode request body as JSON: ' . json_last_error_msg());
            }
            $reqHeaders['Content-Type'] = 'application/json';
        }

        $safeToRetry = strtoupper($method) === 'GET' || isset($headers['Idempotency-Key']);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->http->request(strtoupper($method), $url, $reqHeaders, $body, $this->timeout);
            } catch (NetworkException $e) {
                if ($safeToRetry && $attempt < $this->maxRetries) {
                    $this->sleep($this->backoff($attempt));
                    $attempt++;
                    continue;
                }
                throw $e;
            }

            if ($safeToRetry && $attempt < $this->maxRetries && in_array($response->status, [429, 500, 502, 504], true)) {
                $this->sleep($this->backoff($attempt, $response));
                $attempt++;
                continue;
            }

            return $this->handle($response);
        }
    }

    public function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }
        usleep((int) round($seconds * 1000000));
    }

    private function backoff(int $attempt, ?HttpResponse $response = null): float
    {
        if ($response !== null) {
            $retryAfter = $response->header('retry-after');
            if ($retryAfter !== null && is_numeric($retryAfter)) {
                return min(10.0, max(0.0, (float) $retryAfter));
            }
        }
        return 0.5 * (2 ** $attempt);
    }

    private function handle(HttpResponse $response): array
    {
        $decoded = null;
        if ($response->body !== '') {
            $decoded = json_decode($response->body, true);
        }
        $decoded = is_array($decoded) ? $decoded : null;

        if ($response->status >= 200 && $response->status < 300) {
            if ($decoded === null) {
                throw new FortpesaException('Unexpected non-JSON response from Fortpesa (HTTP ' . $response->status . ').');
            }
            return ['status' => $response->status, 'headers' => $response->headers, 'body' => $decoded];
        }

        throw ApiException::fromResponse($response->status, $decoded);
    }
}
