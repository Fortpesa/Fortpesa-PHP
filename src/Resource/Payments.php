<?php
declare(strict_types=1);

namespace Fortpesa\Resource;

use Fortpesa\Client;
use Fortpesa\Model\Transaction;
use Fortpesa\Phone;

final class Payments
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Prompt a customer's phone for an M-Pesa payment (POST /payments/stk).
     *
     * An Idempotency-Key is ALWAYS sent. Pass your own deterministic key (e.g. "order-2041-attempt-1")
     * to make retries safe across requests and processes; if you omit it, a random one is generated,
     * which still lets the library retry network failures without double-prompting the customer.
     *
     * @param int         $amount           Whole KES, >= 1
     * @param string      $phone            07XXXXXXXX, 01XXXXXXXX, 2547XXXXXXXX or +2547XXXXXXXX
     * @param string      $accountReference Your order/invoice reference, max 12 characters
     * @param int         $channelId        The "Channel ID" of one of your approved channels
     * @param string|null $idempotencyKey   8-128 chars from A-Z a-z 0-9 . _ : -
     *
     * @throws \InvalidArgumentException on invalid local input (nothing is sent)
     * @throws \Fortpesa\Exception\ApiException
     */
    public function stk(int $amount, string $phone, string $accountReference, int $channelId, ?string $idempotencyKey = null): Transaction
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('Amount must be a whole number of KES, at least 1.');
        }
        $length = function_exists('mb_strlen') ? mb_strlen($accountReference) : strlen($accountReference);
        if ($accountReference === '' || $length > 12) {
            throw new \InvalidArgumentException('account_reference is required and must be at most 12 characters.');
        }
        if ($channelId < 1) {
            throw new \InvalidArgumentException('channel_id must be a positive integer (see the Channels page of your dashboard).');
        }

        $key = $idempotencyKey ?? self::generateIdempotencyKey();
        if (!preg_match('/^[A-Za-z0-9._:\-]{8,128}$/', $key)) {
            throw new \InvalidArgumentException('Idempotency key must be 8-128 characters from A-Z a-z 0-9 . _ : -');
        }

        $res = $this->client->request('POST', 'payments/stk', [], [
            'amount' => $amount,
            'phone' => Phone::normalize($phone),
            'account_reference' => $accountReference,
            'channel_id' => $channelId,
        ], ['Idempotency-Key' => $key]);

        $tx = Transaction::fromArray($res['body']['data'] ?? []);
        $tx->replayed = strtolower((string) ($res['headers']['idempotency-replayed'] ?? '')) === 'true';
        return $tx;
    }

    public static function generateIdempotencyKey(): string
    {
        return 'fp-' . bin2hex(random_bytes(16));
    }
}
