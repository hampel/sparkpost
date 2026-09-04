# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/sparkpost` — a PHP client for the SparkPost API, written to be usable from any host
application rather than tied to one HTTP library or framework.

The Symfony Mailer transport lives in a separate package, `hampel/sparkpost-transport`, which
depends on this one. **Nothing in here may depend on `symfony/mailer`** — that boundary is the
reason the packages are split, and it is what keeps this package usable from an application with no
Symfony in it at all.

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

This is not abstraction for its own sake. Some host applications cannot use an arbitrary HTTP
client at all: they require every outbound request to go through their own stack, for proxy support
and SSRF protection. A package that hardcodes Guzzle is unusable there, and what happens next is
that a second API client gets written rather than this one adopted. Under PSR-18 the host writes a
small adapter instead.

Two consequences to keep in mind when changing `Connection`:

- **A PSR-18 client does not throw on an HTTP status.** It throws `ClientExceptionInterface` only
  when the request never completed. That is what keeps `RequestException` (never reached SparkPost,
  safe to retry) cleanly separate from `ApiException` (SparkPost answered, and said no).
- **There is no `'json' => $payload` convenience.** The body is encoded and wrapped in a stream
  through the PSR-17 factory by hand. Do not reach for a Guzzle option to avoid it.

**Do not add `php-http/discovery`** as a dependency. It is a Composer plugin, and a consumer may
refuse to run it — `"allow-plugins": {"php-http/discovery": false}` is a realistic line to find in
an application's `composer.json`. A convenience constructor may use it if it happens to be
installed, but the explicit constructor has to remain the supported path.

## Dependency constraints are load-bearing

`psr/log` is constrained to `^1.1|^2.0|^3.0` **on purpose**. A host application may bundle its own
`psr/log 1.x` and install a package's `vendor/` alongside it — requiring `^3.0` here would then put
a second, signature-incompatible `LoggerInterface` on the autoloader, and the failure arrives at
runtime rather than at install. The same reasoning applies to `psr/http-message` (`^1.1|^2.0`).

Neither range is there to be tidied up. Widening or narrowing either is a compatibility decision
about the applications that actually consume this package, so check against those rather than
against whatever looks current — and note that a bundled version can move in a point release, so
the answer has a shelf life.

**`tests/RecordingLogger.php` declares `log()` without parameter types on purpose**, and that is
the same constraint reaching into the test suite: `psr/log` 1.1 declares `log()` untyped while
`psr/log` 3 declares `string|\Stringable $message`, and an untyped parameter is wider than both, so
one class satisfies every version the package supports. Adding the types "for tidiness" compiles
fine here and breaks the `--prefer-lowest` corner in CI, which is what holds the `psr/log 1.1` end
of that range honest.

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

## The Transmission builder

`Transmission` builds the payload. Most of its value is not the obvious fields but five
rules that are easy to get wrong and produce mail that looks fine until someone reads the
headers — `header_to` on every recipient, the `CC` header being what makes Cc visible,
the disallowed-header list, `false` surviving the prune, and sandbox auto-detection. Each
is commented where it is implemented, and each is asserted on its own in
`TransmissionTest`.

Those rules predate this package — they were established in an earlier implementation and the
builder was checked against captured fixtures while it was being written. That scaffolding is gone,
because a fixture recaptured from anywhere but the original source would have been a golden file
regenerated from the code under test, which certifies nothing.

What survives is `test_the_whole_payload_for_a_message_with_to_cc_and_bcc`, which asserts
one entire payload with `assertSame`, key order included. It duplicates the per-field
tests on purpose: only a whole-payload assertion notices a field that silently appears or
disappears. Its expected array is written out in PHP rather than loaded from a file so
that changing it takes a hand edit in the same diff as whatever changed the builder.

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

**`BounceClassification::Informational` is the one deliberate exception, and it is not a policy.**
Two bounce classes describe a message that was *delivered* and then drew a reply — `AutoReply` (60)
and `Subscribe` (80) — and SparkPost files them under `soft` and `admin` respectively, alongside
real failures. That is faithful to the SMTP exchange and actively misleading downstream: the
obvious `match ($class->classification())` then reaches a punitive arm for someone who has just
opted in. The XenForo add-on hit exactly that, and had to carry its own carve-out plus a test
named after the trap.

The line that keeps the position above intact: *"did this message reach the recipient?"* is a fact
about the taxonomy, and only this package is positioned to answer it for all 21 codes. *"Should I
disable this account?"* is still policy and still belongs to the caller. `Informational` answers
only the first.

`Unsubscribe` (90) describes a delivered message too and is **deliberately not** `Informational`.
`isPermanent()` is what a consumer acts on, and "stop sending to this address" is right for an
opt-out — so reclassifying it would be accurate about the delivery and wrong about the
consequence. `test_unsubscribe_stays_hard_though_the_message_was_delivered` pins it so a tidy-up
towards consistency has to argue with a failing test.

`ChallengeResponse` (100) stays `Soft`, and was checked rather than assumed: the mailbox held the
message pending a challenge nobody answered, so it reached no one. Bird's current table agrees.

`from` and `to` are converted to UTC rather than formatted as they stand: SparkPost's datetime
format carries no offset, so otherwise the same query means different things depending on where
the server runs.

## Suppression, and the two shapes that differ from message events

The resource exists for two questions — *is this address suppressed, and why*, and *take it
off the list* — and both are shaped by how the API answers rather than by what it offers.

**A recipient that is not suppressed is a 404.** So `find()` returns `null` and
`isSuppressed()` returns `false` by catching `ClientException` and checking for exactly 404.
Catching `ClientException` broadly would report every address as clear whenever the key was
wrong, so the status check is the point of that block, not defensiveness around it.

**`links` is not the shape the events endpoint uses**, and nothing about the response makes
that visible:

```
events:      "links": {"next": "/api/v1/events/message?..."}
suppression: "links": [{"href": "...", "rel": "next"}, {"href": "...", "rel": "last"}]
```

`EventPage::fromResponse()` reads `links['next']`. Against a suppression response that key
is absent, so it would report one page and stop — indistinguishable from having read the
whole list. That is why `SuppressionPage` exists rather than reusing `EventPage`, and
`test_the_events_style_link_object_is_not_mistaken_for_one` pins it from the other side.

**Entries are typed, where events are not.** Not an inconsistency: an event's shape varies
enormously by type, while every suppression entry has the same fields. `description` is the
one worth having — SparkPost puts the remote server's own rejection text in it, which is the
answer to "why is this address not receiving mail". The whole entry is kept on `raw` so a
field this class has not grown yet is still reachable.

**Paging is by page number**, though the endpoint offers cursors too. The cursor tokens run
past a kilobyte of base64 and a suppression list is small; there is no stop-and-resume caller
here of the kind that made `EventCursor` a string.

**`add()` is a PUT and therefore an upsert**, and exists mainly so `find()` and `delete()` can
be exercised against the real API without touching an entry SparkPost put there itself. Whether
an application mirrors its own unsubscribes onto SparkPost's list is policy, and stays with the
application — the same reasoning that keeps bounce policy out of `BounceClass`. `list_id` is not
exposed: it addresses SparkPost's own mailing lists, which nothing using this package has.

**The list is eventually consistent, and it is not subtle.** Measured against the live API: an
added address took ~6–7s to become readable on 22 August 2026 and ~10.3s on the 27th, and a
deleted one stays readable for about as long after the delete returns success. Treat the figure
as an order of magnitude rather than a constant — the harness polls for 30s because the second
measurement left under five seconds of headroom under the 15s ceiling it had, and a loop that
gives up prints a warning that reads as a finding about SparkPost rather than about the loop. Nothing in the resource retries or
sleeps — hiding it would make every genuine miss slow, and a caller that needs to observe the
change has to poll. `harness/suppression.php` does exactly that, and its round trip is the only
thing that exercises `add()` and `delete()` for real.

**Do not add `X-MSYS-SUBACCOUNT` support**, and this is the note that exists so it does not
get added by someone reading SparkPost's documentation and finding it missing.

Suppression is scoped per subaccount, and SparkPost offers a request header to say which one.
It is not needed here, because the API key already answers that question: a subaccount key is
bound to its own subaccount automatically and **ignores the header** — verified on a real one,
where `X-MSYS-SUBACCOUNT: 0` and the key's own subaccount id returned byte-identical results.
An account-level key would honour it, but the way to manage one subaccount's suppressions is
to use a key for that subaccount, which SparkPost lets you create for exactly this reason.

Supporting it would mean `Connection` growing per-request headers — it has none today, and
nothing else has asked for them — to serve a caller that does not exist and could not be
tested with any key this package has been run against. `sendingDomains()->forAddress()`
already answers *which* subaccount an address belongs to, which was the useful half.

**The harness checks before it creates.** A round trip that added an address which was already
listed would delete a genuine suppression on the way out — someone else's bounce, removed
silently. It generates an address at `example.org`, verifies it is absent, then proceeds.

It then deletes in a `finally`, because the probes between the add and the delete are live calls
that can throw, and a throw that skipped the delete would leave a real suppression list carrying an
address nobody knows is there. The `exit()` is deliberately outside that block: **PHP does not run
`finally` on `exit()`**, so an exit inside it would quietly undo the whole thing. A cleanup that
fails anyway says so loudly, names the address, and exits non-zero — the messages are what a
person reads, but the exit code is what they skim, and a run that left litter is not a pass.

## Sending domains exist for one field

`SendingDomains` is read-only and always will be: writing there changes where an account's
mail appears to come from, and the narrow grant is worth keeping narrow. Verified against the
live API — with the key set to sending-domains read-only, `GET` works and `POST` returns 403.

It is in the package for `subaccount_id`. Suppression is scoped per subaccount, and **a
subaccount API key cannot read the subaccounts endpoint at all** — that is not a missing
grant, SparkPost has no such permission for a subaccount key, confirmed on a real one. The
sending domain is therefore the only route from an address to the subaccount it belongs to.

Confirmed against live data on 27 August 2026, from three directions that agree: `forAddress()`
on a real From returned the same subaccount a suppression entry carries, and the envelope
addresses in the events output embed that same number, on the domain this resource reports as
the default bounce domain. Note also that all nine domains a subaccount key can see belong to
that subaccount — more evidence for the `X-MSYS-SUBACCOUNT` decision above, since the key is
already answering the question the header would ask.

**The single-domain response is not the same shape as a list row.** The list gives `domain`;
`GET /sending-domains/{domain}` omits it, because it is in the URL, and adds `dkim`. So
`SendingDomain::fromArray()` takes the requested domain as a second argument — without it,
`find()` returns an object whose own `domain` is an empty string, which nothing would notice
until it was logged.

`status` and `dkim` are typed `array<mixed>`, not `array<string, mixed>`. That is not laziness
at level 10: a decoded JSON value cannot be *shown* to have string keys, and claiming it does
would be an assertion dressed as a type.

`forAddress()` accepts `Support <noreply@example.com>` as well as a bare address. Taking
everything after the last `@` keeps the closing bracket and then asks SparkPost for a domain
with a `>` in it — a test asserted that behaviour as correct before it was noticed.

## Tests

`tests/StubClient.php` is a PSR-18 client that answers from a queue and records requests, so the
suite needs no network and no Guzzle mock handler. Guzzle is a dev dependency only, for its PSR-7
objects, and is constrained to `^7.8|^8.0` so CI resolves it at both majors — Guzzle 8 brings
`guzzlehttp/psr7 ^3.0`, and it is the client most consumers will plug into the PSR-18 seam, so a
PSR-7 major that broke request building here should fail in CI rather than in their application. Any new resource gets tested through the stub — if a test needs the network, the design is
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

`harness/` holds five `hampel/rig` exercises. They are not tests and assert nothing — they exist
for the questions a stub structurally cannot answer.

```bash
cp .env.example .env                 # _API_KEY, _TO, _FROM; optional _RETURN_PATH, _REGION
vendor/bin/rig                       # list them
vendor/bin/rig send                  # one real transmission, and what came back
vendor/bin/rig events                # real paging, real event shapes
vendor/bin/rig suppression           # the links shape and the 404; writes are opt-in
vendor/bin/rig domains               # sending domains, and the subaccount derived from one
vendor/bin/rig errors                # needs no key - every call here is meant to fail
```

`harness/lib/` is not scanned: rig discovers exercises with `glob('harness/*.php')`, top level
only, so a shared helper goes one directory down and is `require`d by the exercises that use it.

`send` and `events` answer the only thing worth knowing before a release: whether SparkPost accepts
the payload `Transmission` builds, and whether its pagination links come back in the shape
`EventCursor` expects. `errors` needs no credentials — an invalid key gets a real 401 or 403, and an
unroutable host produces a genuine `RequestException`.

**`events` also probes for the failure that arrives as a success.** Every query parameter the
package sends is a factual claim about SparkPost's API, and a claim that stops being true does not
come back as an error — an unrecognised filter is dropped and the call returns 200 with the whole
account in it. The stub cannot catch that, because it is built from the same assumption the query
builder is: both stay agreed with each other and wrong about SparkPost. So the exercise asks twice
over one window, once unfiltered and once for a recipient that has never existed, and prints both
counts. Two numbers that match is the failure. It still asserts nothing — a person reading two
numbers settles this, and a test cannot, because asserting on the comparison would mean already
knowing the answer being looked for.

Run against the live API on 27 August 2026: 36 events in the window, 0 with an impossible
recipient, so the filter is being applied.

**Print a group that is meant to be compared with `Io::values()`, not a run of `value()`
calls.** `value()` pads to one fixed column, `Io::LABEL_WIDTH`, so a label of 14 characters or
more keeps its line but loses the column and the figures land at ragged indents — which defeats
the only thing this probe asks anyone to do. `values()` takes the whole group and aligns it to
its own widest label, so the labels can say what they mean. That is why `hampel/rig` is
constrained to **`^1.1`**: `values()` arrived there, and `^1.0` resolves to a rig without it.

Two exceptions worth knowing. A single value is just a group of one, so `value()` stays fine
for anything not being compared. And **do not use `values()` where an earlier item can throw
before a later one is computed** — it builds the whole array before printing, so a throw loses
the results already gathered. The two deletes in `suppression`'s round trip are two `value()`
calls for exactly that reason, and say so in a comment.

`SPARKPOST_RETURN_PATH` is what exercises the envelope FROM, and it is there because bounce handling
and DMARC are decided somewhere no test can reach. The envelope address takes the bounces and is
what SPF authenticates; the header From is what DMARC aligns against.

**SparkPost validates the two sender fields in different places, and conflating them put a wrong
claim in these files.** Read off the API on 22 August 2026: a `from` address on a domain that is not
a configured sending domain is rejected outright — `HTTP 400 Unconfigured Sending Domain <domain>` —
while a `return_path` is not validated at post time at all, and a completely bogus one is accepted
with a 200. So acceptance says the payload was well formed and nothing else. Do not restore a claim
that an unverified bounce domain is refused; that rule belongs to the From address.

**What SparkPost then does with the value is a different class of claim, and it was measured later.**
A `return_path` naming a domain the account is not configured for is *discarded*, and the message
goes out under the fallback — the account's default bounce domain, or the subaccount's where the key
is a subaccount key, and `sparkpostmail.com` where neither is configured. Only the domain ever
survives in any case: SparkPost replaces the local part with an identifier of its own, so
`foo@bounce.example.com` is delivered as `<id>@bounce.example.com`. A local part you did not choose
is what success looks like. Measured across every combination on a real account by Simon in
September 2026 and reported here by the `sparkpost-transport` and `comparefunds` sessions — none of
it is reachable from this package's source, a test or a harness payload, which is why it is recorded
as reported rather than stated flatly.

**A bogus value and an empty one are therefore the same state**, which is the half that is easy to
get backwards. What a wrong value costs is not delivery and not alignment — unset is unaligned too.
It is the appearance of having configured something, and nobody re-examines a setting that looks set.

**This section used to carry `Verified 22 August 2026` over two claims of different kinds, and only
one of them had been verified.** The 400 and the 200 were read off the API. *"The message then never
arrives, blocked downstream where DMARC is the likely cause"* was an inference from one uncontrolled
send with no bounce captured; Simon's own words hedged it — *"or if it did, I haven't yet received
it"* — and every summary written from them dropped the hedge. It is probably right, since he changed
the address back to a legitimate domain in the same sitting and the message arrived. But if it is
right then, bogus and unset being the same state, an empty field would have failed that send
identically, and what it shows is that DKIM alignment was not carrying that domain on its own that
afternoon. That is a fact about an account, not about this package. **The remedy for a stamp like
that is not a fresh date** — it is saying which half was observed and which inferred, so the join
stays visible.

`send` prints `return_path` from the built payload rather than
from the variable — it is a top-level field, and putting it under `options` instead is a mistake the
API accepts in silence. `events` then prints `msg_from` whenever it differs from `friendly_from`,
which is the same envelope address as SparkPost recorded it.

**`vendor/bin/rig send` delivers nothing unless `SPARKPOST_DELIVER=1`.** It rewrites recipients to
`<address>.sink.sparkpostmail.com`, which SparkPost accepts, counts and discards while still
producing real delivery and bounce events — so the payload is genuinely exercised and no mail goes
out. That default is inverted from the obvious one on purpose: a session once ran this expecting a
missing-credentials guard, not knowing a populated `.env` was already here, and mail went out. An
opt-in sink flag would not have helped, because a session unaware of the `.env` is equally unaware
of the flag. Do not flip it to opt-in.

### Three layers, and the two switches that are not interchangeable

The sink default above is the middle one of three, and on its own it protects nobody who matters.
It is a default, and the `.env` that actually exists on the machine of whoever owns the key
overrides it — because that person genuinely does want to deliver. So the layers are:

1. **rig withholds the environment file.** `vendor/bin/rig` does not load `.env` at all when
   `CLAUDECODE` is set, and says so. Do not relax the `hampel/rig` constraint — this is the
   only layer that works without the harness cooperating, and a caret below the release that
   introduced something can never reach it. The check arrived in 0.2.0, so `^0.1` could not
   see it; `^0.2` then could not see 1.0.0 either, and `composer update` never says so. The
   constraint is **`^1.0`** for that reason: above 1.0 a caret takes the next minor, which is
   what leaving 0.x bought.
2. **the exercise defaults to the harmless thing** — a sink for `send`, read-only for
   `suppression`.
3. **the opt-in is itself refused under an agent**, which is what closes the gap in (2).

```
SPARKPOST_DELIVER=1                      the human's ordinary opt-in; lives in .env
SPARKPOST_SUPPRESSION_ROUNDTRIP=1        likewise
SPARKPOST_SUPPRESSION_DELETE=<address>   likewise

SPARKPOST_AGENT_MAY_DELIVER=1            an agent, asked to send for real, this once
SPARKPOST_AGENT_MAY_WRITE_SUPPRESSION=1  an agent, asked to write to the list, this once
```

**The second pair never goes in `.env`** — persisted, it recreates exactly the problem it exists
for. They are deliberately not listed among the assignments in `.env.example` for that reason,
only described there.

`harness/lib/agent.php` holds the one function both exercises use, and both of its reads fail
safe: an absent or renamed `CLAUDECODE` falls back to the ordinary opt-in rather than to "assume
human, proceed", so a rename upstream costs this layer and not the safety. The override is tested
against exactly `'1'`, because an environment variable is always a string and a loose test makes
`=0` mean yes.

**Every exercise prints its mode above the work**, not after it, and a sink run says what it did
not prove. A run that answers nothing and is silent about that reads as a pass.

The cost is that sink runs answer nothing decided at the far end — delivery, `Return-Path`, DMARC —
and the exercise says so on the way out. `SPARKPOST_DELIVER=1` is the deliberate act that checks
those, and a shell variable beats the `.env` because `rig` skips keys already in the process
environment.

`.env` and `.env.*` are gitignored with `!.env.example`; `harness/` is `export-ignore`d, along with
`tests/`, `CLAUDE.md` and the tooling config, so none of it ships in the Packagist archive.

## Version support

`php: >=8.3` per the Tier A support policy — published packages get the widest support and the most
verification, because strangers are hurt silently when they break — with PHPStan analysing the
whole 8.3–8.5 range in one pass (`phpVersion` in `phpstan.neon`). Keep that range in step with the
`php` constraint in `composer.json`. Widening or narrowing either is a policy decision, not a
judgement call.

PHPStan runs at **level 10** over both `src` and `tests`, with nothing excluded.

CI runs the corners of the range rather than the whole matrix: 8.3 with `--prefer-lowest`, 8.3
current, and 8.5. PHPStan runs in each of those jobs and not only in one of its own, because
`phpVersion` covers the PHP axis wherever it runs but the result still depends on the dependency
tree resolved against — PHPStan's own version included, which is what the `--prefer-lowest` corner
is for.

## Releases

Since 1.0.0 the public API is stable, and that is a promise with a stated shape rather than a
mood. A breaking change to a class, method or signature in `src/` means **2.0.0**.

**The one carve-out is `BounceClass` and `EventType`, and it is deliberate.** They mirror
SparkPost's taxonomy, which this package does not control, so a code SparkPost adds appears here
**in a minor**. The README says so and tells consumers that every `match` over these enums needs a
`default` arm. Adding a case is therefore not on its own a reason to reach for a major.

**Changing which classification an existing code maps to is a major**, and this is the half worth
holding: a new case announces itself as an `UnhandledMatchError`, while a remapping is silent and
changes what an application does to a real recipient. 0.4.0 did both — it added `Informational`
*and* moved `AutoReply` (60) and `Subscribe` (80) onto it — and the remapping is the part that
earned the breaking version.

`CHANGELOG.md` is hand-maintained, newest first, and updated in its own commit before tagging.
Headings are setext-underlined rather than `##` — `x.y.z (YYYY-MM-DD)` over a row of dashes, matching
the released sections already there — with bullet points below. Notes accumulate under an
`Unreleased` heading as they land, which the release commit renames to `x.y.z (YYYY-MM-DD)`; so
there is one only while something is waiting, and cutting a release consumes it. Simon does his own pushes and
tagging.
