<?php
declare(strict_types=1);

namespace Fortpesa\Model;

/** A Fortpesa transaction. Amounts are whole KES integers. */
final class Transaction
{
    public const STATUS_INITIATING = 'initiating';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_INITIATING, self::STATUS_PENDING, self::STATUS_PROCESSING,
        self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELLED,
        self::STATUS_EXPIRED, self::STATUS_REVERSED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_CANCELLED,
        self::STATUS_EXPIRED, self::STATUS_REVERSED,
    ];

    /** @var string */
    public $uuid;
    /** @var int */
    public $amount;
    /** @var string */
    public $currency;
    /** @var int */
    public $platformFee;
    /** @var string|null */
    public $phone;
    /** @var string|null */
    public $accountReference;
    /** @var string */
    public $status;
    /** @var string|null e.g. POLL_TIMEOUT for expired transactions */
    public $failureCode;
    /** @var int|null (not present in webhook payloads) */
    public $channelId;
    /** @var string|null */
    public $providerTransactionId;
    /** @var string|null ISO 8601 */
    public $createdAt;
    /** @var string|null ISO 8601 */
    public $settledAt;
    /** @var bool true when STK creation returned an earlier transaction for the same Idempotency-Key */
    public $replayed = false;
    /** @var array the untouched API object, for fields added later */
    public $raw = [];

    public static function fromArray(array $d): self
    {
        $t = new self();
        $t->uuid = (string) ($d['uuid'] ?? '');
        $t->amount = (int) ($d['amount'] ?? 0);
        $t->currency = (string) ($d['currency'] ?? 'KES');
        $t->platformFee = (int) ($d['platform_fee'] ?? 0);
        $t->phone = isset($d['phone']) ? (string) $d['phone'] : null;
        $t->accountReference = isset($d['account_reference']) ? (string) $d['account_reference'] : null;
        $t->status = (string) ($d['status'] ?? '');
        $t->failureCode = isset($d['failure_code']) ? (string) $d['failure_code'] : null;
        $t->channelId = isset($d['channel_id']) ? (int) $d['channel_id'] : null;
        $t->providerTransactionId = isset($d['provider_transaction_id']) ? (string) $d['provider_transaction_id'] : null;
        $t->createdAt = isset($d['created_at']) ? (string) $d['created_at'] : null;
        $t->settledAt = isset($d['settled_at']) ? (string) $d['settled_at'] : null;
        $t->raw = $d;
        return $t;
    }

    /** Success, failed, cancelled, expired or reversed: the status will not change again. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** Payment collected. Only fulfil an order when this is true on a fresh API read. */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /** Terminal but not collected (failed, cancelled, expired) or collected then reversed. */
    public function isUnsuccessful(): bool
    {
        return $this->isTerminal() && !$this->isSuccessful();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt !== null ? new \DateTimeImmutable($this->createdAt) : null;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt !== null ? new \DateTimeImmutable($this->settledAt) : null;
    }
}
