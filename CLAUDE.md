# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/sparkpost` — a PHP client for the SparkPost API, written to be usable from any host
application rather than tied to one HTTP library or framework.

It replaces two hand-written API clients: the one inside the `Hampel/SparkPostMail` XenForo add-on,
and the transmission-posting half of `hampel/symfonymailer-sparkpost`. The Symfony Mailer transport
lives in a separate package, `hampel/sparkpost-transport`, which depends on this one. **Nothing in
here may depend on `symfony/mailer`** — that boundary is the reason the packages are split.

## Commands

```bash
composer install
composer check                                  # lint, analyse, test - what CI runs
composer test                                   # phpunit
composer analyse                                # phpstan, levels below
composer format                                 # pint, PSR-12
vendor/bin/phpunit --filter test_name           # one test
vendor/bin/phpunit tests/ConfigTest.php         # one file
```

## The PSR-18 seam is the whole design

The client is injected as `Psr\Http\Client\ClientInterface` with PSR-17 factories, never as a
concrete class, and `Connection` is the only file that touches HTTP.

This is not abstraction for its own sake. The XenForo add-on cannot use an arbitrary HTTP client: it
must route outbound requests through `XF\Http\Reader::requestUntrusted()` for proxy support and SSRF
protection. The previous package hardcoded Guzzle, so the add-on wrote a second API client rather
than use it. Under PSR-18 it writes a small adapter instead.

Two consequences to keep in mind when changing `Connection`:

- **A PSR-18 client does not throw on an HTTP status.** It throws `ClientExceptionInterface` only
  when the request never completed. That is what keeps `RequestException` (never reached SparkPost,
  safe to retry) cleanly separate from `ApiException` (SparkPost answered, and said no).
- **There is no `'json' => $payload` convenience.** The body is encoded and wrapped in a stream
  through the PSR-17 factory by hand. Do not reach for a Guzzle option to avoid it.

**Do not add `php-http/discovery`** as a dependency. It is a Composer plugin, and the XenForo add-on
sets `"allow-plugins": {"php-http/discovery": false}`. A convenience constructor may use it if it
happens to be installed, but the explicit constructor has to remain the supported path.

## Dependency constraints are load-bearing

`psr/log` is constrained to `^1.1|^2.0|^3.0` **on purpose**. XenForo 2.3 ships `psr/log 1.1.4`, and
the add-on installs its own `vendor/` alongside XenForo's — requiring `^3.0` here would put a second,
signature-incompatible `LoggerInterface` on the autoloader. The same reasoning applies to
`psr/http-message` (`^1.1|^2.0`; XF ships 2.0). Check what XenForo bundles in
`/srv/www/xenforo23.local/src/vendor/composer/installed.json` before widening or narrowing either.

## Architecture

- `SparkPost` — entry point and resource factory. Builds one `Connection` and memoises resources.
- `Config` — API key, base URI, region. `resolve()` turns a path into a URI and is where SparkPost's
  pagination links get handled: they come back already carrying the `/api/v1/` prefix that `baseUri`
  ends with, so the prefix is stripped here rather than at each call site.
- `Connection` — request building, sending, decoding, and the single place a failed response becomes
  an exception.
- `Resource/*` — one class per API area, taking and returning plain data.
- `Result/*` — typed results where the shape is worth pinning down.
- `Exception/*` — see below.

### Exceptions

```
ExceptionInterface                     catch-all for this package
├── InvalidArgumentException           caller error, before the network
└── SparkPostException (abstract)
    ├── RequestException               never reached SparkPost
    └── ApiException (abstract)        SparkPost answered; carries status, errors[], body, retryAfter
        ├── ClientException            4xx
        │   └── RateLimitException     429
        └── ServerException            5xx
```

`RateLimitException` extends `ClientException` rather than sitting beside it, so a caller that only
distinguishes client from server errors still catches it.

`ApiException::fromResponse()` must stay defensive about the body. SparkPost is not the only thing
that can answer on that URL — a proxy or gateway will return HTML — so `json_decode()` returning
`null`, and a JSON body with no `errors` key, are both expected inputs, not edge cases.

### HTTP 200 is not a successful send

SparkPost returns 200 having accepted zero recipients. `TransmissionResult::wasAccepted()` is the
real check, and it exists because the package this one replaces got it wrong: it treated 200 as
success and ignored the body, so a rejected send looked identical to a delivered one.

**This package reports rather than throws** on rejected recipients — the API call did succeed, and
whether that counts as failure is the caller's policy. `hampel/sparkpost-transport` is where
`wasAccepted() === false` becomes a thrown `TransportException`.

## Tests

`tests/StubClient.php` is a PSR-18 client that answers from a queue and records requests, so the
suite needs no network and no Guzzle mock handler. Guzzle is a dev dependency only, for its PSR-7
objects. Any new resource gets tested through the stub — if a test needs the network, the design is
wrong.

Namespace is `Hampel\SparkPost\Tests\`, filename suffix `Test.php`.

## Version support

`php: >=8.3` per the Tier A policy in `/srv/www/version-support.html`, with PHPStan analysing the
whole 8.3–8.5 range in one pass (`phpVersion` in `phpstan.neon`). Keep that range in step with the
`php` constraint in `composer.json`. Widening or narrowing either is a policy decision, not a
judgement call.

## Releases

`CHANGELOG.md` is hand-maintained, newest first, `x.y.z (YYYY-MM-DD)` heading with bullet points, and
is updated in its own commit before tagging. Simon does his own pushes and tagging.
