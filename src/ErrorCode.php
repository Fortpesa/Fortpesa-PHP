<?php
declare(strict_types=1);

namespace Fortpesa;

/** Error codes documented by the Fortpesa API. */
final class ErrorCode
{
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const INVALID_API_KEY = 'INVALID_API_KEY';
    public const MERCHANT_INACTIVE = 'MERCHANT_INACTIVE';
    public const KYC_REQUIRED = 'KYC_REQUIRED';
    public const INVALID_PHONE = 'INVALID_PHONE';
    public const INSUFFICIENT_CREDIT = 'INSUFFICIENT_CREDIT';
    public const INVALID_IDEMPOTENCY_KEY = 'INVALID_IDEMPOTENCY_KEY';
    public const NOT_FOUND = 'NOT_FOUND';
    public const CHANNEL_NOT_APPROVED = 'CHANNEL_NOT_APPROVED';
    public const CIRCUIT_OPEN = 'CIRCUIT_OPEN';
}
