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
composer analyse                                # phpstan, level 10 - see Version support
composer format                                 # pint, PSR-12
vendor/bin/phpunit --filter test_name           # one test
vendor/bin/phpunit tests/ConfigTest.php         # one file
vendor/bin/rig                                  # the live-API exercises, below
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

**`tests/RecordingLogger.php` declares `log()` without parameter types on purpose**, and that is
the same constraint reaching into the test suite: `psr/log` 1.1 declares `log()` untyped while
`psr/log` 3 declares `string|\Stringable $message`, and an untyped parameter is wider than both, so
one class satisfies every version the package supports. Adding the types "for tidiness" compiles
fine here and breaks the `--prefer-lowest` corner in CI — which is the XenForo corner.

## Architecture

- `SparkPost` — entry point and resource factory. Builds one `Connection` and memoises resources.
- `Config` — API key, base URI, region. `resolve()` turns a path into a URI and is where SparkPost's
  pagination links get handled: they come back already carrying the `/api/v1/` prefix that `baseUri`
  ends with, so the prefix is stripped here rather than at each call site.
- `Connection` — request building, sending, decoding, and the single place a failed response becomes
  an exception. `SparkPost::connection()` exposes its `get()` and `post()` deliberately, so an
  endpoint with no `Resource` yet is a call away rather than a release away. `composer.json`
  advertises suppression and nothing wraps it — that is the gap this hatch covers meanwhile.
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

## The Transmission builder, and why the fixtures matter

`Transmission` builds the payload. Most of its value is not the obvious fields but five
rules that are easy to get wrong and produce mail that looks fine until someone reads the
headers — `header_to` on every recipient, the `CC` header being what makes Cc visible,
the disallowed-header list, `false` surviving the prune, and sandbox auto-detection. Each
is commented where it is implemented.

**Those rules were worked out by the WordPress plugin first**, and
`tests/fixtures/wordpress-plugin/*.json` is that plugin's real output — captured by
`tests/fixtures/wordpress-plugin/capture.php`, which loads
`includes/class-mailer.php` verbatim outside WordPress with WordPress stubbed, and calls
the private `build_transmission()` by reflection. Nothing is retyped, so the fixtures
cannot drift from what the plugin does.

`TransmissionParityTest` asserts with `assertSame`, which compares key order too. That is
stricter than SparkPost needs, deliberately: it means a change to the payload shape fails
loudly rather than passing as "equivalent". If the plugin changes, re-run `capture.php`
rather than editing the JSON.

**`prune()` is not `array_filter()`.** The default callback drops `false` and `0`, which
would turn `open_tracking => false` into "use the account default" — a bug the package
this replaces actually had. Keep the explicit callback.

### HTTP 200 is not a successful send

SparkPost returns 200 having accepted zero recipients. `TransmissionResult::wasAccepted()` is the
real check, and it exists because the package this one replaces got it wrong: it treated 200 as
success and ignored the body, so a rejected send looked identical to a delivered one.

**This package reports rather than throws** on rejected recipients — the API call did succeed, and
whether that counts as failure is the caller's policy. `hampel/sparkpost-transport` is where
`wasAccepted() === false` becomes a thrown `TransportException`.

## Message events, and why the cursor is a string

Paging is the substance of this resource, not a detail of it, because the two callers are
genuinely different. A script that runs to completion wants `each()`, which walks every page
lazily — stop early and it stops making requests. A queue job cannot run to completion at all, so
it needs somewhere to keep its place between runs.

That is why `EventCursor` is a string and nothing more: a job stores `(string) $page->next()` in
its own state and comes back with `EventCursor::fromString()`. **`Config::resolve()` is what makes
that work unchanged** — the URI SparkPost returns already carries the `/api/v1` prefix that the
base URI ends with, so it can go straight back in without the stripping every consumer of this API
has written by hand. Do not "tidy" that branch out of `resolve()`; it is load-bearing here.

`each()` stops, and says so on the logger, if SparkPost hands back a link pointing at the page it
came from. Cheap insurance against a hang whose cause is outside our control.

**Events stay plain arrays.** Their shape varies enormously by event type, and pinning it down
means either a lowest-common-denominator type that hides most of the payload, or twenty types.

`BounceClass` and `EventType` are enums because the codes and their meanings are SparkPost's own.
What an application *does* about each one — disable the account, stop one kind of email, ignore it
— is that application's policy and belongs to it. Resist requests to put that here.

`from` and `to` are converted to UTC rather than formatted as they stand: SparkPost's datetime
format carries no offset, so otherwise the same query means different things depending on where
the server runs.

## Tests

`tests/StubClient.php` is a PSR-18 client that answers from a queue and records requests, so the
suite needs no network and no Guzzle mock handler. Guzzle is a dev dependency only, for its PSR-7
objects. Any new resource gets tested through the stub — if a test needs the network, the design is
wrong.

The rest of the suite's scaffolding, none of it incidental:

- `TestCase` — the base class; builds a `StubClient` per test and wires a `SparkPost` around it.
- `TransportFailure` — throws `NetworkExceptionInterface`, which is the only way to exercise the
  `RequestException` path: a PSR-18 client never throws on a status code.
- `InspectsPayloads` — dotted-path reads into a built payload or a decoded body. It exists because
  PHPStan runs at level 10, where every nested read on `mixed` is an error; the trait is the one
  place that admits nothing guarantees the shape, and a missing path fails the assertion.
- `RecordingLogger` — see the untyped `log()` note above before touching it.

Namespace is `Hampel\SparkPost\Tests\`, filename suffix `Test.php`.

## Exercising against the real API

`harness/` holds three `hampel/rig` exercises. They are not tests and assert nothing — they exist
for the questions a stub structurally cannot answer.

```bash
cp .env.example .env                 # _API_KEY, _TO, _FROM; optional _RETURN_PATH, _REGION
vendor/bin/rig                       # list them
vendor/bin/rig send                  # one real transmission, and what came back
vendor/bin/rig events                # real paging, real event shapes
vendor/bin/rig errors                # needs no key - every call here is meant to fail
```

`send` and `events` answer the only thing worth knowing before a release: whether SparkPost accepts
the payload `Transmission` builds, and whether its pagination links come back in the shape
`EventCursor` expects. `errors` needs no credentials — an invalid key gets a real 401 or 403, and an
unroutable host produces a genuine `RequestException`.

`SPARKPOST_RETURN_PATH` is what exercises the envelope FROM, and it is there because bounce handling
and DMARC are decided somewhere no test can reach. The envelope address takes the bounces and is
what SPF authenticates; the header From is what DMARC aligns against; SparkPost refuses a bounce
domain the account has not verified. `send` prints `return_path` from the built payload rather than
from the variable — it is a top-level field, and putting it under `options` instead is a mistake the
API accepts in silence. `events` then prints `msg_from` whenever it differs from `friendly_from`,
which is the same envelope address as SparkPost recorded it.

**`vendor/bin/rig send` sends real mail** the moment a real `.env` is present — there is no dry run,
and `--env=` pointing at a throwaway file with an invalid key is the way to exercise the output
without one.

`.env` and `.env.*` are gitignored with `!.env.example`; `harness/` is `export-ignore`d, along with
`tests/`, `CLAUDE.md` and the tooling config, so none of it ships in the Packagist archive.

## Version support

`php: >=8.3` per the Tier A policy in `/srv/www/version-support.html`, with PHPStan analysing the
whole 8.3–8.5 range in one pass (`phpVersion` in `phpstan.neon`). Keep that range in step with the
`php` constraint in `composer.json`. Widening or narrowing either is a policy decision, not a
judgement call.

PHPStan runs at **level 10** over both `src` and `tests`, excluding
`tests/fixtures/wordpress-plugin/capture.php` — that file redeclares WordPress on purpose, and the
suite reads its output rather than its code.

CI runs the corners of the range rather than the whole matrix: 8.3 with `--prefer-lowest`, 8.3
current, and 8.5. PHPStan runs in each of those jobs and not only in one of its own, because
`phpVersion` covers the PHP axis wherever it runs but the result still depends on the dependency
tree resolved against — PHPStan's own version included, which is what the `--prefer-lowest` corner
is for.

## Releases

`CHANGELOG.md` is hand-maintained, newest first, and updated in its own commit before tagging.
Headings are setext-underlined rather than `##` — `x.y.z (YYYY-MM-DD)` over a row of dashes, matching
the `Unreleased` section already there — with bullet points below. Simon does his own pushes and
tagging.
