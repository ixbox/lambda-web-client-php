<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use GuzzleHttp\Psr7\Response;
use Ixbox\LambdaWeb\Client;
use Ixbox\LambdaWeb\Tests\Support\SigV4Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

final class RequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function pathJoinCases(): iterable
    {
        $e = self::ENDPOINT;

        yield 'no base path, empty path' => [$e, '', '/', ''];
        yield 'no base path, trailing slash endpoint, empty path' => [$e . '/', '', '/', ''];
        yield 'no base path, root path' => [$e, '/', '/', ''];
        yield 'no base path, simple path' => [$e, '/jobs', '/jobs', ''];
        yield 'trailing slash in request path is kept' => [$e, '/jobs/', '/jobs/', ''];
        yield 'base path, simple path' => [$e . '/api/v1', '/jobs', '/api/v1/jobs', ''];
        yield 'base path with trailing slash' => [$e . '/api/v1/', '/jobs', '/api/v1/jobs', ''];
        yield 'base path, empty path keeps endpoint path' => [$e . '/api/v1/', '', '/api/v1/', ''];
        yield 'base path, root path' => [$e . '/api', '/', '/api/', ''];
        yield 'query in path' => [$e, '/jobs?status=pending&page=2', '/jobs', 'status=pending&page=2'];
        yield 'already encoded path is preserved' => [$e, '/files/a%20b/c%2Fd', '/files/a%20b/c%2Fd', ''];
        yield 'unencoded characters are percent-encoded' => [$e, '/files/日本 語', '/files/%E6%97%A5%E6%9C%AC%20%E8%AA%9E', ''];
        yield 'colon and at sign stay literal' => [$e, '/jobs:archive/@me', '/jobs:archive/@me', ''];
        yield 'backslash is encoded, host unchanged' => [$e, '/\\evil.example.com', '/%5Cevil.example.com', ''];
        yield 'explicit port is kept' => ['https://localhost:8443/base', '/x', '/base/x', ''];
    }

    #[DataProvider('pathJoinCases')]
    public function testEndpointAndPathAreJoined(string $endpoint, string $path, string $expectedPath, string $expectedQuery): void
    {
        $this->createClient(endpoint: $endpoint)->get($path);

        $uri = $this->sentRequest()->getUri();
        $expectedHost = parse_url($endpoint, PHP_URL_HOST);

        self::assertSame('https', $uri->getScheme());
        self::assertSame($expectedHost, $uri->getHost());
        self::assertSame($expectedPath, $uri->getPath());
        self::assertSame($expectedQuery, $uri->getQuery());
        self::assertTrue(SigV4Verifier::isValid($this->sentRequest(), self::SECRET_KEY));
    }

    public function testHostHeaderAlwaysMatchesEndpoint(): void
    {
        $this->createClient()->get('/jobs');

        self::assertSame(self::HOST, $this->sentRequest()->getHeaderLine('Host'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('methods')]
    public function testShortcutsSendTheirMethod(string $method): void
    {
        $client = $this->createClient();

        $response = $client->{$method}('/resource');

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(strtoupper($method), $this->sentRequest()->getMethod());
    }

    public function testRequestMethodIsCaseInsensitive(): void
    {
        $this->createClient()->request('patch', '/x', ['json' => ['a' => 1]]);

        self::assertSame('PATCH', $this->sentRequest()->getMethod());
    }

    public function testReturnsThePsr7ResponseAsIs(): void
    {
        $client = $this->createClient([new Response(201, ['X-Job-Id' => '42'], '{"id":42}')]);

        $response = $client->post('/jobs', ['json' => ['type' => 'export']]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('42', $response->getHeaderLine('X-Job-Id'));
        self::assertSame('{"id":42}', (string) $response->getBody());
    }

    public function testQueryOptionAsString(): void
    {
        $this->createClient()->get('/jobs', ['query' => 'status=pending&tag=a&tag=b']);

        $request = $this->sentRequest();
        self::assertSame('status=pending&tag=a&tag=b', $request->getUri()->getQuery());
        self::assertTrue(SigV4Verifier::isValid($request, self::SECRET_KEY));
    }

    public function testQueryOptionAsArray(): void
    {
        $this->createClient()->get('/jobs', ['query' => ['status' => 'pending', 'limit' => 10, 'flag' => true]]);

        self::assertSame('status=pending&limit=10&flag=1', $this->sentRequest()->getUri()->getQuery());
    }

    public function testJsonSetsContentTypeUnlessGiven(): void
    {
        $client = $this->createClient([new Response(200), new Response(200)]);

        $client->post('/a', ['json' => ['x' => 1]]);
        $client->post('/b', ['json' => ['x' => 1], 'headers' => ['content-type' => 'application/merge-patch+json']]);

        self::assertSame('application/json', $this->sentRequest(0)->getHeaderLine('Content-Type'));
        self::assertSame('application/merge-patch+json', $this->sentRequest(1)->getHeaderLine('Content-Type'));
        self::assertCount(1, $this->sentRequest(1)->getHeader('Content-Type'));
    }

    public function testJsonScalarsAndNullAreEncoded(): void
    {
        $client = $this->createClient([new Response(200), new Response(200)]);

        $client->post('/a', ['json' => null]);
        $client->post('/b', ['json' => 'text/日本']);

        self::assertSame('null', (string) $this->sentRequest(0)->getBody());
        self::assertSame('"text/日本"', (string) $this->sentRequest(1)->getBody());
    }

    public function testTransportOptionsAreFixedAndTimeoutsConfigurable(): void
    {
        $client = $this->createClient([new Response(200), new Response(200)], timeout: 12.5, connectTimeout: 2);

        $client->get('/a');
        $client->get('/b', ['timeout' => 3, 'connect_timeout' => 0.5]);

        $defaults = $this->sentOptions(0);
        self::assertSame(12.5, $defaults['timeout']);
        self::assertSame(2.0, $defaults['connect_timeout']);
        self::assertFalse($defaults['allow_redirects']);
        self::assertTrue($defaults['verify']);

        $overridden = $this->sentOptions(1);
        self::assertSame(3.0, $overridden['timeout']);
        self::assertSame(0.5, $overridden['connect_timeout']);
    }

    public function testDefaultTimeouts(): void
    {
        $this->createClient()->get('/');

        self::assertSame(Client::DEFAULT_TIMEOUT, $this->sentOptions()['timeout']);
        self::assertSame(Client::DEFAULT_CONNECT_TIMEOUT, $this->sentOptions()['connect_timeout']);
    }
}
