<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Internal;

use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Ixbox\LambdaWeb\Exception\CredentialsException;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle middleware that signs the final request with the AWS SDK's SignatureV4.
 *
 * It must be the innermost middleware (directly in front of the transport handler) so that
 * nothing changes the request after it has been signed. For every request it:
 *
 * 1. sets X-Amz-Content-Sha256 to the SHA-256 of the exact body bytes (the empty-string hash
 *    for requests without a body), as required by Lambda Web Function IamAuth endpoints;
 * 2. asks the credential provider for credentials (the provider handles caching / refresh);
 * 3. delegates signing to Aws\Signature\SignatureV4 (which also adds X-Amz-Security-Token
 *    for temporary credentials);
 * 4. forwards the signed request to the transport handler.
 *
 * Transport errors are re-created with the unsigned request so that the signed request
 * (Authorization, X-Amz-Security-Token) does not leak through exception objects.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class SigningMiddleware
{
    public const CONTENT_SHA256_HEADER = 'X-Amz-Content-Sha256';

    /**
     * @param \Closure(): mixed $credentialProvider AWS SDK credential provider
     */
    public function __construct(
        private readonly \Closure $credentialProvider,
        private readonly SignatureV4 $signer,
    ) {
    }

    /**
     * @param callable(RequestInterface, array<mixed>): PromiseInterface $handler
     *
     * @return \Closure(RequestInterface, array<mixed>): PromiseInterface
     */
    public function __invoke(callable $handler): \Closure
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $body = $request->getBody();
            if (!$body->isSeekable()) {
                return Create::rejectionFor(new \LogicException('Cannot sign a request with a non-seekable body.'));
            }

            $unsigned = $request->withHeader(self::CONTENT_SHA256_HEADER, Utils::hash($body, 'sha256'));

            return $this->resolveCredentials()->then(
                function (CredentialsInterface $credentials) use ($unsigned, $options, $handler): PromiseInterface {
                    $signed = $this->signer->signRequest($unsigned, $credentials);

                    return Create::promiseFor($handler($signed, $options))->then(
                        null,
                        static fn (mixed $reason): PromiseInterface => Create::rejectionFor(
                            self::withoutSignedRequest($reason, $unsigned)
                        ),
                    );
                }
            );
        };
    }

    private function resolveCredentials(): PromiseInterface
    {
        try {
            $result = ($this->credentialProvider)();
        } catch (\Throwable $e) {
            return Create::rejectionFor(self::credentialsError($e));
        }

        return Create::promiseFor($result)->then(
            static function (mixed $credentials): CredentialsInterface {
                if (!$credentials instanceof CredentialsInterface) {
                    throw new CredentialsException(
                        'The credential provider did not resolve to an Aws\Credentials\CredentialsInterface instance.'
                    );
                }

                return $credentials;
            },
            static function (mixed $reason): never {
                throw self::credentialsError($reason instanceof \Throwable ? $reason : null);
            },
        );
    }

    private static function credentialsError(?\Throwable $previous): CredentialsException
    {
        return new CredentialsException('Unable to obtain AWS credentials from the credential provider.', 0, $previous);
    }

    private static function withoutSignedRequest(mixed $reason, RequestInterface $unsigned): mixed
    {
        if ($reason instanceof ConnectException) {
            return new ConnectException(
                $reason->getMessage(),
                $unsigned,
                $reason->getPrevious(),
                $reason->getHandlerContext(),
            );
        }
        if ($reason instanceof RequestException) {
            return new RequestException(
                $reason->getMessage(),
                $unsigned,
                $reason->getResponse(),
                $reason->getPrevious(),
                $reason->getHandlerContext(),
            );
        }

        return $reason;
    }
}
