# 設計メモ

このライブラリの初期バージョン（未リリース）の仕様確認結果と設計判断を記録する。
確認日は 2026-11-20。
AWS の仕様は変わりうるので、実装を変える前にリンク先を再確認すること。

## 1. 仕様確認の結果

### 1.1 確認した資料

| 資料 | URL |
| --- | --- |
| Lambda Web Function endpoint access control | https://docs.aws.amazon.com/lambda/latest/dg/web-functions-endpoint-access-control.html |
| Lambda Web Function endpoints | https://docs.aws.amazon.com/lambda/latest/dg/web-functions-endpoints.html |
| Announcing AWS Lambda Web Functions（AWS News Blog） | https://aws.amazon.com/blogs/aws/announcing-aws-lambda-web-functions-for-fast-consistent-web-app-performance-anywhere/ |
| Exploring AWS Lambda Web Functions（AWS Compute Blog） | https://aws.amazon.com/blogs/compute/exploring-aws-lambda-web-functions-for-building-web-applications/ |
| Creating and managing Lambda function URLs | https://docs.aws.amazon.com/lambda/latest/dg/urls-configuration.html |
| Control access to Lambda function URLs | https://docs.aws.amazon.com/lambda/latest/dg/urls-auth.html |
| Create a signed AWS API request（SigV4） | https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html |
| SDK for PHP: defaultProvider | https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/defaultprovider-provider.html |
| SDK for PHP: Built-in credential providers | https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/built-in-providers-in-the-sdk.html |
| SDK for PHP: Memoizing credentials | https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/memoizing-credentials.html |
| PHP Supported Versions | https://www.php.net/supported-versions.php |

SDK と Guzzle の挙動は、ドキュメントに加えて実際のソースを読んで確認した（aws/aws-sdk-php 3.406.0、guzzlehttp/guzzle 7.16.3）。

### 1.2 結論

**エンドポイントと IAM 認証**

- Web Function のエンドポイントは、Web Function の子リソースである。エンドポイントごとにドメインと `AuthType`（`IamAuth` または `ApplicationManaged`）を持つ。
- `IamAuth` では、呼び出し側が各リクエストに SigV4 で署名する。`MultiRegion` エンドポイントの場合は SigV4a で署名する。
- ドメインの形式は 2 つある。
  - `HomeRegion` / `PerRegion`: `domain-id.shard-id.lambda-web.<region>.on.aws`
  - `MultiRegion`: `domain-id.lambda-web.global.on.aws`
- カスタムドメインは直接付けられない。必要なら CloudFront を前段に置く。
- Lambda は署名を検証したあと、`Authorization` ヘッダーを取り除いてからアプリケーションに渡す。そのため、`IamAuth` を直接呼ぶ場合は、アプリケーション独自の `Authorization` ヘッダーを渡せない。

**従来の Lambda Function URL との違い**

| 項目 | Web Function エンドポイント | Function URL |
| --- | --- | --- |
| 対象 | Web Function（ポートで待ち受ける HTTP サーバー） | Event Function |
| ドメイン | `*.lambda-web.<region>.on.aws`、`*.lambda-web.global.on.aws` | `<url-id>.lambda-url.<region>.on.aws` |
| 認証種別 | `IamAuth` / `ApplicationManaged` | `AWS_IAM` / `NONE` |
| 呼び出しの IAM アクション | `lambda:InvokeWebFunctionEndpoint` | `lambda:InvokeFunctionUrl`（構成によっては `lambda:InvokeFunction` も必要） |
| リソース ARN | `arn:aws:lambda:<region>:<account>:web-function/<name>/endpoint/<endpoint>` | `arn:aws:lambda:<region>:<account>:function:<name>` |

このライブラリが対象とするのは Web Function エンドポイントだけである。
Function URL も SigV4 で技術的には呼べる可能性がある。ただし、テストもドキュメントもしていないので、対応範囲には含めない。

**SigV4 の signing service name**

- `lambda`（access control ページの "Signing requests" 節に明記）。
- 参考までに、SDK の管理 API モデル（`lambda-web` 2025-03-07）の `signingName` も `lambda` だった。

**エンドポイント種別ごとのリージョンと署名方式**

- `HomeRegion` / `PerRegion`: SigV4。リージョンにはエンドポイントのリージョンを使う。
- `MultiRegion`: SigV4a。リージョンセットは `*`。**このライブラリでは対象外とした。**

**X-Amz-Content-SHA256**

- 署名付きのリクエストにはすべてこのヘッダーが必要で、本文なしのリクエストも例外ではない。ない場合は `403 Forbidden` になる。
- Lambda は署名の検証時に本文を読まず、このヘッダーの値を payload hash として使う。本文とヘッダー値が一致するかどうかは検証しない。
- 本文に署名しない場合は `UNSIGNED-PAYLOAD` を指定できる。chunked signing はサポートされていない。
- 本文なしのリクエストでは、空文字列の SHA-256（`e3b0c442...b855`）を使う。これは SigV4 の一般規則に従ったものである。

**IAM アクションとリソース ARN**

- アクション: `lambda:InvokeWebFunctionEndpoint`
- リソース: `arn:aws:lambda:<region>:<account-id>:web-function/<function-name>/endpoint/<endpoint-name>`。すべてのエンドポイントを対象にするなら `.../endpoint/*` と書く。
- 同一アカウントからの呼び出しなら、identity-based policy だけで足りる。クロスアカウントの場合は、identity-based policy と Web Function の resource-based policy の両方が必要になる。

**一時認証情報のセッショントークン**

- SigV4 の一般規則では、`X-Amz-Security-Token` を付け、署名対象のヘッダーにも含める。
- SDK の `SignatureV4::signRequest()` は、資格情報がセッショントークンを持っていれば、このヘッダーを追加してから署名する。ライブラリ側では何もしない。
- 補足: Lambda Web Functions のドキュメントで `X-Amz-Security-Token` に触れているのは `ApplicationManaged` の節だけで、そこには「SigV4 署名と一緒に除去される」と書かれている。`IamAuth` で一時認証情報を使えるかどうかは明記されていないが、「unexpired credentials」という記述と、CloudFront OAC や IAM ロールでの利用が前提になっていることから、使えると判断した。実環境では検証していない（§4）。

**SDK の認証情報プロバイダー**

- プロバイダーは「引数なしで呼ぶと `PromiseInterface` を返し、その promise が `CredentialsInterface` で解決される callable」である。
- `CredentialProvider::defaultProvider()` は既定のチェーンをたどる。順序は env → web identity → SSO → login → process → ini → ECS または IMDS で、全体が `memoize()` されている。
- `memoize()` は取得した資格情報をキャッシュする。期限まで 60 秒（`REFRESH_WINDOW`）を切ったか期限切れになると、内側のプロバイダーを再度呼んで更新する。期限のない資格情報は定数として扱う。
- SDK のドキュメントでは、プロバイダーは API 呼び出しのたびに呼ばれる前提になっている。

**SDK の署名処理**

- `Aws\Signature\SignatureV4::signRequest(RequestInterface, CredentialsInterface)` は、署名済みの新しい PSR-7 リクエストを返す。
  - `X-Amz-Date` を付ける。既存の `Date`、`X-Amz-Date`、`Authorization` は取り除く。
  - リクエストに `X-Amz-Content-Sha256` があれば、その値を payload hash として使う。なければ本文を読んで SHA-256 を計算するが、**このヘッダーを自動では追加しない**（追加するのは `unsigned-body` 指定時の `UNSIGNED-PAYLOAD` だけ）。Lambda Web Functions はこのヘッダーを必須とするので、署名の前に呼び出し側で付ける必要がある。
  - 非 S3 サービス向けの規則に従い、パスは各セグメントを二重に URI エンコードして canonical URI を作る。
  - クエリは `Query::parse` でいったん分解し、`Query::build` で組み立て直して URI に戻す。そのため、送信されるクエリ文字列は正規化される（例: `+` は空白として解釈され、`%20` で送られる）。
  - 次のヘッダーは署名対象から除外される: `user-agent`、`accept`、`content-length`、`expect`、`authorization`、`cache-control`、`range`、`if-*`、`x-amzn-trace-id` など。それ以外は署名される。ここには hop-by-hop ヘッダーも含まれる。
- `Aws\LambdaWeb\LambdaWebClient` は Web Function を管理する API（CreateWebFunction など）のクライアントで、エンドポイントの呼び出しには使えない。Lambda にも「エンドポイント呼び出し用の専用 SDK はない」と書かれている。このライブラリは `LambdaWebClient` を使わない。

**Guzzle のリクエスト加工と送信の順序**

1. `Client::send()` → `applyOptions()`。`headers`、`body`、`json`、`query` などのオプションがここでリクエストに反映される。既定の `User-Agent` もここで付く。
2. HandlerStack のミドルウェアが外側から順に実行される。`push()` で追加したものほど内側に入り、ハンドラーに近くなる。
   - `HandlerStack::create()` の既定構成は `http_errors` → `allow_redirects` → `cookies` → `prepare_body` の順である。
   - `prepare_body` は `Content-Length`（または `Transfer-Encoding`）と、必要に応じて `Content-Type` / `Expect` を付ける。
3. ハンドラー（curl など）が送信する。curl ハンドラーは `Accept-Encoding` を付けることがある。

## 2. 設計判断

### 2.1 署名の位置と順序

- `Client` 内部で `HandlerStack` を自前で組み立てる。中身は `prepare_body` と署名ミドルウェアだけである。署名ミドルウェアは最後に `push()` するので、最も内側、つまりハンドラーの直前で実行される。
- URI、ヘッダー、本文は `Client` が PSR-7 リクエストとして組み立てる。Guzzle の `body` / `json` / `query` / `headers` オプションは使わない。Guzzle に渡すのはタイムアウトなどのトランスポート用オプションだけである。
- 署名ミドルウェアがリクエストごとに行う処理は次のとおり。
  1. 実際の本文ストリームから SHA-256 を計算し、`X-Amz-Content-Sha256` に設定する（`GuzzleHttp\Psr7\Utils::hash`）。
  2. 認証情報プロバイダーを呼ぶ。
  3. `SignatureV4('lambda', $region)->signRequest()` で署名する。
  4. ハンドラーに渡す。
- 署名後にリクエストを変えうるのは、利用者が注入したハンドラーだけである（§2.5）。curl が付ける `Accept-Encoding` などは署名対象外のヘッダーなので、署名には影響しない。
- テストでは、トランスポートが受け取った署名済みリクエストから署名を再計算して一致を確認している。tests/Support/SigV4Verifier.php は IAM ドキュメントのアルゴリズムに従って書いたテスト専用のコードである。

### 2.2 本文ハッシュ

- 本文は常に送信バイト列そのものからハッシュする。`json` の場合は、エンコードした文字列がそのまま送られる。
- `UNSIGNED-PAYLOAD` は初期版では使わない。受け付ける本文が文字列だけなので、常にハッシュを計算できるからである。
- 「ハッシュの準備」は PHP 標準の `hash('sha256')` を使う。署名アルゴリズムそのもの（canonical request の組み立て、署名鍵の導出、HMAC）は SDK に任せる。

### 2.3 認証情報

- コンストラクターで受け取るのはプロバイダー（callable）である。資格情報そのものは受け取らない。未指定時は `CredentialProvider::defaultProvider()` を 1 回だけ生成して保持する。
- プロバイダーは**リクエストごとに**呼ぶ。キャッシュと更新は SDK の `memoize()` に任せる。コンストラクターでは呼ばないので、長時間動くプロセスでも、一時認証情報の更新が止まることはない。
- `CredentialsInterface` を直接受け取る API は用意しない。アクセスキーのハードコードを誘発しないためである。テストなどで必要な場合は、SDK の `CredentialProvider::fromCredentials()` を使えばよい。

### 2.4 例外

| 例外 | 発生条件 | 備考 |
| --- | --- | --- |
| `Exception\InvalidArgumentException` | 設定やオプションが不正 | 何も送信しない |
| `Exception\InvalidRequestException` | `sendRequest()` に渡したリクエストを安全に送れない | 何も送信しない。`InvalidArgumentException` のサブクラスで、PSR-18 の `RequestExceptionInterface` を実装する |
| `Exception\CredentialsException` | プロバイダーが失敗した | 何も送信しない。元の例外は `getPrevious()` で取れる |
| `Exception\TransportException` | レスポンスを受け取れなかった（接続、TLS、タイムアウトなど） | 元の Guzzle 例外を `getPrevious()` で取れる。PSR-18 の `NetworkExceptionInterface` を実装する |
| `Exception\HttpResponseException` | 4xx / 5xx（`request()` 系で `http_errors` を明示的に有効にした場合だけ） | `getResponse()` で取れる。名前とメッセージで署名エラーとは断定しない |

- すべて `Exception\LambdaWebException` を実装し、`LambdaWebException` は PSR-18 の `ClientExceptionInterface` を継承する。
- メッセージに含めるのは、メソッドとクエリを除いた URL だけである。認証情報、署名、クエリ、本文は含めない。
- curl ハンドラーの例外は、ハンドラーが受け取った**署名済み**のリクエストを抱えている。そのため署名ミドルウェアは、`ConnectException` / `RequestException` を**署名前のリクエスト**で作り直してから上に返す。メッセージ、previous、handler context はそのまま引き継ぐ。こうすると、例外をダンプしても `Authorization` や `X-Amz-Security-Token` が出ない。
- `getRequest()` が返すのも署名前のリクエストである。
- `http_errors` の既定値は `false` である。PSR-18 に合わせ、4xx / 5xx は正常なレスポンスとして返す。`request()` 系に限り、クライアント単位またはリクエスト単位で `true` にできる。`sendRequest()` は PSR-18 の決まりにより常に例外にしない。3xx はどちらでも例外にせず、そのまま返す。
- 当初は Guzzle の既定に合わせて `true` にしていたが、PSR-18 準拠を優先して変更した（2026-11-20）。

### 2.5 送信経路の注入

- 注入できるのは Guzzle の**ハンドラー**（終端のトランスポート）だけで、Guzzle クライアントは注入できない。任意のクライアントを受け取ると、ミドルウェアの順序やオプションによって署名後にリクエストが変わる可能性があり、それを保証できないからである。
- ハンドラーは署名済みのリクエストを受け取り、変えずに送る責任を負う。この前提は PHPDoc と README に明記した。
- 主な用途はテスト（`MockHandler`）と、curl オプションを変えたハンドラーの差し替えである。

### 2.6 URI の規則

- endpoint には次の条件を課す。
  - `https` の絶対 URL で、ホストがあること。
  - userinfo、クエリ、フラグメントは不可。前後の空白も不可。
  - パスを含めてもよく、その場合はベースパスになる。ただし、ドットセグメントと空セグメントは不可。
- `*.lambda-web.global.on.aws`（MultiRegion）は拒否する。
- `*.lambda-web.<region>.on.aws` の形のホストは、`<region>` が `region` 引数と一致することを確認する。これは設定ミスを見つけるための検証で、URL からリージョンを推測するわけではない。それ以外のホストはこの検査をしない。
- path の規則は次のとおり。
  - `''` の場合は endpoint のパスをそのまま使う。endpoint にパスがなければ `/` になる。
  - それ以外は `/` で始まる必要があり、`{ベースパス（末尾の / を除く）}{path}` になる。
  - `//host`、絶対 URL、`/` で始まらない相対パス、`#`、`.` / `..` セグメント（`%2e` を含む）、`//`（空セグメント）は拒否する。
  - スキームとホストは常に endpoint のものを使い、path は `withPath()` でしか適用しない。そのため、path から宛先が変わることはない。
  - path に `?` を含めてもよく、その部分はクエリになる。この場合、`query` オプションと同時には指定できない。
  - エンコード済みの `%XX` は保持し、エンコードされていない文字は PSR-7 の `Uri` がエンコードする。
- curl はドットセグメントを畳み込むので、署名したパスと実際に送られるパスがずれるおそれがある。これがドットセグメントを拒否する理由である。空セグメントについては、サーバー側の正規化の挙動が分からないので、初期版では拒否した。

### 2.7 ヘッダーの規則

次のヘッダーは利用者が指定すると `InvalidArgumentException` になる。

- 署名が管理するもの: `Authorization`、`Host`、`Date`、`X-Amz-*`（すべて）
- 本文から計算するもの: `Content-Length`、`Transfer-Encoding`、`Expect`
- hop-by-hop: `Connection`、`Keep-Alive`、`TE`、`Trailer`、`Upgrade`、`Proxy-Authorization`、`Proxy-Authenticate`

大文字小文字だけが違う同名ヘッダーの重複も拒否する。
`Content-Type` を含め、それ以外のヘッダーは送信され、SDK の除外リストにないものは署名対象になる。

### 2.8 本文とオプション

- `json` と `body` を同時に指定するとエラーにする。
- `json` は `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION` でエンコードする。`Content-Type` が未指定なら `application/json` を付ける。
- `request()` の `body` は文字列だけを受け付ける。ストリーム、リソース、配列は明示的に拒否する。ストリームは `sendRequest()` でだけ受け付ける（§2.12）。
- 受け付けるオプションは `query`、`headers`、`json`、`body`、`timeout`、`connect_timeout`、`http_errors` だけである。`form_params`、`multipart`、`auth`、`allow_redirects`、`verify`、`sink`、`debug` などは拒否する。
- HTTP メソッドは GET / POST / PUT / PATCH / DELETE / HEAD / OPTIONS に限る。

### 2.9 安全性に関する既定値

- TLS 検証は常に有効（`verify => true`、変更不可）。
- リダイレクトは追跡しない。redirect ミドルウェアを積まず、`allow_redirects => false` も指定する。3xx はそのまま返す。
- リトライはしない。
- 既定のタイムアウトは、全体 30 秒、接続 10 秒。どちらもクライアント単位とリクエスト単位で変更できる。`0` を指定すると無制限になる（Guzzle の意味論）。
- `Expect: 100-continue` は付けない（`expect => false`）。
- ライブラリはログを出さず、標準出力にも何も書かない。

### 2.10 対応 PHP バージョンと依存関係

- `php: ^8.3`
  - aws/aws-sdk-php の最新版（3.402 以降）は `php >= 8.2` を要求する。
  - しかし PHP 8.2 のセキュリティサポートは 2026-12-31 に終わるので、新しく作るライブラリの下限には採らなかった。8.3 のセキュリティサポートは 2027-12-31 まで、8.4 は 2028-12-31 まで続く。
  - PHPUnit 12 も PHP 8.3 以上を要求する。
- `aws/aws-sdk-php: ^3.402`: 本ライブラリが使う API（`SignatureV4`、`CredentialProvider`）は以前からあるが、下限は PHP 8.2 必須化後のリリースに合わせた。
- `guzzlehttp/guzzle: ^7.15.2`: 要件で Guzzle 7 と指定されている。7.15.2 未満は Packagist のセキュリティアドバイザリにより Composer がインストールを拒否するので、実際に解決できる最小版を下限にした。Guzzle 8 への対応は将来の課題とする。
- `guzzlehttp/psr7: ^2.13` と `guzzlehttp/promises: ^2.5` は直接使っているので明記した。下限は `--prefer-lowest` で解決された版である。
- 開発ツールは PHPUnit 12、PHPStan 2（level max）、PHP_CodeSniffer 4（PSR-12）とした。フォーマッターは phpcbf だけにした。

### 2.11 公開 API の範囲

公開 API は `Ixbox\LambdaWeb\Client`（PSR-18 の `ClientInterface` を含む）と `Ixbox\LambdaWeb\Exception\*` だけである。
`Ixbox\LambdaWeb\Internal\*` は `@internal` で、互換性を保証しない。

### 2.12 PSR-18（`sendRequest()`）

`Client` は `Psr\Http\Client\ClientInterface` を実装する。
PSR-18 を受け取るライブラリ（OpenAPI 生成クライアントなど）に、署名付きクライアントとして差し込めるようにするためである。

PSR-18 のリクエストは呼び出し側が組み立てた完全な PSR-7 リクエストなので、`request()` 系と同じ安全性を保つため、送信前に次を検証する。
違反は `InvalidRequestException`（元の `InvalidArgumentException` を previous に持つ）で、何も送信しない。

- **宛先**: スキーム、ホスト、ポートが endpoint と一致すること。パスは endpoint のパスと同じかその下であること（`/api` に対して `/apix` は不可）。userinfo、フラグメント、ドットセグメント、空セグメントは不可。
- **メソッド**: 対応する 7 種のみ。小文字は大文字に正規化する（PSR-18 はクライアントによるリクエストの変更を認めている）。
- **本文**: 巻き戻せて、サイズが分かるストリームに限る。`GuzzleHttp\Psr7\Utils::hash` は先頭から全体をハッシュして読み取り位置を戻し、curl ハンドラーも本文を先頭から送る（文字列化するか、明示的に `rewind()` する）。そのため、ハッシュと送信内容は一致する。サイズ不明のストリームは、`prepare_body` が `Transfer-Encoding: chunked` を付けることになるので拒否した。
- **ヘッダー**: `request()` 系と同じ予約ヘッダーを拒否する。ただし PSR-7 のリクエストには通常 `Host` が入っているので、`Host` は endpoint の authority と一致する場合だけ受け付け、なければ付ける。`Content-Length` は本文サイズと一致する場合だけ受け付ける。
- **タイムアウト**: PSR-18 にはリクエスト単位の指定手段がないので、クライアントの既定値を使う。
- 4xx / 5xx は常にレスポンスとして返す。リダイレクトは追跡しない（PSR-18 は追跡を要求していない）。

`psr/http-client` を直接の依存に加えた（`^1.0`）。

### 2.13 コーディングスタイル

PSR-12 に従い、PHP_CodeSniffer の `PSR12` 標準で検査する。
行長（120 文字のソフトリミット）は src と examples で検査し、超過すると CI が失敗する。
tests だけは、データプロバイダーの行や長いアサーションメッセージを 1 行で書けるように除外している。

## 3. 合理的な仮定

- 一時認証情報の扱いは SigV4 の一般規則どおり（`X-Amz-Security-Token` を署名対象に含める）で受け付けられる、と仮定した。
- 本文なしのリクエストの `X-Amz-Content-SHA256` は、空文字列のハッシュで受け付けられる、と仮定した。ドキュメントには「本文なしでもヘッダーが必須」としか書かれておらず、値は SigV4 の一般規則に従った。
- `Content-Type` などのアプリケーション用ヘッダーを署名対象に含めても、Lambda 側の検証で問題にならない、と仮定した。SigV4 では SignedHeaders に挙げたヘッダーを検証するのが通常である。

## 4. 未確認事項

実際の AWS 環境での呼び出しはしていない。以下は公式ドキュメントで確認できなかった点、または実環境でしか確かめられない点である。

1. **パスの二重エンコード**: SDK は非 S3 サービス向けの規則でパスを二重にエンコードして署名する。Lambda Web Function のエンドポイントが同じ規則で検証するかどうかは、ドキュメントに記載がない。`%XX` を含むパス（日本語、空白、`%2F` など）で署名が一致するかは、実環境での確認が必要である。
2. **一時認証情報（`X-Amz-Security-Token`）**: `IamAuth` エンドポイントの節には明記がない（§1.2 の補足を参照）。
3. **クエリの正規化**: SDK はクエリを分解して組み立て直す。重複キー、値のないキー、`+` を含む値をサーバーがどう解釈するかは、アプリケーションのフレームワークに依存する。
4. **HTTP/2 と `:authority`**: Guzzle と curl が HTTP/1.1 で送ることを前提にしている（Host ヘッダーで署名する）。
5. **Lambda 側の 403 / 429 / 5xx のレスポンス本文の形式**: ドキュメントに記載がないので、本文の解析はしない。
6. **Function URL**（`*.lambda-url.*`）を同じ方式で呼べるかどうかは検証していない。対応範囲外とする。

## 5. 将来の拡張候補（初期版の対象外）

- SigV4a と MultiRegion エンドポイント（SDK の SigV4a は `aws-crt-php` 拡張を必要とする）
- `request()` でのストリーム本文と `UNSIGNED-PAYLOAD`
- 非同期 API
- オプトインのリトライ
- Guzzle 8 への対応
