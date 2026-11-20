<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Marker interface implemented by every exception thrown by this library.
 *
 * It extends the PSR-18 ClientExceptionInterface, so PSR-18 consumers can catch it generically.
 */
interface LambdaWebException extends ClientExceptionInterface
{
}
