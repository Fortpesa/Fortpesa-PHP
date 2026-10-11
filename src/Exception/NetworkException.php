<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** The request never produced an HTTP response (DNS, timeout, TLS, connection reset...). */
class NetworkException extends FortpesaException
{
}
