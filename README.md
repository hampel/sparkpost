# SparkPost API client for PHP

[![Tests](https://github.com/hampel/sparkpost/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/sparkpost/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/sparkpost.svg?style=flat-square)](https://packagist.org/packages/hampel/sparkpost)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/sparkpost.svg?style=flat-square)](https://packagist.org/packages/hampel/sparkpost)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/sparkpost.svg?style=flat-square)](https://github.com/hampel/sparkpost/issues)
[![License](https://img.shields.io/packagist/l/hampel/sparkpost.svg?style=flat-square)](https://packagist.org/packages/hampel/sparkpost)

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for the [SparkPost API](https://developers.sparkpost.com/api/), built on
**PSR-18** so it works with whatever HTTP client your application already has.

## Installation

```bash
composer require hampel/sparkpost
```

You also need a PSR-18 client and a PSR-17 factory. Guzzle provides both, 7 or 8:

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

## Message events

```php
use Hampel\SparkPost\MessageEvent\EventQuery;
use Hampel\SparkPost\MessageEvent\EventType;

$query = EventQuery::make()
    ->events(EventType::Bounce, EventType::SpamComplaint, EventType::ListUnsubscribe)
    ->from(new DateTimeImmutable('-1 day'))
    ->to(new DateTimeImmutable())
    ->perPage(100);

foreach ($sparkpost->messageEvents()->each($query) as $event) {
    // one event at a time, across as many pages as it takes
}
```

`each()` is lazy — a page is only fetched once the previous one has been consumed, so
stopping early stops making requests. `from` and `to` are converted to UTC, which is what
the API assumes when no timezone is given.

### Picking the work up again later

There are usually more events than one request has time to collect, so the position in a
search is a **cursor that casts to a string**. Store it wherever a string can go, and
resume in the next run:

```php
$page = $sparkpost->messageEvents()->search($query);

process($page->results);

if ($page->hasMore()) {
    $job->data['cursor'] = (string) $page->next();   // and stop here
}
```

```php
// ... a request, a job, or an hour later
$page = $sparkpost->messageEvents()->next(EventCursor::fromString($job->data['cursor']));
```

A page reports `$page->totalCount`, counts, and iterates. Events themselves are plain
arrays: their shape varies a great deal by event type, and pinning it down would mean
either a type that hides most of the payload or twenty of them.

### Bounce classes

SparkPost reports why a message bounced as a numeric class. The codes and their meanings
are SparkPost's; what to *do* about each one is your policy:

```php
use Hampel\SparkPost\MessageEvent\BounceClass;
use Hampel\SparkPost\MessageEvent\BounceClassification;

// note the cast: SparkPost sends bounce_class as a string, and may add codes later
$class = BounceClass::tryFrom((int) ($event['bounce_class'] ?? 0));

$class?->classification();                 // Hard, Soft, Block, Admin, Informational, Undetermined
$class?->classification()->isPermanent();  // whether to stop sending to this address
$class?->slug();                           // 'invalid_recipient'
```

**Not everything on the bounce channel is a bounce**, and this is the one thing worth
reading before you write the `match`. Two classes describe a message that *arrived* and
drew a reply: `auto_reply` (60), and `subscribe` (80), which is someone opting back in.
Both are `Informational`:

```php
match ($class?->classification()) {
    BounceClassification::Hard  => $this->stopSending($user),
    BounceClassification::Block,
    BounceClassification::Admin => $this->investigate($user),
    BounceClassification::Soft  => $this->countTowardsRetries($user),

    // delivered, and answered - a resubscribe is good news, not a failure
    BounceClassification::Informational => $this->note($event),

    default => null,
};
```

`Informational` is this package's grouping rather than SparkPost's own: their table files
60 under soft and 80 under admin, which describes the SMTP exchange accurately and sends
the obvious `match` arm off to punish a user who has just opted in.

## Suppression

Two questions, and the API answers both awkwardly enough to be worth wrapping.

```php
$suppression = $sparkpost->suppression();

if ($suppression->isSuppressed('someone@example.com')) {
    // SparkPost is dropping mail to this address
}

$entry = $suppression->find('someone@example.com');

$entry?->source;        // 'Bounce Rule', 'Spam Complaint', 'Manually Added', ...
$entry?->description;   // the remote server's own words: "550-5.1.1 The email account ..."
$entry?->created;       // DateTimeImmutable
$entry?->transactional;
```

**`find()` returns `null` for an address that is not suppressed**, which is worth stating
because the API does not: SparkPost answers 404, so "this address is fine" arrives as an
error. Only the 404 is translated — a 401 from a bad key is still thrown, rather than
quietly reporting every address as clear.

Removing an entry lets SparkPost attempt delivery again:

```php
$suppression->delete('someone@example.com');   // false if it was not on the list
```

That is the operation worth having. SparkPost suppresses on a hard bounce by itself, and
its list and your own idea of who may be emailed then drift apart — so an address you have
re-enabled at your end can still be silently dropped at SparkPost's. Nothing in the sending
path reports it, because it is not an error.

Reading the whole list, a page at a time:

```php
foreach ($suppression->each() as $entry) {
    // lazy - stop early and it stops making requests
}

$page = $suppression->search(page: 1, perPage: 100);
$page->totalCount;
$page->hasMore;
```

**The list you see is the one your API key can see.** Suppression is scoped per subaccount,
and a subaccount key is bound to its own automatically — so an address suppressed under a
different subaccount simply is not there as far as that key is concerned. To work with a
particular subaccount's list, use a key belonging to it. `sendingDomains()->forAddress()`
above is how to find out which subaccount an address you send from belongs to.

Adding one is a `PUT`, and therefore an upsert — an address already listed has its entry
replaced rather than the call being rejected:

```php
$suppression->add('someone@example.com', 'asked us to stop');
$suppression->add('someone@example.com', transactional: false);
```

**The list is eventually consistent**, which is worth knowing before you write anything
around it. Measured against the live API: an added address took about six seconds to become
readable, and a deleted one stayed readable about as long after the delete succeeded. `add()`
returning true means SparkPost accepted the write, not that `isSuppressed()` agrees yet.
Nothing here retries for you — poll if you need to see the change.

Whether an unsubscribe in your application should also go on SparkPost's list is a policy
question, and it is yours rather than this package's.

## Sending domains, and finding the subaccount

Read-only. Creating and verifying a sending domain is an administration task done once in
SparkPost's own UI, and a key that can write here can change where an account's mail appears
to come from — so this only reads, and the key only needs the read grant.

```php
foreach ($sparkpost->sendingDomains()->all() as $domain) {
    $domain->domain;
    $domain->subaccountId;
    $domain->isDefaultBounceDomain;
    $domain->status['ownership_verified'] ?? null;
}
```

The reason it is here is `subaccountId`. **Suppression is per-subaccount**, and a subaccount
API key cannot read the subaccounts endpoint at all — SparkPost offers no such permission
for one. The sending domain carries the number, so the address you send as is the way to
find out which subaccount you are operating in:

```php
$domain = $sparkpost->sendingDomains()->forAddress('Support <noreply@mail.example.com>');

$domain?->subaccountId;      // 3629
$domain?->hasSubaccount();   // false for the primary account, which is 0 or absent
```

`forAddress()` reads the display-name form as well as a bare address, and returns `null`
without making a request when it is handed something that is not an address at all.
`find($domain)` returns `null` for a domain the account does not have — which is also the
reason a send from it would be refused.

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

Any PSR-18 client works, which matters when the host application has its own HTTP stack
that you are not free to bypass — one routing every outbound request through a configurable
proxy, with SSRF protections applied on the way out. An adapter implementing `sendRequest()`
over that keeps all of it and still shares this package, rather than forcing a second API
client to be written alongside.

The same seam is what makes the test suite network-free — see `tests/StubClient.php`.

## Endpoints not yet wrapped

`$sparkpost->connection()` exposes `get()` and `post()` directly, so an endpoint this
package has not covered yet is a call away rather than a release away:

```php
$body = $sparkpost->connection()->get('webhooks');
```

Paths may be given bare (`transmissions`), with a leading slash, or exactly as SparkPost
returns them in pagination links (`/api/v1/events/message?page=2`) — the version prefix is
handled either way.

## Versioning and support

Semantic versioning. `1.0.0` declares the public API stable, so the constraint to write is:

```json
"hampel/sparkpost": "^1.0"
```

That is `>=1.0.0 <2.0.0`. **Write `^1.0`, not `~1.0.0`** — the tilde means `>=1.0.0 <1.1.0`,
which pins you to patch releases and quietly withholds every additive one. The two look
interchangeable and are not.

* **PHP 8.3 or later.** Tested against 8.3 (including at the lowest resolvable dependency
  set) and 8.5.
* **1.x is supported.** Fixes land on the current minor.
* **0.x is not, and there is nothing to weigh up.** `src/` is byte-identical between 0.4.0
  and 1.0.0, so upgrading from 0.4.0 is a constraint edit with no code change behind it.
  Before that, every 0.x minor was a breaking boundary to Composer, which is the tax `^1.0`
  removes.

### What "stable" covers, and the one place it deliberately does not

A breaking change to a class, method or method signature in `src/` means `2.0.0`.

**`BounceClass` and `EventType` are the exception, and it is a deliberate one.** They mirror
SparkPost's own taxonomy, which this package does not control — if SparkPost issues a new
bounce code, a new case appears here **in a minor release**. So:

**every `match` over one of these enums needs a `default` arm.** That is not defensive
padding, it is the supported way to use them — see the example under
[Bounce classes](#bounce-classes), which has one for exactly this reason. Without it, a new
SparkPost code is a fatal `UnhandledMatchError`.

This is not theoretical. Adding `BounceClassification::Informational` in 0.4.0 turned an
exhaustive `match` in a real application into a runtime fatal, on a vacation auto-reply, in a
background job — where the stack trace points at the consumer's code and says nothing about
an upgrade.

What *does* mean a major: **changing which classification an existing code maps to.** That is
a silent behaviour change rather than a loud one, so it gets the loud version number. 0.4.0
moved `AutoReply` (60) and `Subscribe` (80), and was released as a breaking change for that
reason as much as for the new case.

### Decoded SparkPost data: the container is stable, the contents are not

The same split, in the other place SparkPost's own data crosses into this package's public
API — `ApiException::$errors`, `ApiException::$body`, and the event arrays from
`messageEvents()`.

**Stable, and covered by the major version:**

* `$errors` exists on every `ApiException` subclass, is `public readonly`, and is always a
  list of arrays — `[]` when the response carried no `errors` key or was not JSON at all
  (a proxy answering with HTML is an expected input here, not an edge case). It is never
  `null`, so `foreach` over it needs no guard.
* likewise `$statusCode` (`int`), `$body` (`string`, the raw response) and `$retryAfter`
  (`?int`).

**Promised by nobody — not by this package, and not to it:** the keys *inside* each error,
and the shape of an event. Those are SparkPost's payload, passed through with no reshaping
beyond dropping non-array entries. If SparkPost renames a field inside `errors[]`, this
package will not notice and will not issue a major for it, and no version number anywhere
will have moved. Read them with `??`, as below.

```php
// safe: guard on the type, read the property, treat what is inside as untrusted
if ($previous instanceof ApiException) {
    foreach ($previous->errors as $error) {
        $log->warning($error['message'] ?? 'SparkPost gave no message', $error);
    }
}
```

The rule of thumb across all three carve-outs is the same one: **this package promises the
shape it built and does not promise the shape SparkPost sent.** Where those meet — an enum
of SparkPost's codes, an array of SparkPost's errors — the container is ours and the contents
are theirs.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
