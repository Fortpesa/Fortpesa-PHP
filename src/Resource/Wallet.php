<?php
declare(strict_types=1);

namespace Fortpesa\Resource;

use Fortpesa\Client;
use Fortpesa\Model\WalletBalance;

final class Wallet
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** Prepaid service wallet balance (GET /wallet/balance). Top-ups are done in the dashboard; there is no API for it. */
    public function balance(): WalletBalance
    {
        $res = $this->client->request('GET', 'wallet/balance');
        return WalletBalance::fromArray($res['body']['data'] ?? []);
    }
}
