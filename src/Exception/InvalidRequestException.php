<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** 400 / 422: bad phone, provider refusal, or field validation errors (see getErrors()). Fix the input; don't retry as-is. */
class InvalidRequestException extends ApiException
{
}
