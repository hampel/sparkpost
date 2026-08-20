# SparkPost API client for PHP

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for the [SparkPost API](https://developers.sparkpost.com/api/), built on
**PSR-18** so it works with whatever HTTP client your application already has.

## Installation

```bash
composer require hampel/sparkpost
```

You also need a PSR-18 client and a PSR-17 factory. Guzzle 7 provides both:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;

$guzzle  = new Client();
$factory = new HttpFactory();   // PSR-17, fills both the request and stream roles

$sparkpost = new SparkPost(new Config('MY-API-KEY'), $guzzle, $factory, $factory);

$result = $sparkpost->transmissions()->send([
    'recipients' => [
        ['address' => ['email' => 'me@example.com']],
    ],
    'content' => [
        'from'    => ['email' => 'webmaster@example.com'],
        'subject' => 'Hello',
        'text'    => 'Hello from SparkPost.',
    ],
]);
```

For the EU tenancy, or any other region:

```php
$sparkpost = new SparkPost(Config::forRegion('MY-API-KEY', 'eu'), $guzzle, $factory, $factory);
```

A PSR-3 logger is optional and takes a fifth argument. Requests are logged at `debug`,
failures at `error`; attachment payloads are truncated before they reach the log.

## HTTP 200 does not mean the mail was sent

SparkPost returns `200` having accepted zero recipients, so the status code alone will tell
you a send succeeded when nothing left the building. That is what `TransmissionResult` is
for:

```php
if (! $result->wasAccepted()) {
    // nothing was sent, whatever the status code said
}

$result->id;                        // the transmission id
$result->totalAcceptedRecipients;
$result->totalRejectedRecipients;
$result->hasRejections();
```

Rejected recipients are reported rather than thrown, because what counts as a failure is
your policy: a mail transport should treat `wasAccepted() === false` as a failed send, a
bulk job may not.

## Errors

Everything this package throws implements `Hampel\SparkPost\Exception\ExceptionInterface`,
so one clause catches the lot. Below that, the distinctions are the ones you would actually
branch on:

| Exception | When | Retry? |
|---|---|---|
| `RequestException` | The request never reached SparkPost — DNS, TLS, connect timeout | Yes, and it cannot have duplicated anything |
| `ClientException` | A 4xx. The request or the key was wrong | Not unchanged |
| `RateLimitException` | A 429, with `$retryAfter` when the header was sent | Yes, after waiting |
| `ServerException` | A 5xx. SparkPost's problem, probably temporary | Yes |
| `InvalidArgumentException` | Caught before the network — empty key, unencodable payload | No |

`ClientException`, `RateLimitException` and `ServerException` all carry `$statusCode`,
the decoded `$errors` array, the raw `$body`, and `$retryAfter`.

```php
use Hampel\SparkPost\Exception\RateLimitException;
use Hampel\SparkPost\Exception\ExceptionInterface;

try {
    $sparkpost->transmissions()->send($transmission);
} catch (RateLimitException $e) {
    $queue->release($e->retryAfter ?? 60);
} catch (ExceptionInterface $e) {
    $log->error($e->getMessage());
}
```

## Bringing your own HTTP client

Any PSR-18 client works, which matters when the host has its own HTTP stack that you are
not free to bypass. XenForo, for example, routes outbound requests through a configurable
proxy with SSRF protections; an adapter implementing `sendRequest()` over
`XF\Http\Reader::requestUntrusted()` keeps all of that and still shares this package.

The same seam is what makes the test suite network-free — see `tests/StubClient.php`.

## Endpoints not yet wrapped

`$sparkpost->connection()` exposes `get()` and `post()` directly, so an endpoint this
package has not covered yet is a call away rather than a release away:

```php
$body = $sparkpost->connection()->get('suppression-list', ['limit' => 50]);
```

Paths may be given bare (`transmissions`), with a leading slash, or exactly as SparkPost
returns them in pagination links (`/api/v1/events/message?page=2`) — the version prefix is
handled either way.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
