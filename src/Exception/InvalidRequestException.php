<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown by Client::sendRequest() (PSR-18) when the given request cannot be sent safely:
 * another destination than the configured endpoint, an unsupported method, a signing-managed
 * header, a non-seekable body, and so on.
 *
 * No request is sent when this exception is thrown.
 */
final class InvalidRequestException extends InvalidArgumentException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The request as given to sendRequest() (it has not been signed).
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
