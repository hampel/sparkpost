# Changelog

## Unreleased

**Transmission content was being logged, and if you have ever run this package at `debug` your
log store holds the emails it sent.** Up to and including 1.1.0,
`Transmissions::send()` logged the entire transmission payload at `debug`: the subject, the HTML
body, the text body, `content.headers`, `substitution_data` and the recipients, all verbatim.
Only attachment and inline-image `data` was abbreviated, and only above 100 characters. For a
mail transport that is every outgoing message, password-reset and email-confirmation links
included. Check whether your own log store has those records and whether they should be purged;
this release stops new ones being written and cannot remove the old.

- the transmission log line now describes the send instead of reproducing it: `campaign_id`,
  `template_id`, `recipient_count`, `recipients` (addresses, capped at ten), `transactional`,
  `sandbox`, `attachment_count`, `inline_image_count`, `has_substitution_data` and `return_path`.
  No part of the message — subject, bodies, headers, substitution data — is logged at any level
- a transmission's result is logged at `debug`: `transmission_id`,
  `total_accepted_recipients`, `total_rejected_recipients`
- the `error` record for a failed response no longer carries the response body. It logs
  `status`, `method`, `uri`, SparkPost's parsed `errors[]`, and the body's `body_length` and
  `content_type`. This is the record production writes, since `error` is above the usual
  threshold and is the level that gets emailed
- `ApiException`'s message quotes at most 200 characters of a body that would not parse, since
  that message is what a caller logging `['exception' => $e]` writes. The whole body is still on
  `$body`
- `Suppression::add()` logs named keys rather than spreading its payload, so the caller's
  `description` text is no longer logged

## 1.1.0 (2026-09-13)

- PSR-17 factories are optional in the `SparkPost` constructor. When either is omitted,
  Guzzle's, Nyholm's or Diactoros' is found by class name; `InvalidArgumentException` if none is
  installed
- add `SparkPost::withKey()`, taking the API key, a PSR-18 client and an optional region
- `Transmission::returnPath()` documents the envelope FROM: SparkPost does not validate it at
  post time, a value naming a domain the account is not configured for is discarded in favour
  of the fallback bounce domain, and only the domain survives
- README documents versioning and support — `^1.0` is the constraint to write, PHP 8.3 or
  later, 1.x supported and 0.x not
- README states what the stability promise covers. A break in a class, method or signature
  means 2.0.0; `BounceClass` and `EventType` gain cases in a minor, so every `match` over them
  needs a `default` arm; changing which classification an existing code maps to is a major
- README states that `ApiException::$errors`, `$body`, `$statusCode` and `$retryAfter` are
  covered by the major version, and that the keys inside each error and the shape of an event
  are not
- corrected the documented behaviour of a bogus return path in `.env.example` and the harness:
  a bogus value and an empty one produce the same envelope
- README and `Suppression::add()` describe the suppression propagation lag as an order of
  magnitude rather than a fixed figure
- README and the test fixtures use an illustrative subaccount id

## 1.0.0 (2026-09-04)

**The public API is declared stable.** A breaking change from here means `2.0.0`. The
constraint to write is `^1.0`.

- no functional change: `src/` is byte-identical to 0.4.0

## 0.4.0 (2026-09-04)

**Breaking, hence 0.4.0 rather than 0.3.1:** adding a case to `BounceClassification` makes an
exhaustive `match` over that enum in a consumer throw `UnhandledMatchError`, and two bounce
classes change the classification they report.

- add `BounceClassification::Informational`, and reclassify `AutoReply` (60) and
  `Subscribe` (80) onto it, from `Soft` and `Admin` respectively. Both describe a message that
  was delivered and then drew a reply. The grouping is this package's, not SparkPost's
- `Unsubscribe` (90) stays `Hard`
- `BounceClass` cites a current source for the bounce classification table. The URL it carried
  no longer serves one, and the table that replaced it omits four of the codes
- `RateLimitException` documents its empty body and the four properties it inherits from
  `ApiException`
- harness: print grouped figures with `Io::values()`, and require `hampel/rig` at `^1.1`
- CI: the declared-dependencies job runs `composer-require-checker` as well as the dev-free
  analysis

## 0.3.0 (2026-08-27)

- declare `ext-ctype`, used by `ApiException` and not previously required
- nothing else here reaches an installed package; `src/` is untouched since 0.2.0
- require `hampel/rig` at `^1.0` for the harness, up from `^0.1.2`
- harness: a real delivery or a write to the suppression list needs a second opt-in
- harness: check that SparkPost still honours the message-events recipient filter
- harness: the suppression round trip deletes its throwaway address in a `finally`, and exits
  non-zero if that delete fails

## 0.2.0 (2026-08-22)

- add the suppression resource — `search()`, `find()`, `isSuppressed()`, `add()` and `delete()`
- add the sending domains resource — `all()`, `find()` and `forAddress()`, read-only

## 0.1.1 (2026-08-22)

- documentation only: no code changes

## 0.1.0 (2026-08-21)

- initial development — PSR-18 connection, configuration, exception hierarchy and the
  transmissions resource
- add the `Transmission` builder, with `Address` and `Attachment`
- add the message events resource, with a serialisable cursor, a lazy iterator across pages,
  and the `BounceClass` and `EventType` enums
- add `Config::host()`, `SparkPost::config()` and `Transmission::deliverTo()`
- tested against Guzzle 7 and 8
