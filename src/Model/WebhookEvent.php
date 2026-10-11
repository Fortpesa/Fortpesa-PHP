<?php
declare(strict_types=1);

namespace Fortpesa\Model;

/** A verified webhook delivery. It is a notification, not proof: re-fetch the transaction before fulfilling. */
final class WebhookEvent
{
    public const SUCCESS = 'transaction.success';
    public const FAILED = 'transaction.failed';
    public const CANCELLED = 'transaction.cancelled';
    public const EXPIRED = 'transaction.expired';
    public const REVERSED = 'transaction.reversed';

    /** @var string e.g. transaction.success */
    public $type;
    /** @var string|null ISO 8601 */
    public $createdAt;
    /** @var array the "data" object from the payload */
    public $data;

    public function __construct(string $type, ?string $createdAt, array $data)
    {
        $this->type = $type;
        $this->createdAt = $createdAt;
        $this->data = $data;
    }

    public function uuid(): string
    {
        return (string) ($this->data['uuid'] ?? '');
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    /** The transaction snapshot carried by the payload (no channel_id in webhook payloads). */
    public function transaction(): Transaction
    {
        return Transaction::fromArray($this->data);
    }
}
