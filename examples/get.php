<?php

/**
 * GET example.
 *
 *   WEB_FUNCTION_URL=https://xxxx.xxx.lambda-web.ap-northeast-1.on.aws \
 *   AWS_REGION=ap-northeast-1 \
 *   php examples/get.php
 *
 * Credentials are resolved by the AWS SDK default provider chain (IAM role, AWS_PROFILE,
 * environment variables, ...). Do not put access keys in this file.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ixbox\LambdaWeb\Client;
use Ixbox\LambdaWeb\Exception\LambdaWebException;

$endpoint = getenv('WEB_FUNCTION_URL');
$region = getenv('AWS_REGION');
if ($endpoint === false || $endpoint === '' || $region === false || $region === '') {
    fwrite(STDERR, "Set WEB_FUNCTION_URL and AWS_REGION.\n");
    exit(1);
}

$client = new Client(endpoint: $endpoint, region: $region);

try {
    $response = $client->get('/jobs', [
        'query' => ['status' => 'pending'],
    ]);
    // 4xx / 5xx are returned as normal responses (PSR-18 behaviour).
    if ($response->getStatusCode() >= 400) {
        fwrite(STDERR, 'HTTP ' . $response->getStatusCode() . PHP_EOL . (string) $response->getBody() . PHP_EOL);
        exit(1);
    }
    echo $response->getStatusCode(), PHP_EOL;
    echo (string) $response->getBody(), PHP_EOL;
} catch (LambdaWebException $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
