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
use Hampel\SparkPost\Transmission\Transmission;

$guzzle  = new Client();
$factory = new HttpFactory();   // PSR-17, fills both the request and stream roles

$sparkpost = new SparkPost(new Config('MY-API-KEY'), $guzzle, $factory, $factory);

$result = $sparkpost->transmissions()->send(
    Transmission::make()
        ->from('webmaster@example.com', 'Webmaster')
        ->subject('Hello')
        ->text('Hello from SparkPost.')
        ->to('me@example.com', 'Me')
);
```

For the EU tenancy, or any other region:

```php
$sparkpost = new SparkPost(Config::forRegion('MY-API-KEY', 'eu'), $guzzle, $factory, $factory);
```

A PSR-3 logger is optional and takes a fifth argument. Requests are logged at `debug`,
failures at `error`; attachment payloads are truncated before they reach the log.

## Building a transmission

`send()` takes a `Transmission`, or a plain array if you would rather build the payload
yourself.

```php
use Hampel\SparkPost\Transmission\Attachment;
use Hampel\SparkPost\Transmission\Transmission;

$transmission = Transmission::make()
    ->from('webmaster@example.com', 'Webmaster')
    ->subject('Your invoice')
    ->html('<p>Attached. Also see <img src="cid:logo"></p>')
    ->text('Attached.')
    ->to('alice@example.com', 'Alice')
    ->cc('accounts@example.com', 'Accounts')
    ->bcc('archive@example.com')
    ->replyTo('billing@example.com')
    ->header('X-Campaign', 'invoices')
    ->attach(Attachment::fromPath('/tmp/invoice.pdf', 'invoice.pdf', 'application/pdf'))
    ->attach(Attachment::inline('logo', 'image/png', $logoBytes))
    ->transactional()
    ->openTracking(false)
    ->campaignId('invoices')
    ->metadata(['user_id' => 7])
    ->substitutionData(['first_name' => 'Alice']);
```

Most of that is obvious. These parts are not, and are the reason the builder exists:

- **SparkPost sends one message per recipient**, so without a `header_to` every recipient
  sees a `To:` line containing only themselves. The builder sets it on every recipient
  from your `to()` list, which is what reproduces ordinary mail.
- **Cc is made visible by a `CC` header**, not by the recipient list — the recipients are
  how the mail is addressed, the header is how it is displayed. Bcc gets no header, which
  is what makes it blind.
- **A dozen headers are rejected** if you pass them in `content.headers`, because
  SparkPost derives them from the transmission itself. `header()` drops those rather than
  letting the API reject the whole send.
- **`false` survives.** An option set to `false` is sent as `false`, not dropped as empty
  — `openTracking(false)` means "do not track opens", not "use the account default".
- **Mail from `@sparkpostbox.com`** switches the `sandbox` option on by itself, because
  that domain silently fails without it. `sandbox(false)` overrides.

Stored templates and A/B tests replace the content entirely:

```php
Transmission::make()->to('alice@example.com')->template('welcome');
Transmission::make()->to('alice@example.com')->abTest('subject-line');
```

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
