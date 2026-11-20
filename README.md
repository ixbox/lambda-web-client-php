# lambda-web-client-php

PHP HTTP client for AWS Lambda Web Functions with automatic SigV4 signing.

> **Status: 未リリース（開発中）**
> まだ Packagist に公開していません。API は変わる可能性があります。

AWS Lambda Web Functions のエンドポイントのうち、認証種別が `IamAuth` のものを、PHP から普通の HTTP クライアントと同じ感覚で呼ぶためのライブラリです。

`IamAuth` のエンドポイントを呼ぶには、リクエストごとに次の処理が必要です。

- AWS 認証情報の取得
- `X-Amz-Content-SHA256`（本文ハッシュ）の準備
- SigV4 署名
- 署名したリクエストの送信

このライブラリはこれらをまとめて引き受けます。
署名アルゴリズムと認証情報の探索は自作せず、[AWS SDK for PHP v3](https://github.com/aws/aws-sdk-php) の公開 API（`Aws\Signature\SignatureV4`、`Aws\Credentials\CredentialProvider`）に任せています。
HTTP 通信には [Guzzle 7](https://github.com/guzzle/guzzle) を使います。

## 動作要件

- PHP 8.3 以上（CI では 8.3 / 8.4 / 8.5 / 8.6 でテストしています）
- `aws/aws-sdk-php` ^3.402
- `guzzlehttp/guzzle` ^7.15.2

PHP 8.2 はセキュリティサポートが 2026-12-31 に終わるため、対象外にしました。
判断の詳細は [docs/design.md](docs/design.md) にあります。

## インストール

> **注意**: このパッケージはまだ Packagist に公開していないので、下記のコマンドは現時点では動きません。
> 公開するまでは、Composer の `repositories` に VCS リポジトリを追加して使ってください。

公開後は次のコマンドでインストールできる予定です。

```bash
composer require ixbox/lambda-web-client-php
```

公開前に試す場合の例です。

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ixbox/lambda-web-client-php" }
    ],
    "require": {
        "ixbox/lambda-web-client-php": "dev-main"
    }
}
```

## 使い方

### GET

```php
use Ixbox\LambdaWeb\Client;

$client = new Client(
    endpoint: $_ENV['WEB_FUNCTION_URL'], // 例: https://xxxx.xxx.lambda-web.ap-northeast-1.on.aws
    region: 'ap-northeast-1',
);

$response = $client->get('/jobs', [
    'query' => ['status' => 'pending'],
]);

echo $response->getStatusCode();
echo (string) $response->getBody();
```

### JSON POST

```php
$response = $client->post('/jobs', [
    'json' => ['type' => 'export'],
]);
```

戻り値は PSR-7 の `Psr\Http\Message\ResponseInterface` です。

### API

```php
$client->request(string $method, string $path = '', array $options = []): ResponseInterface;

$client->get($path, $options);
$client->post($path, $options);
$client->put($path, $options);
$client->patch($path, $options);
$client->delete($path, $options);
$client->head($path, $options);
$client->options($path, $options);
```

受け付けるリクエストオプションは次のとおりです。

| オプション | 型 | 説明 |
| --- | --- | --- |
| `query` | `array` または `string` | クエリ文字列。配列は `http_build_query`（RFC 3986）で組み立てます |
| `headers` | `array<string, string\|int\|float\|list<...>>` | アプリケーション用のヘッダー |
| `json` | `mixed` | JSON にエンコードして送信します（UTF-8、`/` と非 ASCII 文字はエスケープしません）。`Content-Type` を指定しなければ `application/json` を付けます |
| `body` | `string` | 本文を文字列のまま送信します。`json` とは同時に指定できません |
| `timeout` | `int\|float` | 全体のタイムアウト（秒）。クライアントの既定値を上書きします |
| `connect_timeout` | `int\|float` | 接続のタイムアウト（秒）。クライアントの既定値を上書きします |
| `http_errors` | `bool` | `true` にすると 4xx / 5xx を例外にします（既定は `false`）。クライアントの既定値を上書きします |

上記以外のオプション（`form_params`、`multipart`、`auth`、`allow_redirects`、`verify`、`sink`、`debug` など）は、`InvalidArgumentException` になります。
`request()` の `body` に渡せるのは文字列だけです。
ストリームを送りたい場合は、PSR-18 の `sendRequest()` を使ってください。

### PSR-18（`sendRequest()`）

`Client` は PSR-18 の `Psr\Http\Client\ClientInterface` を実装しています。
そのため、PSR-18 クライアントを受け取るライブラリ（OpenAPI から生成したクライアントなど）に、署名付きのクライアントとしてそのまま渡せます。

```php
use GuzzleHttp\Psr7\Request;

$request = new Request(
    'POST',
    'https://xxxx.xxx.lambda-web.ap-northeast-1.on.aws/jobs',
    ['Content-Type' => 'application/json'],
    '{"type":"export"}',
);

$response = $client->sendRequest($request);
```

`sendRequest()` は PSR-18 の決まりに従います。

- **4xx / 5xx は常にレスポンスとして返し、例外にしません。** クライアントの `httpErrors` 設定も関係しません。
- 例外は、送信できない場合（`InvalidRequestException`。PSR-18 の `RequestExceptionInterface`）と、レスポンスを受け取れなかった場合（`TransportException`。PSR-18 の `NetworkExceptionInterface`）に投げます。
- タイムアウトはクライアントの既定値を使います。

宛先を固定し、署名を壊さないために、次の条件を満たさないリクエストは `InvalidRequestException` になります。
この場合、何も送信しません。

- URI のスキーム、ホスト、ポートが endpoint と一致し、パスが endpoint のパスと同じかその下にあること。userinfo とフラグメントは不可です。`.` / `..` セグメントや空のセグメントも不可です。
- メソッドが GET / POST / PUT / PATCH / DELETE / HEAD / OPTIONS のいずれかであること（小文字は大文字に正規化します）。
- 本文が巻き戻せるストリームで、サイズが分かること。読み取り位置にかかわらず、ストリームの**先頭から全体**をハッシュして送信します。
- `Authorization`、`X-Amz-*`、`Date`、`Transfer-Encoding`、`Expect`、hop-by-hop ヘッダーを含まないこと。`Host` と `Content-Length` は、endpoint や本文サイズと一致する場合だけ受け付けます。`Host` がなければ自動で付けます。

### endpoint と path の結合

| endpoint | path | 送信先 |
| --- | --- | --- |
| `https://h` | `''` | `https://h/` |
| `https://h` | `/jobs` | `https://h/jobs` |
| `https://h/api/v1` | `/jobs` | `https://h/api/v1/jobs` |
| `https://h/api/v1/` | `/jobs` | `https://h/api/v1/jobs` |
| `https://h/api/v1/` | `''` | `https://h/api/v1/` |
| `https://h` | `/jobs?status=pending` | `https://h/jobs?status=pending` |

- path は `''` か、`/` で始まる文字列でなければなりません。
- スキームとホストは常に endpoint のものを使います。path に絶対 URL や `//example.com` を渡すとエラーになり、送信先は変わりません。
- `.` / `..` セグメント、`//`（空のセグメント）、`#` を含む path も拒否します。
- path にクエリ（`?`）を含めた場合、`query` オプションは同時に指定できません。
- endpoint は `https` の URL に限ります。userinfo、クエリ、フラグメントを含めることはできません。

## 認証情報

### 既定: SDK 標準のプロバイダーチェーン

`credentials` を指定しない場合は、`Aws\Credentials\CredentialProvider::defaultProvider()` を使います。
探索の順序は、環境変数 → Web Identity → SSO → `aws login` → `credential_process` → 共有 credentials / config ファイル → ECS または EC2 インスタンスプロファイル（IMDS）です。

**本番環境では IAM ロールの利用を基本にしてください。**
EC2 のインスタンスプロファイル、ECS / EKS のタスクロール、Lambda の実行ロールなどがこれにあたります。
アクセスキーをコードや設定ファイルに書き込む必要はありません。

認証情報はコンストラクターでは取得しません。
**リクエストのたびに**プロバイダーを呼び、キャッシュと更新は SDK の `memoize()` に任せます。
そのため、長時間動き続ける PHP プロセス（キューワーカーなど）でも、一時認証情報は期限前に自動で更新されます。
一時認証情報のセッショントークン（`X-Amz-Security-Token`）は、SDK の署名処理が付けます。

### プロバイダーを注入する

SDK の任意のプロバイダーを渡せます。
資格情報を直接取得する代わりに、毎回呼ばれるプロバイダーを渡す形です。

```php
use Aws\Credentials\CredentialProvider;
use Ixbox\LambdaWeb\Client;

// 例 1: 特定のプロファイル（~/.aws/credentials）を使う
$provider = CredentialProvider::memoize(CredentialProvider::ini('my-profile'));

// 例 2: 別アカウントのロールを AssumeRole する
$provider = CredentialProvider::memoize(CredentialProvider::assumeRole([
    'client' => new Aws\Sts\StsClient(['region' => 'ap-northeast-1']),
    'assume_role_params' => [
        'RoleArn' => 'arn:aws:iam::111122223333:role/InvokeWebFunction',
        'RoleSessionName' => 'my-app',
    ],
]));

$client = new Client(
    endpoint: $_ENV['WEB_FUNCTION_URL'],
    region: 'ap-northeast-1',
    credentials: $provider,
);
```

SDK のドキュメントにあるとおり、独自のプロバイダーは `CredentialProvider::memoize()` で包むことをおすすめします。
ただし、既定のプロバイダーは最初から memoize されています。

## タイムアウト

既定値は、全体 30 秒、接続 10 秒です。
クライアント単位でもリクエスト単位でも変更できます。
`0` を指定すると無制限になります（Guzzle と同じ意味）。

```php
$client = new Client(
    endpoint: $_ENV['WEB_FUNCTION_URL'],
    region: 'ap-northeast-1',
    timeout: 10.0,
    connectTimeout: 3.0,
);

$client->post('/reports', ['json' => $payload, 'timeout' => 60]);
```

## エラー処理

すべての例外は `Ixbox\LambdaWeb\Exception\LambdaWebException` を実装しています。

| 例外 | 発生条件 |
| --- | --- |
| `InvalidArgumentException` | 設定やオプションが不正なとき。何も送信しません |
| `CredentialsException` | 認証情報を取得できないとき。何も送信しません。元の例外は `getPrevious()` で取れます |
| `TransportException` | レスポンスを受け取れなかったとき（DNS、接続、TLS、タイムアウトなど）。元の Guzzle 例外は `getPrevious()` で取れます |
| `InvalidRequestException` | `sendRequest()` に渡したリクエストを安全に送れないとき。何も送信しません。`InvalidArgumentException` のサブクラスです |
| `HttpResponseException` | `http_errors` を明示的に有効にしていて、4xx / 5xx が返ったとき。`getResponse()` と `getStatusCode()` を使えます |

例外はすべて PSR-18 の `ClientExceptionInterface` も実装しています。

**既定では、4xx / 5xx は例外にせず、そのままレスポンスとして返します**（PSR-18 と同じ挙動です）。
ステータスコードは呼び出し側で確認してください。

```php
use Ixbox\LambdaWeb\Exception\LambdaWebException;
use Ixbox\LambdaWeb\Exception\TransportException;

try {
    $response = $client->get('/jobs');
    if ($response->getStatusCode() >= 400) {
        // 4xx / 5xx の処理
    }
} catch (TransportException $e) {
    // 接続できなかった、タイムアウトしたなど
} catch (LambdaWebException $e) {
    // 設定エラー、認証情報エラー
}
```

`request()` とショートカットでは、Guzzle と同じように 4xx / 5xx を例外にすることもできます（`sendRequest()` には適用されません）。

```php
use Ixbox\LambdaWeb\Exception\HttpResponseException;

$client = new Client(endpoint: $url, region: 'ap-northeast-1', httpErrors: true);
// または、リクエスト単位で指定する
try {
    $client->get('/jobs', ['http_errors' => true]);
} catch (HttpResponseException $e) {
    $status = $e->getStatusCode();
    $body = (string) $e->getResponse()->getBody();
}
```

**403 Forbidden が返っても、原因が署名とは限りません。**
次のどれでも 403 になりえます。

- `X-Amz-Content-SHA256` がない、または署名が不正
- 認証情報が期限切れ
- IAM ポリシーまたは resource-based policy に `lambda:InvokeWebFunctionEndpoint` の許可がない
- `region` がエンドポイントのリージョンと一致しない
- アプリケーション自身が 403 を返した

レスポンスの内容と IAM の設定を確認してください。

例外のメッセージには、メソッドとクエリを除いた URL だけを入れています。
認証情報、署名、クエリ、本文は入りません。
`getRequest()` が返すのは**署名前の**リクエストなので、`Authorization` と `X-Amz-Security-Token` は含まれません。

## リトライとリダイレクト

- **リトライはしません。** 必要な場合は、冪等性を考えたうえでアプリケーション側で実装してください。
- **リダイレクトは追跡しません。** 3xx はそのままレスポンスとして返します。署名付きのリクエストを別のオリジンに転送しないためです。
- TLS の証明書検証は常に有効で、無効にする手段はありません。

## IAM ポリシーの例

呼び出し元（IAM ロールなど）に付ける最小限の identity-based policy です。

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": "lambda:InvokeWebFunctionEndpoint",
            "Resource": "arn:aws:lambda:ap-northeast-1:111122223333:web-function/my-function/endpoint/prod"
        }
    ]
}
```

関数のすべてのエンドポイントを許可する場合は、`.../endpoint/*` と書きます。
クロスアカウントで呼ぶ場合は、Web Function 側の resource-based policy でも許可が必要です。
詳しくは [Lambda Web Function endpoint access control](https://docs.aws.amazon.com/lambda/latest/dg/web-functions-endpoint-access-control.html) を参照してください。

## 対応範囲

| 項目 | 対応 |
| --- | --- |
| `HomeRegion` エンドポイント（`*.lambda-web.<region>.on.aws`） | ✅ SigV4 |
| `PerRegion` エンドポイント（リージョンごとのドメイン） | ✅ SigV4（そのドメインのリージョンを指定する） |
| `MultiRegion` エンドポイント（`*.lambda-web.global.on.aws`） | ❌ SigV4a が必要なため非対応。指定するとエラーになります |
| 認証種別 `IamAuth` | ✅ |
| 認証種別 `ApplicationManaged` | 対象外（署名は不要です。普通の HTTP クライアントを使ってください） |
| SigV4a | ❌ |
| 同期リクエスト | ✅ |
| 非同期 API | ❌ |
| JSON / 文字列の本文 | ✅ |
| PSR-18（`sendRequest()`） | ✅ |
| ストリーム本文 | `sendRequest()` だけ（巻き戻せてサイズが分かるもの） |
| multipart、form_params | ❌ |
| 自動リトライ | ❌ |
| Lambda Invoke API、AWS リソースの作成・管理 | ❌（管理には SDK の `Aws\LambdaWeb\LambdaWebClient` を使ってください） |

`region` は必ず明示してください。
URL からリージョンを推測することはしません。
ただし、`*.lambda-web.<region>.on.aws` 形式のホストで `<region>` と `region` 引数が食い違う場合は、設定ミスとしてエラーにします。

### Lambda Function URL との違い

従来の Lambda Function URL（`https://<id>.lambda-url.<region>.on.aws`）は **Event Function** 用のエンドポイントです。
IAM アクションも `lambda:InvokeFunctionUrl` で、Web Functions とは別物です。
このライブラリは **Web Functions のエンドポイントだけ**を対象にしており、Function URL での動作はテストも保証もしていません。

### 署名とヘッダーの扱い

- 署名サービス名は `lambda`、リージョンは `region` 引数の値です。
- `X-Amz-Content-SHA256` には、実際に送るバイト列の SHA-256 を常に設定します。本文がない場合は空文字列のハッシュです。
- URI、ヘッダー、本文がすべて確定したあと、送信の直前に署名します。
- 次のヘッダーは指定するとエラーになります。
  - `Authorization`、`Host`、`Date`、`X-Amz-*`
  - `Content-Length`、`Transfer-Encoding`、`Expect`
  - hop-by-hop ヘッダー（`Connection` など）
- Lambda は検証後に `Authorization` ヘッダーを取り除きます。そのため、`IamAuth` のエンドポイントにはアプリケーション独自の `Authorization` ヘッダーを渡せません。

### 送信経路（ハンドラー）の注入

テストなどで、ネットワークを使わずに動かしたい場合は、Guzzle のハンドラーを渡せます。

```php
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

$client = new Client(
    endpoint: 'https://example.lambda-web.ap-northeast-1.on.aws',
    region: 'ap-northeast-1',
    credentials: $provider,
    handler: new MockHandler([new Response(200, [], '{"ok":true}')]),
);
```

ハンドラーは**署名済みの**リクエストを受け取ります。
受け取ったリクエストを変更すると署名が合わなくなるので、変更せずに送ってください。
Guzzle クライアントそのものは注入できません。
ミドルウェアの順序によって署名後にリクエストが変わる可能性があり、それを保証できないためです。

## 開発

```bash
composer install

composer test      # PHPUnit（ネットワークも AWS 認証情報も不要）
composer analyse   # PHPStan（level max）
composer cs        # PHP_CodeSniffer（PSR-12）
composer cs:fix    # 自動整形（phpcbf）
composer check     # validate + cs + analyse + test
```

テストでは固定のダミー認証情報と Guzzle の `MockHandler` を使っています。
AWS SDK の実際の署名処理を通したリクエストについて、トランスポートが受け取った内容から署名を再計算し、一致することを確かめています。

[examples/](examples/) のサンプルは、環境変数から endpoint と region を読み込みます。

```bash
WEB_FUNCTION_URL=https://xxxx.xxx.lambda-web.ap-northeast-1.on.aws \
AWS_REGION=ap-northeast-1 \
php examples/get.php
```

設計判断と未確認事項は [docs/design.md](docs/design.md) にまとめています。

## 参考にした AWS 公式ドキュメント

- [Lambda Web Function endpoint access control](https://docs.aws.amazon.com/lambda/latest/dg/web-functions-endpoint-access-control.html)
- [Lambda Web Function endpoints](https://docs.aws.amazon.com/lambda/latest/dg/web-functions-endpoints.html)
- [Announcing AWS Lambda Web Functions](https://aws.amazon.com/blogs/aws/announcing-aws-lambda-web-functions-for-fast-consistent-web-app-performance-anywhere/)
- [Create a signed AWS API request (SigV4)](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html)
- [Control access to Lambda function URLs](https://docs.aws.amazon.com/lambda/latest/dg/urls-auth.html)
- [AWS SDK for PHP: defaultProvider](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/defaultprovider-provider.html)
- [AWS SDK for PHP: Memoizing credentials](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/memoizing-credentials.html)

## ライセンス

未定です（LICENSE ファイルはまだありません）。
