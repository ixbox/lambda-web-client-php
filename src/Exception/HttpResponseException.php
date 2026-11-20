<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Thrown for an HTTP 4xx / 5xx response only when "http_errors" is explicitly enabled.
 *
 * By default (and always for the PSR-18 sendRequest()), 4xx / 5xx responses are returned
 * as normal responses, as required by PSR-18.
 *
 * A 403 response is not necessarily a signature problem: it can also be caused by missing
 * IAM permissions, an expired session, a resource policy, or the application itself.
 * Inspect the response to find out.
 */
final class HttpResponseException extends \RuntimeException implements LambdaWebException
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
    ) {
        parent::__construct($message, $response->getStatusCode());
    }

    /**
     * The request before SigV4 signing (no Authorization / X-Amz-Date / X-Amz-Security-Token headers).
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }
}
