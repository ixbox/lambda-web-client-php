<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use GuzzleHttp\Psr7\Utils;
use Ixbox\LambdaWeb\Tests\Support\SigV4Verifier;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * These tests run the real Aws\Signature\SignatureV4 (no mocks of the signing process) and
 * inspect the request as received by the transport handler.
 */
final class SigningTest extends TestCase
{
    public function testGetIsSignedWithSigV4ForLambdaInConfiguredRegion(): void
    {
        $this->createClient()->get('/jobs');

        $request = $this->sentRequest();
        $auth = SigV4Verifier::parseAuthorization($request);

        self::assertSame(self::ACCESS_KEY_ID, $auth['accessKeyId']);
        self::assertSame(self::REGION, $auth['region']);
        self::assertSame('lambda', $auth['service']);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $request->getHeaderLine('X-Amz-Date'));
        self::assertSame(substr($request->getHeaderLine('X-Amz-Date'), 0, 8), $auth['date']);
        self::assertContains('host', $auth['signedHeaders']);
        self::assertContains('x-amz-date', $auth['signedHeaders']);
        self::assertContains('x-amz-content-sha256', $auth['signedHeaders']);
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testRequestWithoutBodySendsEmptyPayloadHash(): void
    {
        $this->createClient()->get('/jobs');

        $request = $this->sentRequest();
        self::assertSame('', (string) $request->getBody());
        self::assertSame(self::EMPTY_SHA256, $request->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methodsWithoutBody(): iterable
    {
        foreach (['GET', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('methodsWithoutBody')]
    public function testEveryMethodWithoutBodyCarriesThePayloadHash(string $method): void
    {
        $this->createClient()->request($method, '/resource');

        $request = $this->sentRequest();
        self::assertSame($method, $request->getMethod());
        self::assertSame(self::EMPTY_SHA256, $request->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testJsonBodyHashMatchesTheBytesActuallySent(): void
    {
        $this->createClient()->post('/jobs', ['json' => ['type' => 'export']]);

        $request = $this->sentRequest();
        $sentBody = (string) $request->getBody();

        self::assertSame('{"type":"export"}', $sentBody);
        self::assertSame(hash('sha256', $sentBody), $request->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertContains('content-type', SigV4Verifier::parseAuthorization($request)['signedHeaders']);
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testMultibyteAndSymbolBodyIsHashedAsUtf8Bytes(): void
    {
        $payload = ['message' => 'こんにちは 世界 & <tag> "quote" / \\ +=?%', 'emoji' => '🍣', 'float' => 1.0];

        $this->createClient()->post('/messages', ['json' => $payload]);

        $request = $this->sentRequest();
        $sentBody = (string) $request->getBody();

        self::assertStringContainsString('こんにちは 世界', $sentBody);
        self::assertStringContainsString('🍣', $sentBody);
        self::assertStringContainsString('"float":1.0', $sentBody);
        self::assertSame($payload, json_decode($sentBody, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(hash('sha256', $sentBody), $request->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testStringBodyIsSentAndSignedUnchanged(): void
    {
        $body = "名前=山田 太郎&memo=a+b c\r\n<xml attr=\"1\"/>\x00binary";

        $this->createClient()->put('/raw', ['body' => $body, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8']]);

        $request = $this->sentRequest();
        self::assertSame($body, (string) $request->getBody());
        self::assertSame(hash('sha256', $body), $request->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertSame('text/plain; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testTemporaryCredentialsAddSignedSessionToken(): void
    {
        $this->createClient(credentials: self::temporaryCredentials())->get('/jobs');

        $request = $this->sentRequest();
        self::assertSame(self::SESSION_TOKEN, $request->getHeaderLine('X-Amz-Security-Token'));
        self::assertContains('x-amz-security-token', SigV4Verifier::parseAuthorization($request)['signedHeaders']);
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testLongTermCredentialsDoNotAddSessionToken(): void
    {
        $this->createClient()->get('/jobs');

        self::assertFalse($this->sentRequest()->hasHeader('X-Amz-Security-Token'));
    }

    public function testQueryAndEncodedPathAreCoveredBySignature(): void
    {
        $this->createClient()->get('/files/日本語 ファイル/a%2Fb', [
            'query' => ['q' => '日本 語&x=y+z', 'tags' => ['b', 'a'], 'empty' => '', 'sym' => "!*'();:@&=+$,/?#[]"],
        ]);

        $request = $this->sentRequest();
        self::assertSame(
            '/files/%E6%97%A5%E6%9C%AC%E8%AA%9E%20%E3%83%95%E3%82%A1%E3%82%A4%E3%83%AB/a%2Fb',
            $request->getUri()->getPath(),
        );
        parse_str($request->getUri()->getQuery(), $parsed);
        self::assertSame('日本 語&x=y+z', $parsed['q']);
        self::assertSame(['b', 'a'], $parsed['tags']);
        self::assertSame('', $parsed['empty']);
        self::assertSame("!*'();:@&=+$,/?#[]", $parsed['sym']);
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testSignatureWouldNotSurviveTamperingAfterSigning(): void
    {
        // Sanity check of the verifier itself: changing a signed element after signing is detected.
        $this->createClient()->post('/jobs', ['json' => ['type' => 'export']]);
        $request = $this->sentRequest();

        $tamperedBody = $request
            ->withBody(Utils::streamFor('{"type":"delete"}'))
            ->withHeader('X-Amz-Content-Sha256', hash('sha256', '{"type":"delete"}'));
        $tamperedPath = $request->withUri($request->getUri()->withPath('/admin'));
        $tamperedQuery = $request->withUri($request->getUri()->withQuery('x=1'));

        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
        self::assertFalse(SigV4Verifier::isValid($tamperedBody, self::SECRET_KEY));
        self::assertFalse(SigV4Verifier::isValid($tamperedPath, self::SECRET_KEY));
        self::assertFalse(SigV4Verifier::isValid($tamperedQuery, self::SECRET_KEY));
    }

    public function testApplicationHeadersAreSentAndContentTypeIsSigned(): void
    {
        $this->createClient()->post('/jobs', [
            'json' => ['a' => 1],
            'headers' => [
                'Content-Type' => 'application/vnd.example+json',
                'X-Request-Id' => 'req-123',
                'Accept' => 'application/json',
                'X-Multi' => ['one', 'two'],
            ],
        ]);

        $request = $this->sentRequest();
        $signed = SigV4Verifier::parseAuthorization($request)['signedHeaders'];

        self::assertSame('application/vnd.example+json', $request->getHeaderLine('Content-Type'));
        self::assertSame('req-123', $request->getHeaderLine('X-Request-Id'));
        self::assertSame(['one', 'two'], $request->getHeader('X-Multi'));
        self::assertContains('content-type', $signed);
        self::assertContains('x-request-id', $signed);
        self::assertNotContains('accept', $signed, 'The SDK does not sign Accept (proxy-safe header list).');
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testLargeBodyIsSignedWithoutExpectHeader(): void
    {
        $body = str_repeat('x', 2 * 1024 * 1024);

        $this->createClient()->post('/upload', ['body' => $body]);

        $request = $this->sentRequest();
        self::assertFalse($request->hasHeader('Expect'));
        self::assertSame((string) strlen($body), $request->getHeaderLine('Content-Length'));
        self::assertSame(hash('sha256', $body), $request->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }
}
