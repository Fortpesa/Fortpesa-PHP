<?php
declare(strict_types=1);

namespace Fortpesa\Exception;

/** 404: transaction UUID unknown or owned by another merchant. */
class NotFoundException extends ApiException
{
}
