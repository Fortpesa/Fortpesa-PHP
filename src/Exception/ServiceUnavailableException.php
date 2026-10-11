<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** 503: CHANNEL_NOT_APPROVED (fix your channel) or CIRCUIT_OPEN (provider failing, retry after a minute). */
class ServiceUnavailableException extends ApiException
{
}
