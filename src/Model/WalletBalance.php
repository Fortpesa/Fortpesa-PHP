<?php
declare(strict_types=1);

namespace Fortpesa\Model;

/** Prepaid service wallet that platform fees are drawn from. Whole KES. */
final class WalletBalance
{
    /** @var int|null */
    public $merchantId;
    /** @var int */
    public $balance;
    /** @var string */
    public $currency;

    public static function fromArray(array $d): self
    {
        $w = new self();
        $w->merchantId = isset($d['merchant_id']) ? (int) $d['merchant_id'] : null;
        $w->balance = (int) ($d['balance'] ?? 0);
        $w->currency = (string) ($d['currency'] ?? 'KES');
        return $w;
    }
}
