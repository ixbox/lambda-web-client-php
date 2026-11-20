<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests\Support;

use Psr\Http\Message\RequestInterface;

/**
 * Test-only SigV4 verifier.
 *
 * Re-computes the signature of a request *as received by the transport handler*, following
 * the algorithm documented in "Create a signed AWS API request" (IAM User Guide), and compares
 * it with the signature in the Authorization header. Because it uses the request's own
 * X-Amz-Date, it works without freezing time. If anything covered by the signature (method,
 * path, query, signed headers, payload hash) changed after signing, verification fails.
 *
 * This lives in tests only; the library itself delegates signing to Aws\Signature\SignatureV4.
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final class SigV4Verifier
{
    /**
     * @return array{accessKeyId: string, date: string, region: string, service: string, signedHeaders: list<string>, signature: string}
     */
    public static function parseAuthorization(RequestInterface $request): array
    {
        $header = $request->getHeaderLine('Authorization');
        $pattern = '#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/([^/]+)/aws4_request, '
            . 'SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$#';
        if (preg_match($pattern, $header, $m) !== 1) {
            throw new \RuntimeException('Authorization header is not a SigV4 header: ' . $header);
        }

        return [
            'accessKeyId' => $m[1],
            'date' => $m[2],
            'region' => $m[3],
            'service' => $m[4],
            'signedHeaders' => explode(';', $m[5]),
            'signature' => $m[6],
        ];
    }

    public static function expectedSignature(RequestInterface $request, string $secretKey): string
    {
        $auth = self::parseAuthorization($request);
        $amzDate = $request->getHeaderLine('X-Amz-Date');
        $scope = "{$auth['date']}/{$auth['region']}/{$auth['service']}/aws4_request";

        $canonicalRequest = implode("\n", [
            $request->getMethod(),
            self::canonicalUri($request->getUri()->getPath()),
            self::canonicalQuery($request->getUri()->getQuery()),
            self::canonicalHeaders($request, $auth['signedHeaders']),
            implode(';', $auth['signedHeaders']),
            $request->getHeaderLine('X-Amz-Content-Sha256'),
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $auth['date'], 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $auth['region'], $kDate, true);
        $kService = hash_hmac('sha256', $auth['service'], $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    public static function isValid(RequestInterface $request, string $secretKey): bool
    {
        return hash_equals(self::expectedSignature($request, $secretKey), self::parseAuthorization($request)['signature']);
    }

    /**
     * Non-S3 services: each (already percent-encoded) path segment is URI-encoded again.
     */
    private static function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $part) {
            $kv = explode('=', $part, 2);
            $k = $kv[0];
            $v = $kv[1] ?? '';
            $pairs[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    /**
     * @param list<string> $signedHeaders
     */
    private static function canonicalHeaders(RequestInterface $request, array $signedHeaders): string
    {
        $lines = '';
        foreach ($signedHeaders as $name) {
            if (!$request->hasHeader($name)) {
                throw new \RuntimeException("Signed header {$name} is missing from the sent request.");
            }
            $values = array_map(
                static fn (string $v): string => (string) preg_replace('/\s+/', ' ', trim($v)),
                $request->getHeader($name),
            );
            sort($values);
            $lines .= $name . ':' . implode(',', $values) . "\n";
        }

        return $lines;
    }
}
