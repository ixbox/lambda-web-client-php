<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb;

use Aws\Credentials\CredentialProvider;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException as GuzzleTransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\Utils as GuzzleUtils;
use Ixbox\LambdaWeb\Exception\CredentialsException;
use Ixbox\LambdaWeb\Exception\HttpResponseException;
use Ixbox\LambdaWeb\Exception\InvalidArgumentException;
use Ixbox\LambdaWeb\Exception\InvalidRequestException;
use Ixbox\LambdaWeb\Exception\TransportException;
use Ixbox\LambdaWeb\Internal\Endpoint;
use Ixbox\LambdaWeb\Internal\SigningMiddleware;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for AWS Lambda Web Function endpoints with IAM authentication (IamAuth).
 *
 * Every request is signed with SigV4 (service "lambda", the configured region) by the AWS SDK
 * for PHP, after its URI, headers and body are final. Only regional endpoints (HomeRegion /
 * PerRegion) are supported; MultiRegion endpoints require SigV4a and are rejected.
 *
 * Two ways to send a request:
 *
 * - request() / get() / post() / ...: path relative to the endpoint plus an options array.
 * - sendRequest(): PSR-18 ClientInterface. Takes a complete PSR-7 request whose URI must target
 *   the configured endpoint.
 *
 * Following PSR-18, 4xx / 5xx responses are returned as normal responses by default. Exceptions
 * are thrown only when no response could be obtained, or for 4xx / 5xx when the "http_errors"
 * option is explicitly enabled (never for sendRequest()).
 *
 * Request options for request() and its shortcuts (all optional):
 *
 * - query:           array<string, mixed>|string  Query string. Not allowed together with "?" in the path.
 * - headers:         array<string, string|int|float|list<string|int|float>>  Application headers.
 * - json:            mixed   Value encoded as JSON (UTF-8, unescaped slashes / unicode). Sets
 *                            "Content-Type: application/json" unless a Content-Type header is given.
 * - body:            string  Raw request body. Cannot be combined with "json".
 * - timeout:         int|float  Total timeout in seconds (0 = no limit). Overrides the client default.
 * - connect_timeout: int|float  Connection timeout in seconds (0 = no limit). Overrides the client default.
 * - http_errors:     bool    Throw HttpResponseException for 4xx / 5xx (default false). Overrides the
 *                            client default.
 *
 * Any other option (form_params, multipart, auth, allow_redirects, verify, sink, ...) is rejected.
 */
final class Client implements ClientInterface
{
    /** SigV4 signing name documented for Lambda Web Function endpoints. */
    public const SIGNING_SERVICE = 'lambda';

    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private const OPTIONS = ['query', 'headers', 'json', 'body', 'timeout', 'connect_timeout', 'http_errors'];

    /**
     * Headers managed by the signer / transport. Any "x-amz-*" header is rejected as well.
     */
    private const RESERVED_HEADERS = [
        'authorization',
        'host',
        'date',
        'content-length',
        'transfer-encoding',
        'expect',
        'connection',
        'keep-alive',
        'te',
        'trailer',
        'upgrade',
        'proxy-authorization',
        'proxy-authenticate',
    ];

    private const JSON_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
        | \JSON_PRESERVE_ZERO_FRACTION;

    private const REGION_PATTERN = '/^[a-z]{2,}(?:-[a-z0-9]+)*-[0-9]+$/D';

    private readonly Endpoint $endpoint;

    private readonly GuzzleClient $http;

    /**
     * @param string        $endpoint       Endpoint URL, e.g. "https://abc.b7t.lambda-web.ap-northeast-1.on.aws".
     *                                      May include a base path. Must be https, without user info,
     *                                      query or fragment.
     * @param string        $region         Region used for SigV4 signing (the endpoint's region),
     *                                      e.g. "ap-northeast-1".
     * @param callable|null $credentials    AWS SDK credential provider: callable(): PromiseInterface resolving to
     *                                      Aws\Credentials\CredentialsInterface. It is invoked for every request
     *                                      and is responsible for caching / refreshing. Defaults to
     *                                      Aws\Credentials\CredentialProvider::defaultProvider() (memoized).
     * @param float         $timeout        Default total timeout in seconds (0 = no limit).
     * @param float         $connectTimeout Default connection timeout in seconds (0 = no limit).
     * @param bool          $httpErrors     Throw HttpResponseException for 4xx / 5xx responses from request()
     *                                      and its shortcuts. Defaults to false (PSR-18 behaviour). Never
     *                                      applies to sendRequest().
     * @param callable|null $handler        Guzzle transport handler:
     *                                      callable(RequestInterface, array): PromiseInterface.
     *                                      Receives the already-signed request and must send it unchanged.
     *                                      Intended for tests (GuzzleHttp\Handler\MockHandler) or a custom
     *                                      curl handler. Defaults to GuzzleHttp\Utils::chooseHandler().
     */
    public function __construct(
        string $endpoint,
        string $region,
        ?callable $credentials = null,
        private readonly float $timeout = self::DEFAULT_TIMEOUT,
        private readonly float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        private readonly bool $httpErrors = false,
        ?callable $handler = null,
    ) {
        if (preg_match(self::REGION_PATTERN, $region) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid region "%s". Specify the endpoint\'s AWS region explicitly, e.g. "ap-northeast-1".',
                $region,
            ));
        }
        self::assertTimeout($timeout, 'timeout');
        self::assertTimeout($connectTimeout, 'connectTimeout');

        $this->endpoint = Endpoint::fromString($endpoint, $region);

        $provider = $credentials ?? CredentialProvider::defaultProvider();

        // Only transport-level middleware: no redirect, cookie, retry or http_errors middleware.
        // The signer is pushed last so it is the innermost middleware, right in front of the handler.
        $stack = new HandlerStack($handler ?? GuzzleUtils::chooseHandler());
        $stack->push(Middleware::prepareBody(), 'prepare_body');
        $stack->push(
            new SigningMiddleware(\Closure::fromCallable($provider), new SignatureV4(self::SIGNING_SERVICE, $region)),
            'sigv4',
        );

        $this->http = new GuzzleClient(['handler' => $stack]);
    }

    /**
     * Sends a signed request and returns the response.
     *
     * @param string               $method  GET, POST, PUT, PATCH, DELETE, HEAD or OPTIONS (case-insensitive).
     * @param string               $path    "" or a path starting with "/", appended to the endpoint base path.
     * @param array<string, mixed> $options See the class documentation.
     *
     * @throws InvalidArgumentException For an invalid method, path or option (nothing is sent).
     * @throws CredentialsException     When credentials cannot be obtained (nothing is sent).
     * @throws TransportException       When no response was received (connection error, timeout, ...).
     * @throws HttpResponseException    For a 4xx / 5xx response, only when http_errors is enabled.
     */
    public function request(string $method, string $path = '', array $options = []): ResponseInterface
    {
        $method = strtoupper($method);
        self::assertMethod($method);

        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported request option(s): %s. Supported options: %s.',
                implode(', ', array_map(strval(...), $unknown)),
                implode(', ', self::OPTIONS),
            ));
        }

        $request = $this->buildRequest($method, $path, $options);

        return $this->transfer(
            $request,
            $this->timeoutOption($options, 'timeout', $this->timeout),
            $this->timeoutOption($options, 'connect_timeout', $this->connectTimeout),
            $this->option($options, 'http_errors', $this->httpErrors),
        );
    }

    /**
     * PSR-18: signs and sends a complete PSR-7 request.
     *
     * The request URI must target the configured endpoint (same scheme, host and port, and a path
     * at or below the endpoint path). 4xx / 5xx responses are always returned, never thrown.
     * The body must be a seekable stream of known size; the whole stream (from position 0) is
     * hashed and sent. Signing-managed headers (Authorization, X-Amz-*, ...) are rejected; Host
     * and Content-Length are accepted only when they match the endpoint / body size. The client's
     * default timeouts apply.
     *
     * @throws InvalidRequestException When the request cannot be sent safely (nothing is sent).
     * @throws CredentialsException    When credentials cannot be obtained (nothing is sent).
     * @throws TransportException      When no response was received (connection error, timeout, ...).
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $prepared = $this->prepareRequest($request);
        } catch (InvalidArgumentException $e) {
            throw new InvalidRequestException($e->getMessage(), $request, $e);
        }

        return $this->transfer($prepared, $this->timeout, $this->connectTimeout, false);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('GET', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('POST', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function put(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('PUT', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function patch(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function delete(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function head(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('HEAD', $path, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function options(string $path = '', array $options = []): ResponseInterface
    {
        return $this->request('OPTIONS', $path, $options);
    }

    private function transfer(
        RequestInterface $request,
        float $timeout,
        float $connectTimeout,
        bool $httpErrors,
    ): ResponseInterface {
        try {
            $response = $this->http->send($request, [
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'allow_redirects' => false,
                'http_errors' => false,
                'verify' => true,
                'expect' => false,
            ]);
        } catch (GuzzleTransferException $e) {
            throw new TransportException(
                sprintf(
                    'Failed to send %s request to %s.',
                    $request->getMethod(),
                    Endpoint::describe($request->getUri()),
                ),
                $request,
                $e,
            );
        }

        if ($httpErrors && $response->getStatusCode() >= 400) {
            throw new HttpResponseException(
                sprintf(
                    'HTTP %d %s returned for %s %s.',
                    $response->getStatusCode(),
                    $response->getReasonPhrase(),
                    $request->getMethod(),
                    Endpoint::describe($request->getUri()),
                ),
                $request,
                $response,
            );
        }

        return $response;
    }

    /**
     * Validates a PSR-7 request given to sendRequest() and normalizes the method / Host header.
     */
    private function prepareRequest(RequestInterface $request): RequestInterface
    {
        $method = strtoupper($request->getMethod());
        self::assertMethod($method);
        $this->endpoint->assertTargets($request->getUri());

        $body = $request->getBody();
        if (!$body->isSeekable()) {
            throw new InvalidArgumentException(
                'The request body must be a seekable stream (it is hashed for signing before it is sent).'
            );
        }
        $size = $body->getSize();
        if ($size === null) {
            throw new InvalidArgumentException('The request body size must be known.');
        }

        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);
            if ($lower === 'host') {
                if ($values !== [$this->endpoint->authority()]) {
                    throw new InvalidArgumentException(sprintf(
                        'The Host header must match the endpoint ("%s").',
                        $this->endpoint->authority(),
                    ));
                }
                continue;
            }
            if ($lower === 'content-length') {
                if ($values !== [(string) $size]) {
                    throw new InvalidArgumentException('The Content-Length header does not match the body size.');
                }
                continue;
            }
            if (self::isReservedHeader($lower)) {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" header is managed by the client (SigV4 signing / transport) and cannot be set.',
                    $name,
                ));
            }
        }

        if ($method !== $request->getMethod()) {
            $request = $request->withMethod($method);
        }
        if (!$request->hasHeader('Host')) {
            $request = $request->withHeader('Host', $this->endpoint->authority());
        }

        return $request;
    }

    private static function assertMethod(string $method): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP method "%s". Supported methods: %s.',
                $method,
                implode(', ', self::METHODS),
            ));
        }
    }

    private static function isReservedHeader(string $lowerName): bool
    {
        return in_array($lowerName, self::RESERVED_HEADERS, true) || str_starts_with($lowerName, 'x-amz-');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildRequest(string $method, string $path, array $options): RequestInterface
    {
        $uri = $this->endpoint->resolve($path);

        if (array_key_exists('query', $options)) {
            if ($uri->getQuery() !== '') {
                throw new InvalidArgumentException(
                    'Specify the query string either in the path or with the "query" option, not both.'
                );
            }
            try {
                $uri = $uri->withQuery(self::buildQuery($options['query']));
            } catch (\InvalidArgumentException $e) {
                throw new InvalidArgumentException('The "query" option is not valid.', 0, $e);
            }
        }

        $headers = self::buildHeaders($options['headers'] ?? []);

        $hasJson = array_key_exists('json', $options);
        $hasBody = array_key_exists('body', $options);
        if ($hasJson && $hasBody) {
            throw new InvalidArgumentException('The "json" and "body" options cannot be used together.');
        }

        $body = '';
        if ($hasJson) {
            try {
                $body = json_encode($options['json'], self::JSON_FLAGS);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('The "json" option could not be encoded as JSON.', 0, $e);
            }
            if (!self::hasHeader($headers, 'content-type')) {
                $headers['Content-Type'] = ['application/json'];
            }
        } elseif ($hasBody) {
            if (!is_string($options['body'])) {
                throw new InvalidArgumentException(sprintf(
                    'The "body" option must be a string, %s given. Streams, resources and arrays are not supported; '
                    . 'use the "json" option for structured data.',
                    get_debug_type($options['body']),
                ));
            }
            $body = $options['body'];
        }

        try {
            return new Request($method, $uri, $headers, Psr7Utils::streamFor($body), '1.1');
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException('The request headers are not valid.', 0, $e);
        }
    }

    private static function buildQuery(mixed $query): string
    {
        if (is_string($query)) {
            if (str_contains($query, '#')) {
                throw new InvalidArgumentException('The "query" option must not contain a fragment ("#").');
            }

            return $query;
        }
        if (!is_array($query)) {
            throw new InvalidArgumentException(sprintf(
                'The "query" option must be an array or a string, %s given.',
                get_debug_type($query),
            ));
        }
        self::assertQueryValues($query);

        return http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param array<mixed> $values
     */
    private static function assertQueryValues(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                self::assertQueryValues($value);
            } elseif ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Query values must be scalars, null or arrays, %s given.',
                    get_debug_type($value),
                ));
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function buildHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            throw new InvalidArgumentException(sprintf(
                'The "headers" option must be an array, %s given.',
                get_debug_type($headers),
            ));
        }

        $result = [];
        $seen = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Header names must be non-empty strings.');
            }
            $lower = strtolower($name);
            if (self::isReservedHeader($lower)) {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" header is managed by the client (SigV4 signing / transport) and cannot be set.',
                    $name,
                ));
            }
            if (isset($seen[$lower])) {
                throw new InvalidArgumentException(sprintf('The "%s" header is specified more than once.', $name));
            }
            $seen[$lower] = true;

            $values = is_array($value) ? $value : [$value];
            if ($values === [] || !array_is_list($values)) {
                throw new InvalidArgumentException(sprintf(
                    'The "%s" header value must be a string, a number or a non-empty list of them.',
                    $name,
                ));
            }
            $normalized = [];
            foreach ($values as $v) {
                if (!is_string($v) && !is_int($v) && !is_float($v)) {
                    throw new InvalidArgumentException(sprintf(
                        'The "%s" header value must be a string, a number or a non-empty list of them.',
                        $name,
                    ));
                }
                $normalized[] = (string) $v;
            }
            $result[$name] = $normalized;
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function hasHeader(array $headers, string $lowerName): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower($name) === $lowerName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function option(array $options, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }
        if (!is_bool($options[$name])) {
            throw new InvalidArgumentException(sprintf('The "%s" option must be a boolean.', $name));
        }

        return $options[$name];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function timeoutOption(array $options, string $name, float $default): float
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }
        $value = $options[$name];
        if (!is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException(sprintf('The "%s" option must be a number of seconds.', $name));
        }
        self::assertTimeout((float) $value, $name);

        return (float) $value;
    }

    private static function assertTimeout(float $value, string $name): void
    {
        if (!is_finite($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" value must be a finite number of seconds >= 0 (0 means no limit).',
                $name,
            ));
        }
    }
}
