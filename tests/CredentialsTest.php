<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Exception\CredentialsException as AwsCredentialsException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Ixbox\LambdaWeb\Client;
use Ixbox\LambdaWeb\Exception\CredentialsException;
use Ixbox\LambdaWeb\Tests\Support\SigV4Verifier;
use Psr\Http\Message\RequestInterface;

final class CredentialsTest extends TestCase
{
    public function testProviderIsNotCalledAtConstructionAndIsCalledPerRequest(): void
    {
        $calls = 0;
        $provider = static function () use (&$calls): PromiseInterface {
            $calls++;

            return Create::promiseFor(new Credentials(self::ACCESS_KEY_ID, self::SECRET_KEY));
        };

        $client = $this->createClient([new Response(200), new Response(200)], credentials: $provider);
        self::assertSame(0, $calls, 'Credentials must not be resolved in the constructor.');

        $client->get('/a');
        $client->get('/b');

        self::assertSame(2, $calls);
    }

    public function testRefreshedCredentialsAreUsedForLaterRequests(): void
    {
        // Simulates a provider that rotates temporary credentials (e.g. a memoized STS/IMDS provider
        // after expiry). The client must not pin the first credentials.
        $issued = [
            new Credentials('ASIAFIRST', 'secret-one', 'token-one', time() + 900),
            new Credentials('ASIASECOND', 'secret-two', 'token-two', time() + 900),
        ];
        $provider = static function () use (&$issued): PromiseInterface {
            return Create::promiseFor(array_shift($issued));
        };

        $client = $this->createClient([new Response(200), new Response(200)], credentials: $provider);
        $client->get('/a');
        $client->get('/b');

        self::assertSame('ASIAFIRST', SigV4Verifier::parseAuthorization($this->sentRequest(0))['accessKeyId']);
        self::assertSame('token-one', $this->sentRequest(0)->getHeaderLine('X-Amz-Security-Token'));
        self::assertTrue(SigV4Verifier::isValid($this->sentRequest(0), 'secret-one'));

        self::assertSame('ASIASECOND', SigV4Verifier::parseAuthorization($this->sentRequest(1))['accessKeyId']);
        self::assertSame('token-two', $this->sentRequest(1)->getHeaderLine('X-Amz-Security-Token'));
        self::assertTrue(SigV4Verifier::isValid($this->sentRequest(1), 'secret-two'));
    }

    public function testMemoizedSdkProviderRefreshesExpiredCredentials(): void
    {
        $calls = 0;
        $inner = static function () use (&$calls): PromiseInterface {
            $calls++;
            // Already inside the SDK's refresh window, so memoize() must call the provider again.
            return Create::promiseFor(new Credentials("ASIA{$calls}", "secret-{$calls}", "token-{$calls}", time() + 10));
        };

        $client = $this->createClient([new Response(200), new Response(200)], credentials: CredentialProvider::memoize($inner));
        $client->get('/a');
        $client->get('/b');

        self::assertGreaterThanOrEqual(2, $calls);
        self::assertNotSame(
            SigV4Verifier::parseAuthorization($this->sentRequest(0))['accessKeyId'],
            SigV4Verifier::parseAuthorization($this->sentRequest(1))['accessKeyId'],
        );
    }

    public function testRejectedProviderThrowsCredentialsExceptionAndSendsNothing(): void
    {
        $original = new AwsCredentialsException('Error retrieving credentials from the instance profile metadata service');
        $provider = static fn (): PromiseInterface => Create::rejectionFor($original);

        $client = $this->createClient(credentials: $provider);

        try {
            $client->get('/jobs');
            self::fail('Expected CredentialsException');
        } catch (CredentialsException $e) {
            self::assertSame($original, $e->getPrevious());
        }
        self::assertSame([], $this->history);
    }

    public function testThrowingProviderThrowsCredentialsException(): void
    {
        $provider = static function (): never {
            throw new \RuntimeException('boom');
        };

        $this->expectException(CredentialsException::class);
        $this->createClient(credentials: $provider)->get('/jobs');
    }

    public function testProviderResolvingToNonCredentialsIsRejected(): void
    {
        $provider = static fn (): PromiseInterface => Create::promiseFor(['key' => 'AKID', 'secret' => 'x']);

        $this->expectException(CredentialsException::class);
        $this->createClient(credentials: $provider)->get('/jobs');
    }

    public function testDefaultProviderChainIsUsedWhenNoProviderIsGiven(): void
    {
        $saved = [];
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN'] as $name) {
            $saved[$name] = getenv($name);
        }

        putenv('AWS_ACCESS_KEY_ID=ASIAFROMENV');
        putenv('AWS_SECRET_ACCESS_KEY=secret-from-env');
        putenv('AWS_SESSION_TOKEN=token-from-env');

        try {
            $history = [];
            $handler = Middleware::history($history)(new MockHandler([new Response(200)]));
            $client = new Client(endpoint: self::ENDPOINT, region: self::REGION, handler: $handler);

            $client->get('/jobs');

            $request = self::entryOf($history, 0)['request'] ?? null;
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertSame('ASIAFROMENV', SigV4Verifier::parseAuthorization($request)['accessKeyId']);
            self::assertSame('token-from-env', $request->getHeaderLine('X-Amz-Security-Token'));
            self::assertTrue(SigV4Verifier::isValid($request, 'secret-from-env'));
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : "{$name}={$value}");
            }
        }
    }
}
