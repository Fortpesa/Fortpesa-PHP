<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** A webhook signature or timestamp check failed. Respond 400 and ignore the payload. */
class SignatureVerificationException extends FortpesaException
{
}
