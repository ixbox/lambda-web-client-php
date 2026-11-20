<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Exception;

/**
 * Thrown when the AWS credential provider fails to return credentials.
 *
 * No request is sent when this exception is thrown. The provider's original error,
 * if any, is available through getPrevious(). The message never contains credential values.
 */
final class CredentialsException extends \RuntimeException implements LambdaWebException
{
}
