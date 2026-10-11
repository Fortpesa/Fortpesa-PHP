<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** 402: the prepaid service wallet can't cover the platform fee. Top up in the dashboard. */
class InsufficientCreditException extends ApiException
{
}
