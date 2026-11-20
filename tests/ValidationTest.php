<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use GuzzleHttp\Psr7\Utils;
use Ixbox\LambdaWeb\Client;
use Ixbox\LambdaWeb\Exception\InvalidArgumentException;
use Ixbox\LambdaWeb\Exception\LambdaWebException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEndpoints(): iterable
    {
        yield 'empty' => [''];
        yield 'surrounding whitespace' => [' ' . self::ENDPOINT];
        yield 'plain http' => ['http://' . self::HOST];
        yield 'other scheme' => ['ftp://' . self::HOST];
        yield 'no scheme' => [self::HOST];
        yield 'scheme-relative' => ['//' . self::HOST];
        yield 'no host' => ['https://'];
        yield 'user info' => ['https://user:secret@' . self::HOST];
        yield 'query' => [self::ENDPOINT . '/?a=1'];
        yield 'empty query' => [self::ENDPOINT . '/?'];
        yield 'fragment' => [self::ENDPOINT . '/#frag'];
        yield 'dot segment in base path' => [self::ENDPOINT . '/a/../b'];
        yield 'empty segment in base path' => [self::ENDPOINT . '//a'];
        yield 'MultiRegion global domain' => ['https://bcdfghjklmn.lambda-web.global.on.aws'];
        yield 'regional domain of another region' => ['https://abc.b7t.lambda-web.us-east-1.on.aws'];
        yield 'malformed' => ['https://exa mple.com:99999'];
    }

    #[DataProvider('invalidEndpoints')]
    public function testInvalidEndpointIsRejected(string $endpoint): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(endpoint: $endpoint, region: self::REGION, credentials: self::staticCredentials());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRegions(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['AP-NORTHEAST-1'];
        yield 'global' => ['global'];
        yield 'wildcard (SigV4a region set)' => ['*'];
        yield 'missing number' => ['ap-northeast'];
        yield 'spaces' => ['ap northeast 1'];
        yield 'trailing newline' => ["ap-northeast-1\n"];
    }

    #[DataProvider('invalidRegions')]
    public function testInvalidRegionIsRejected(string $region): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(endpoint: 'https://example.com', region: $region, credentials: self::staticCredentials());
    }

    public function testValidRegionsAreAccepted(): void
    {
        foreach (['us-east-1', 'ap-northeast-3', 'eu-central-2', 'us-gov-west-1', 'cn-north-1'] as $region) {
            $client = new Client(endpoint: 'https://example.com', region: $region, credentials: self::staticCredentials());
            self::assertInstanceOf(Client::class, $client);
        }
    }

    public function testInvalidClientTimeoutsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(endpoint: self::ENDPOINT, region: self::REGION, credentials: self::staticCredentials(), timeout: -1);
    }

    public function testNonFiniteConnectTimeoutIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client(
            endpoint: self::ENDPOINT,
            region: self::REGION,
            credentials: self::staticCredentials(),
            connectTimeout: INF,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostChangingPaths(): iterable
    {
        yield 'absolute https URL' => ['https://evil.example.com/steal'];
        yield 'absolute http URL' => ['http://evil.example.com/steal'];
        yield 'network-path reference' => ['//evil.example.com/steal'];
        yield 'network-path reference with userinfo' => ['//user@evil.example.com'];
        yield 'relative path' => ['jobs'];
        yield 'relative with colon' => ['evil.example.com:443/x'];
        yield 'dot dot segment' => ['/a/../b'];
        yield 'encoded dot dot segment' => ['/a/%2e%2E/b'];
        yield 'dot segment' => ['/./a'];
        yield 'empty segment' => ['/a//b'];
        yield 'fragment' => ['/a#frag'];
    }

    #[DataProvider('hostChangingPaths')]
    public function testPathCannotChangeTheDestinationOrBeAmbiguous(string $path): void
    {
        $client = $this->createClient();

        try {
            $client->get($path);
            self::fail('Expected InvalidArgumentException for path ' . $path);
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->history, 'Nothing must be sent.');
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'json and body together' => [['json' => ['a' => 1], 'body' => 'x']];
        yield 'json null and body together' => [['json' => null, 'body' => '']];
        yield 'form_params unsupported' => [['form_params' => ['a' => 1]]];
        yield 'multipart unsupported' => [['multipart' => []]];
        yield 'auth unsupported' => [['auth' => ['user', 'pass']]];
        yield 'allow_redirects not configurable' => [['allow_redirects' => true]];
        yield 'verify not configurable' => [['verify' => false]];
        yield 'sink unsupported' => [['sink' => '/tmp/x']];
        yield 'debug unsupported' => [['debug' => true]];
        yield 'body array' => [['body' => ['a' => 1]]];
        yield 'body int' => [['body' => 123]];
        yield 'body stream' => [['body' => Utils::streamFor('x')]];
        yield 'body resource' => [['body' => fopen('php://memory', 'rb')]];
        yield 'json invalid UTF-8' => [['json' => ['a' => "\xB1\x31"]]];
        yield 'json resource' => [['json' => fopen('php://memory', 'rb')]];
        yield 'query int' => [['query' => 5]];
        yield 'query object value' => [['query' => ['a' => new \stdClass()]]];
        yield 'query with fragment' => [['query' => 'a=1#x']];
        yield 'headers not array' => [['headers' => 'X-A: b']];
        yield 'header list' => [['headers' => ['X-A: b']]];
        yield 'header bool value' => [['headers' => ['X-A' => true]]];
        yield 'header null value' => [['headers' => ['X-A' => null]]];
        yield 'header empty list' => [['headers' => ['X-A' => []]]];
        yield 'header name with space' => [['headers' => ['Bad Header' => 'x']]];
        yield 'header value with CRLF' => [['headers' => ['X-A' => "x\r\nX-Injected: 1"]]];
        yield 'duplicate header names' => [['headers' => ['X-A' => '1', 'x-a' => '2']]];
        yield 'timeout negative' => [['timeout' => -1]];
        yield 'timeout string' => [['timeout' => '10']];
        yield 'connect_timeout NAN' => [['connect_timeout' => NAN]];
        yield 'http_errors not bool' => [['http_errors' => 'yes']];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionIsRejectedBeforeSending(array $options): void
    {
        $client = $this->createClient();

        try {
            $client->post('/jobs', $options);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertInstanceOf(LambdaWebException::class, $e);
            self::assertSame([], $this->history, 'Nothing must be sent.');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedHeaders(): iterable
    {
        foreach (
            [
                'Authorization', 'authorization', 'Host', 'Date', 'X-Amz-Date', 'X-Amz-Security-Token',
                'X-Amz-Content-Sha256', 'x-amz-anything', 'Content-Length', 'Transfer-Encoding', 'Expect',
                'Connection', 'Proxy-Authorization',
            ] as $name
        ) {
            yield $name => [$name];
        }
    }

    #[DataProvider('reservedHeaders')]
    public function testSigningManagedHeadersCannotBeSet(string $name): void
    {
        $client = $this->createClient();

        try {
            $client->get('/jobs', ['headers' => [$name => 'value']]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($name, $e->getMessage());
            self::assertSame([], $this->history);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedMethods(): iterable
    {
        yield 'TRACE' => ['TRACE'];
        yield 'CONNECT' => ['CONNECT'];
        yield 'custom' => ['PURGE'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsupportedMethods')]
    public function testUnsupportedMethodIsRejected(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createClient()->request($method, '/');
    }

    public function testQueryInPathAndQueryOptionTogetherIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createClient()->get('/jobs?a=1', ['query' => ['b' => 2]]);
    }

    public function testCredentialsAreNotPartOfValidationMessages(): void
    {
        try {
            new Client(endpoint: 'https://AKIDEXAMPLE:topsecret@' . self::HOST, region: self::REGION);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('topsecret', $e->getMessage());
        }
    }
}
