<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Internal;

use GuzzleHttp\Psr7\Uri;
use Ixbox\LambdaWeb\Exception\InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Validated endpoint (scheme + authority + optional base path) and path joining rules.
 *
 * The scheme and authority of a request URI always come from the endpoint. A request path is
 * only ever applied with UriInterface::withPath(), so it cannot change the destination host.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class Endpoint
{
    private const REGIONAL_HOST_PATTERN = '/\.lambda-web\.([a-z0-9-]+)\.on\.aws$/D';

    private function __construct(
        private readonly UriInterface $baseUri,
        private readonly string $basePath,
    ) {
    }

    public static function fromString(string $endpoint, string $region): self
    {
        if ($endpoint === '' || $endpoint !== trim($endpoint)) {
            throw new InvalidArgumentException('The endpoint must be a non-empty URL without surrounding whitespace.');
        }
        if (str_contains($endpoint, '?') || str_contains($endpoint, '#')) {
            throw new InvalidArgumentException(
                'The endpoint must not contain a query string or a fragment; use the "query" request option instead.'
            );
        }

        try {
            $uri = new Uri($endpoint);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException('The endpoint is not a valid URL.', 0, $e);
        }

        if ($uri->getScheme() !== 'https') {
            throw new InvalidArgumentException('The endpoint must be an absolute "https://" URL.');
        }
        if ($uri->getHost() === '') {
            throw new InvalidArgumentException('The endpoint must contain a host name.');
        }
        if ($uri->getUserInfo() !== '') {
            throw new InvalidArgumentException('The endpoint must not contain user information (user:password@).');
        }

        if (preg_match(self::REGIONAL_HOST_PATTERN, $uri->getHost(), $m) === 1) {
            if ($m[1] === 'global') {
                throw new InvalidArgumentException(
                    'MultiRegion endpoints (*.lambda-web.global.on.aws) require SigV4a, which is not supported. '
                    . 'Use a HomeRegion or PerRegion (regional) endpoint.'
                );
            }
            if ($m[1] !== $region) {
                throw new InvalidArgumentException(sprintf(
                    'The endpoint host belongs to region "%s" but the client is configured for region "%s".',
                    $m[1],
                    $region,
                ));
            }
        }

        $path = $uri->getPath();
        if ($path !== '') {
            self::assertSafePath($path, 'endpoint path');
        }

        return new self($uri, rtrim($path, '/'));
    }

    /**
     * Joins the endpoint base path and the request path.
     *
     * - ""            -> the endpoint path as configured (or "/" when it has none)
     * - "/jobs"       -> "{base path}/jobs"
     * - "/jobs?x=1"   -> "{base path}/jobs" with query "x=1"
     *
     * The request path must be empty or start with a single "/". Absolute URLs, network-path
     * references ("//host"), fragments, dot segments and empty segments are rejected.
     */
    public function resolve(string $path): UriInterface
    {
        $query = '';

        if ($path === '') {
            $joined = $this->baseUri->getPath() === '' ? '/' : $this->baseUri->getPath();
        } else {
            if ($path[0] !== '/' || str_starts_with($path, '//')) {
                throw new InvalidArgumentException(
                    'The request path must be empty or start with a single "/" '
                    . '(absolute URLs and "//host" are not allowed).'
                );
            }
            if (str_contains($path, '#')) {
                throw new InvalidArgumentException('The request path must not contain a fragment ("#").');
            }

            $queryPos = strpos($path, '?');
            if ($queryPos !== false) {
                $query = substr($path, $queryPos + 1);
                $path = substr($path, 0, $queryPos);
            }

            self::assertSafePath($path, 'request path');
            $joined = $this->basePath . $path;
        }

        try {
            return $this->baseUri->withPath($joined)->withQuery($query);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException('The request path or query is not valid.', 0, $e);
        }
    }

    /**
     * Checks that a complete request URI (PSR-18 sendRequest()) targets this endpoint:
     * same scheme, host and port, no user info or fragment, and a path that is the endpoint
     * path or below it, without dot segments or empty segments.
     */
    public function assertTargets(UriInterface $uri): void
    {
        if (
            $uri->getScheme() !== $this->baseUri->getScheme()
            || $uri->getHost() !== $this->baseUri->getHost()
            || $uri->getPort() !== $this->baseUri->getPort()
        ) {
            throw new InvalidArgumentException(sprintf(
                'The request URI must target the configured endpoint (%s://%s).',
                $this->baseUri->getScheme(),
                $this->authority(),
            ));
        }
        if ($uri->getUserInfo() !== '') {
            throw new InvalidArgumentException('The request URI must not contain user information (user:password@).');
        }
        if ($uri->getFragment() !== '') {
            throw new InvalidArgumentException('The request URI must not contain a fragment ("#").');
        }

        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        self::assertSafePath($path, 'request path');

        if ($this->basePath !== '' && $path !== $this->basePath && !str_starts_with($path, $this->basePath . '/')) {
            throw new InvalidArgumentException(sprintf(
                'The request path must be the endpoint path "%s" or below it.',
                $this->basePath,
            ));
        }
    }

    /**
     * The value of the Host header for this endpoint ("host" or "host:port" for a non-default port).
     */
    public function authority(): string
    {
        $port = $this->baseUri->getPort();

        return $this->baseUri->getHost() . ($port === null ? '' : ':' . $port);
    }

    /**
     * Human-readable target without query string, for exception messages.
     */
    public static function describe(UriInterface $uri): string
    {
        return (string) $uri->withQuery('')->withFragment('')->withUserInfo('');
    }

    private static function assertSafePath(string $path, string $label): void
    {
        if ($path[0] !== '/') {
            throw new InvalidArgumentException(sprintf('The %s must start with "/".', $label));
        }

        $segments = explode('/', substr($path, 1));
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '' && $i !== $last) {
                throw new InvalidArgumentException(sprintf('The %s must not contain empty segments ("//").', $label));
            }
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..') {
                throw new InvalidArgumentException(sprintf('The %s must not contain "." or ".." segments.', $label));
            }
        }
    }
}
