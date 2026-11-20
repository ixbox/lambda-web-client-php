<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Ixbox\LambdaWeb\Client;
use Ixbox\LambdaWeb\Exception\InvalidArgumentException;
use Ixbox\LambdaWeb\Exception\InvalidRequestException;
use Ixbox\LambdaWeb\Exception\TransportException;
use Ixbox\LambdaWeb\Tests\Support\SigV4Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class Psr18Test extends TestCase
{
    public function testClientImplementsPsr18(): void
    {
        self::assertInstanceOf(ClientInterface::class, $this->createClient());
    }

    public function testSendRequestSignsAndSendsTheRequest(): void
    {
        $client = $this->createClient([new Response(200, [], 'ok')], credentials: self::temporaryCredentials());

        $response = $client->sendRequest(new Request('GET', self::ENDPOINT . '/jobs?status=pending&q=%E6%97%A5'));

        self::assertSame('ok', (string) $response->getBody());
        $sent = $this->sentRequest();
        self::assertSame('GET', $sent->getMethod());
        self::assertSame('/jobs', $sent->getUri()->getPath());
        self::assertSame('status=pending&q=%E6%97%A5', $sent->getUri()->getQuery());
        self::assertSame(self::HOST, $sent->getHeaderLine('Host'));
        self::assertSame(self::EMPTY_SHA256, $sent->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertSame(self::SESSION_TOKEN, $sent->getHeaderLine('X-Amz-Security-Token'));
        self::assertSame('lambda', SigV4Verifier::parseAuthorization($sent)['service']);
        self::assertTrue(SigV4Verifier::isValid($sent, self::SECRET_KEY));
    }

    public function testStreamBodyIsHashedAndSentFromTheBeginning(): void
    {
        $content = '{"message":"こんにちは 世界"}';
        $stream = Utils::streamFor($content);
        $stream->read(5); // a consumer may hand over a stream that is not at position 0

        $request = new Request('POST', self::ENDPOINT . '/messages', ['Content-Type' => 'application/json'], $stream);
        $this->createClient()->sendRequest($request);

        $sent = $this->sentRequest();
        self::assertSame($content, (string) $sent->getBody());
        self::assertSame(hash('sha256', $content), $sent->getHeaderLine('X-Amz-Content-Sha256'));
        self::assertContains('content-type', SigV4Verifier::parseAuthorization($sent)['signedHeaders']);
        self::assertTrue(SigV4Verifier::isValid($sent, self::SECRET_KEY));
    }

    public function testErrorResponsesAreNeverThrownBySendRequest(): void
    {
        $client = $this->createClient([new Response(403), new Response(500)], httpErrors: true);

        self::assertSame(403, $client->sendRequest(new Request('GET', self::ENDPOINT . '/a'))->getStatusCode());
        self::assertSame(500, $client->sendRequest(new Request('DELETE', self::ENDPOINT . '/b'))->getStatusCode());
    }

    public function testLibraryDefaultReturnsErrorResponsesFromShortcutsToo(): void
    {
        $client = new Client(
            endpoint: self::ENDPOINT,
            region: self::REGION,
            credentials: self::staticCredentials(),
            handler: new MockHandler([new Response(500, [], 'boom')]),
        );

        self::assertSame(500, $client->post('/jobs', ['json' => []])->getStatusCode());
    }

    public function testRedirectIsReturnedNotFollowed(): void
    {
        $client = $this->createClient([new Response(301, ['Location' => 'https://evil.example.com/']), new Response(200)]);

        self::assertSame(301, $client->sendRequest(new Request('GET', self::ENDPOINT . '/old'))->getStatusCode());
        self::assertSame(1, $this->sentCount());
    }

    public function testMethodIsNormalizedToUpperCase(): void
    {
        $this->createClient()->sendRequest(new Request('post', self::ENDPOINT . '/jobs', [], 'x'));

        self::assertSame('POST', $this->sentRequest()->getMethod());
        self::assertTrue(SigV4Verifier::isValid($this->sentRequest(), self::SECRET_KEY));
    }

    public function testMatchingContentLengthIsAccepted(): void
    {
        $this->createClient()->sendRequest(new Request('PUT', self::ENDPOINT . '/raw', ['Content-Length' => '3'], 'abc'));

        self::assertSame('3', $this->sentRequest()->getHeaderLine('Content-Length'));
        self::assertTrue(SigV4Verifier::isValid($this->sentRequest(), self::SECRET_KEY));
    }

    public function testMissingHostHeaderIsAdded(): void
    {
        $request = (new Request('GET', self::ENDPOINT . '/jobs'))->withoutHeader('Host');

        $this->createClient()->sendRequest($request);

        self::assertSame(self::HOST, $this->sentRequest()->getHeaderLine('Host'));
        self::assertContains('host', SigV4Verifier::parseAuthorization($this->sentRequest())['signedHeaders']);
    }

    public function testRequestBelowEndpointBasePathIsAccepted(): void
    {
        $client = $this->createClient([new Response(200), new Response(200)], endpoint: self::ENDPOINT . '/api/');

        $client->sendRequest(new Request('GET', self::ENDPOINT . '/api'));
        $client->sendRequest(new Request('GET', self::ENDPOINT . '/api/v1/jobs'));

        self::assertSame('/api', $this->sentRequest(0)->getUri()->getPath());
        self::assertSame('/api/v1/jobs', $this->sentRequest(1)->getUri()->getPath());
    }

    /**
     * @return iterable<string, array{RequestInterface}>
     */
    public static function unsafeRequests(): iterable
    {
        $e = self::ENDPOINT;

        yield 'other host' => [new Request('GET', 'https://evil.example.com/jobs')];
        yield 'subdomain of endpoint host' => [new Request('GET', 'https://x.' . self::HOST . '/jobs')];
        yield 'plain http' => [new Request('GET', 'http://' . self::HOST . '/jobs')];
        yield 'other port' => [new Request('GET', 'https://' . self::HOST . ':8443/jobs')];
        yield 'relative URI' => [new Request('GET', '/jobs')];
        yield 'user info' => [new Request('GET', 'https://user:pass@' . self::HOST . '/jobs')];
        yield 'fragment' => [new Request('GET', $e . '/jobs#frag')];
        yield 'dot segment' => [new Request('GET', $e . '/a/../admin')];
        yield 'empty segment' => [new Request('GET', $e . '/a//b')];
        yield 'unsupported method' => [new Request('TRACE', $e . '/jobs')];
        yield 'Host header of another host' => [(new Request('GET', $e . '/jobs'))->withHeader('Host', 'evil.example.com')];
        yield 'Authorization header' => [new Request('GET', $e . '/jobs', ['Authorization' => 'Bearer app-token'])];
        yield 'X-Amz-Date header' => [new Request('GET', $e . '/jobs', ['X-Amz-Date' => '20260101T000000Z'])];
        yield 'X-Amz-Content-Sha256 header' => [new Request('POST', $e . '/jobs', ['X-Amz-Content-Sha256' => 'UNSIGNED-PAYLOAD'], 'x')];
        yield 'Transfer-Encoding header' => [new Request('POST', $e . '/jobs', ['Transfer-Encoding' => 'chunked'], 'x')];
        yield 'wrong Content-Length' => [new Request('POST', $e . '/jobs', ['Content-Length' => '99'], 'x')];
        yield 'non-seekable body' => [new Request('POST', $e . '/jobs', [], new NoSeekStream(Utils::streamFor('x')))];
        yield 'unknown body size' => [
            new Request('POST', $e . '/jobs', [], FnStream::decorate(Utils::streamFor('x'), ['getSize' => static fn () => null])),
        ];
    }

    #[DataProvider('unsafeRequests')]
    public function testUnsafeRequestIsRejectedWithRequestException(RequestInterface $request): void
    {
        $client = $this->createClient();

        try {
            $client->sendRequest($request);
            self::fail('Expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertInstanceOf(RequestExceptionInterface::class, $e);
            self::assertInstanceOf(ClientExceptionInterface::class, $e);
            self::assertInstanceOf(InvalidArgumentException::class, $e);
            self::assertSame($request, $e->getRequest());
            self::assertInstanceOf(InvalidArgumentException::class, $e->getPrevious());
        }
        self::assertSame(0, $this->sentCount(), 'Nothing must be sent.');
    }

    public function testRequestBeyondEndpointBasePathIsRejected(): void
    {
        $client = $this->createClient(endpoint: self::ENDPOINT . '/api');

        $this->expectException(InvalidRequestException::class);
        $client->sendRequest(new Request('GET', self::ENDPOINT . '/apix/jobs'));
    }

    public function testNetworkErrorImplementsPsr18NetworkException(): void
    {
        $client = $this->createClient(
            [static fn (RequestInterface $r): ConnectException => new ConnectException('cURL error 6', $r)],
            credentials: self::temporaryCredentials(),
        );

        try {
            $client->sendRequest(new Request('GET', self::ENDPOINT . '/jobs'));
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertInstanceOf(NetworkExceptionInterface::class, $e);
            self::assertFalse($e->getRequest()->hasHeader('Authorization'));
            self::assertFalse($e->getRequest()->hasHeader('X-Amz-Security-Token'));
        }
    }

    public function testCredentialsFailureIsAPsr18ClientException(): void
    {
        $client = $this->createClient(credentials: static fn () => throw new \RuntimeException('no credentials'));

        $this->expectException(ClientExceptionInterface::class);
        $client->sendRequest(new Request('GET', self::ENDPOINT . '/jobs'));
    }
}
