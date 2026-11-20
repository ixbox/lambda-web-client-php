<?php

declare(strict_types=1);

namespace Ixbox\LambdaWeb\Tests;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ixbox\LambdaWeb\Client;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    // Dummy values in the format of the AWS documentation examples. Not real credentials.
    protected const ACCESS_KEY_ID = 'AKIDEXAMPLE';
    protected const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    protected const SESSION_TOKEN = 'dummy-session-token/with+special=chars';

    protected const REGION = 'ap-northeast-1';
    protected const HOST = 'bcdfghjklmnpqrst.b7t.lambda-web.ap-northeast-1.on.aws';
    protected const ENDPOINT = 'https://' . self::HOST;

    protected const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Filled by GuzzleHttp\Middleware::history(): one entry per request the transport received.
     *
     * @var array<mixed>|\ArrayAccess<int, array<mixed>>
     */
    protected array|\ArrayAccess $history = [];

    /**
     * Creates a client whose transport is a Guzzle MockHandler. Requests are recorded in
     * $this->history exactly as the transport receives them (i.e. after signing).
     *
     * @param list<mixed> $queue Responses / exceptions / callables for the MockHandler
     */
    protected function createClient(
        array $queue = [],
        ?callable $credentials = null,
        string $endpoint = self::ENDPOINT,
        float $timeout = Client::DEFAULT_TIMEOUT,
        float $connectTimeout = Client::DEFAULT_CONNECT_TIMEOUT,
        bool $httpErrors = false,
    ): Client {
        $this->history = [];
        $mock = new MockHandler($queue === [] ? [new Response(200, [], 'ok')] : $queue);
        $handler = Middleware::history($this->history)($mock);

        return new Client(
            endpoint: $endpoint,
            region: self::REGION,
            credentials: $credentials ?? self::staticCredentials(),
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            httpErrors: $httpErrors,
            handler: $handler,
        );
    }

    protected static function staticCredentials(): callable
    {
        return CredentialProvider::fromCredentials(new Credentials(self::ACCESS_KEY_ID, self::SECRET_KEY));
    }

    protected static function temporaryCredentials(): callable
    {
        return CredentialProvider::fromCredentials(
            new Credentials(self::ACCESS_KEY_ID, self::SECRET_KEY, self::SESSION_TOKEN, time() + 3600)
        );
    }

    protected function sentRequest(int $index = 0): RequestInterface
    {
        $request = $this->historyEntry($index)['request'] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /**
     * @return array<mixed>
     */
    protected function sentOptions(int $index = 0): array
    {
        $options = $this->historyEntry($index)['options'] ?? null;
        self::assertIsArray($options);

        return $options;
    }

    /**
     * @param array<mixed>|\ArrayAccess<int, array<mixed>> $history
     *
     * @return array<mixed>
     */
    protected static function entryOf(array|\ArrayAccess $history, int $index): array
    {
        self::assertTrue(isset($history[$index]), 'No request reached the transport handler.');
        $entry = $history[$index];
        self::assertIsArray($entry);

        return $entry;
    }

    /**
     * Number of requests that reached the transport handler.
     */
    protected function sentCount(): int
    {
        self::assertIsArray($this->history);

        return count($this->history);
    }

    /**
     * @return array<mixed>
     */
    private function historyEntry(int $index): array
    {
        return self::entryOf($this->history, $index);
    }
}
