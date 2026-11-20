<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Ixbox\LambdaWeb\Exception\HttpResponseException;
use Ixbox\LambdaWeb\Exception\LambdaWebException;
use Ixbox\LambdaWeb\Exception\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;

final class ErrorHandlingTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function errorStatuses(): iterable
    {
        yield '400' => [400];
        yield '403' => [403];
        yield '404' => [404];
        yield '429' => [429];
        yield '500' => [500];
        yield '503' => [503];
    }

    #[DataProvider('errorStatuses')]
    public function testErrorStatusIsReturnedAsResponseByDefault(int $status): void
    {
        // PSR-18 semantics: a well-formed 4xx / 5xx response is a successful round trip.
        $client = $this->createClient([new Response($status, [], 'error body')]);

        $response = $client->get('/jobs');

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('error body', (string) $response->getBody());
    }

    #[DataProvider('errorStatuses')]
    public function testErrorStatusThrowsHttpResponseExceptionWhenEnabled(int $status): void
    {
        $client = $this->createClient(
            [new Response($status, ['Content-Type' => 'application/json'], '{"message":"nope"}')],
            credentials: self::temporaryCredentials(),
            httpErrors: true,
        );

        try {
            $client->get('/jobs', ['query' => ['token' => 'app-secret']]);
            self::fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            self::assertInstanceOf(LambdaWebException::class, $e);
            self::assertSame($status, $e->getStatusCode());
            self::assertSame($status, $e->getCode());
            self::assertSame('{"message":"nope"}', (string) $e->getResponse()->getBody());
            self::assertStringContainsString((string) $status, $e->getMessage());
            self::assertStringContainsString('GET https://' . self::HOST . '/jobs', $e->getMessage());
            // No query string, credentials or signature in the message.
            self::assertStringNotContainsString('app-secret', $e->getMessage());
            self::assertStringNotContainsString(self::ACCESS_KEY_ID, $e->getMessage());
            self::assertStringNotContainsString(self::SESSION_TOKEN, $e->getMessage());
            // The exposed request is the unsigned one.
            self::assertFalse($e->getRequest()->hasHeader('Authorization'));
            self::assertFalse($e->getRequest()->hasHeader('X-Amz-Security-Token'));
        }
        self::assertSame(1, $this->sentCount(), 'No retry.');
    }

    public function testForbiddenIsNotReportedAsSignatureError(): void
    {
        $client = $this->createClient([new Response(403, [], 'Forbidden')], httpErrors: true);

        try {
            $client->get('/jobs');
            self::fail('Expected HttpResponseException');
        } catch (HttpResponseException $e) {
            self::assertStringNotContainsStringIgnoringCase('signature', $e->getMessage());
        }
    }

    public function testHttpErrorsCanBeDisabledPerClient(): void
    {
        $client = $this->createClient([new Response(404), new Response(500)]);

        self::assertSame(404, $client->get('/a')->getStatusCode());
        self::assertSame(500, $client->get('/b')->getStatusCode());
    }

    public function testHttpErrorsCanBeChangedPerRequest(): void
    {
        $client = $this->createClient([new Response(404), new Response(404)], httpErrors: true);

        self::assertSame(404, $client->get('/a', ['http_errors' => false])->getStatusCode());

        $strict = $this->createClient([new Response(404)]);
        $this->expectException(HttpResponseException::class);
        $strict->get('/b', ['http_errors' => true]);
    }

    public function testRedirectIsNotFollowed(): void
    {
        $client = $this->createClient([
            new Response(302, ['Location' => 'https://evil.example.com/collect']),
            new Response(200, [], 'should never be requested'),
        ]);

        $response = $client->get('/old');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://evil.example.com/collect', $response->getHeaderLine('Location'));
        self::assertSame(1, $this->sentCount());
        self::assertSame(self::HOST, $this->sentRequest()->getUri()->getHost());
    }

    public function testConnectionErrorThrowsTransportExceptionWithoutSignedRequest(): void
    {
        $client = $this->createClient(
            [
                // Like the curl handler, the error carries the request the transport received (the signed one).
                static fn (RequestInterface $request): ConnectException => new ConnectException(
                    'cURL error 7: Failed to connect',
                    $request,
                ),
            ],
            credentials: self::temporaryCredentials(),
        );

        try {
            $client->post('/jobs', ['json' => ['type' => 'export']]);
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertInstanceOf(LambdaWebException::class, $e);
            self::assertStringContainsString('POST request to https://' . self::HOST . '/jobs', $e->getMessage());

            $previous = $e->getPrevious();
            self::assertInstanceOf(ConnectException::class, $previous);
            self::assertSame('cURL error 7: Failed to connect', $previous->getMessage());

            foreach ([$e->getRequest(), $previous->getRequest()] as $request) {
                self::assertFalse($request->hasHeader('Authorization'));
                self::assertFalse($request->hasHeader('X-Amz-Security-Token'));
                self::assertFalse($request->hasHeader('X-Amz-Date'));
            }
            self::assertStringNotContainsString(self::SESSION_TOKEN, $e->getMessage());
        }

        self::assertTrue($this->sentRequest()->hasHeader('Authorization'), 'The transport did receive a signed request.');
        self::assertSame(1, $this->sentCount(), 'No retry.');
    }

    public function testTransferErrorWithoutResponseThrowsTransportException(): void
    {
        $client = $this->createClient([
            static fn (RequestInterface $request): RequestException => new RequestException(
                'cURL error 28: Operation timed out',
                $request,
            ),
        ]);

        try {
            $client->get('/slow');
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(RequestException::class, $previous);
            self::assertFalse($previous->getRequest()->hasHeader('Authorization'));
        }
    }
}
