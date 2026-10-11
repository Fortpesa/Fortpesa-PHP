<?php
declare(strict_types=1);

namespace Fortpesa\Resource;

use Fortpesa\Client;
use Fortpesa\Model\Transaction;

final class Transactions
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Read one transaction (GET /transactions/{uuid}). Non-terminal transactions are re-confirmed
     * with M-Pesa on the server (at most once every 10s), so this is the call to trust before fulfilling.
     *
     * @throws \Fortpesa\Exception\NotFoundException
     */
    public function get(string $uuid): Transaction
    {
        if (trim($uuid) === '') {
            throw new \InvalidArgumentException('Transaction UUID is required.');
        }
        $res = $this->client->request('GET', 'transactions/' . rawurlencode($uuid));
        return Transaction::fromArray($res['body']['data'] ?? []);
    }

    /**
     * Most recent transactions, newest first (GET /transactions). May lag; use get() for freshness.
     *
     * @param string|null $status  one of Transaction::STATUSES
     * @param int|null    $perPage server default is 50
     * @return Transaction[]
     */
    public function list(?string $status = null, ?int $perPage = null): array
    {
        $query = [];
        if ($status !== null) {
            if (!in_array($status, Transaction::STATUSES, true)) {
                throw new \InvalidArgumentException('Unknown status "' . $status . '". Expected one of: ' . implode(', ', Transaction::STATUSES));
            }
            $query['status'] = $status;
        }
        if ($perPage !== null) {
            if ($perPage < 1) {
                throw new \InvalidArgumentException('per_page must be at least 1.');
            }
            $query['per_page'] = $perPage;
        }

        $res = $this->client->request('GET', 'transactions', $query);
        $out = [];
        foreach (($res['body']['data'] ?? []) as $row) {
            if (is_array($row)) {
                $out[] = Transaction::fromArray($row);
            }
        }
        return $out;
    }

    /**
     * Recent transactions for one of your account references. Use this before re-sending an STK
     * request that timed out without an Idempotency-Key, to avoid a second prompt.
     *
     * @return Transaction[]
     */
    public function findByAccountReference(string $accountReference, int $perPage = 50): array
    {
        return array_values(array_filter(
            $this->list(null, $perPage),
            static function (Transaction $t) use ($accountReference): bool {
                return $t->accountReference === $accountReference;
            }
        ));
    }

    /**
     * Poll until the transaction is terminal or the timeout passes. Webhooks are preferred in
     * production; this is for scripts, CLIs and checkout pages that must wait inline.
     *
     * Returns the last transaction read. Check isTerminal(): if it is false, the timeout elapsed
     * first and the transaction is still in flight (Fortpesa marks it "expired" within ~2 minutes).
     *
     * @param int $timeout  seconds to keep polling (default 120)
     * @param int $interval seconds between polls (default 5, minimum 1)
     */
    public function waitForCompletion(string $uuid, int $timeout = 120, int $interval = 5): Transaction
    {
        $interval = max(1, $interval);
        $maxPolls = (int) ceil(max(0, $timeout) / $interval);

        $tx = $this->get($uuid);
        for ($i = 0; $i < $maxPolls && !$tx->isTerminal(); $i++) {
            $this->client->sleep((float) $interval);
            $tx = $this->get($uuid);
        }
        return $tx;
    }
}
