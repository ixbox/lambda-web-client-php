<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown when no HTTP response was received (DNS failure, connection error, TLS error, timeout, ...).
 * Implements the PSR-18 NetworkExceptionInterface.
 *
 * The underlying Guzzle exception is available through getPrevious(). Neither this exception
 * nor the previous one holds the signed request: getRequest() returns the request as it was
 * before signing, so it contains no Authorization or X-Amz-Security-Token header.
 */
final class TransportException extends \RuntimeException implements LambdaWebException, NetworkExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The request before SigV4 signing (no Authorization / X-Amz-Date / X-Amz-Security-Token headers).
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
