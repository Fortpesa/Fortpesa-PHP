<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** 429: over the 120 requests/minute limit. Back off. */
class RateLimitException extends ApiException
{
}
