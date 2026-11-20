<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Exception;

/**
 * Thrown for an invalid client configuration or an invalid / unsupported request option.
 *
 * No request is sent when this exception is thrown.
 */
class InvalidArgumentException extends \InvalidArgumentException implements LambdaWebException
{
}
