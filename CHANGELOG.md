CHANGELOG
=========

Unreleased
----------

**This is a breaking change, so it is 0.4.0 rather than 0.3.1**, and it has to be released
before 1.0.0 rather than after: adding a case to `BounceClassification` makes an exhaustive
`match` over that enum in a consumer throw `UnhandledMatchError`, and two bounce classes
change the classification they report.

* add `BounceClassification::Informational`, and reclassify `AutoReply` (60) and
  `Subscribe` (80) onto it, from `Soft` and `Admin` respectively. Both describe a message
  that was *delivered* and then drew a reply, so filing them with the failures meant the
  obvious `match ($class->classification())` reached a punitive arm for a recipient who
  had just opted in. This grouping is ours, not SparkPost's - see the enum's own docblock
* `Unsubscribe` (90) deliberately stays `Hard` although it also describes a delivered
  message: `isPermanent()` is what a consumer acts on, and "stop sending to this address"
  is the right consequence for an opt-out. Pinned by a test
* `BounceClass` cited a bounce-classification-codes URL that now redirects to bird.com and
  no longer carries the table. The docblock says so, and points at the coarser rollup that
  replaced it, with a warning that it is not the same table - it omits four of the codes
* `RateLimitException` says that its empty body is not an empty type, and names the four
  properties it inherits from `ApiException`
* harness: print a group of figures with `Io::values()`, which aligns them to the group's
  own widest label, and require `hampel/rig` at `^1.1` for it
* CI: the declared-dependencies job runs `composer-require-checker` as well as the dev-free
  analysis. The analysis cannot see a dependency that arrives transitively, nor a missing
  `ext-*` - a function from an absent extension is indistinguishable from one from core -
  which is how `ext-ctype` went undeclared from 0.1.0 to 0.3.0 with that job green

0.3.0 (2026-08-27)
------------------

* declare `ext-ctype`, which was used and not required - `ApiException` reads the
  `Retry-After` header with `ctype_digit()`, and ctype is a separable extension that trimmed
  builds do drop. Without the declaration a host that cannot run the code installs anyway and
  fatals on an undefined function, on the path that handles an API error
* nothing else here reaches an installed package - `src/` is untouched since 0.2.0, and the
  remaining files are development-only or `export-ignore`d out of the dist archive
* require `hampel/rig` at `^1.0` for the harness, up from `^0.1.2` - rig withholds the
  environment file from an agent session, which arrived in 0.2.0, and a caret constraint
  below the release that introduces something can never reach it
* harness: refuse a real delivery or a suppression write under an agent session, whatever
  the environment file says
* harness: check that SparkPost still honours the message-events recipient filter, by asking
  twice over one window and printing both counts - a filter the API stops recognising is
  dropped silently and answers 200, which no stub can catch
* harness: the suppression round trip now deletes its throwaway address in a `finally`, and
  exits non-zero if that delete fails

0.2.0 (2026-08-22)
------------------

* add the suppression resource - search, find, isSuppressed, add and delete
* add the sending domains resource - all, find and forAddress, read-only

0.1.1 (2026-08-22)
------------------

* documentation only: no code changes

0.1.0 (2026-08-21)
------------------

* initial development - PSR-18 connection, configuration, exception hierarchy and the
  transmissions resource
* add the Transmission builder, with Address and Attachment
* add the message events resource, with a serialisable cursor, a lazy iterator across
  pages, and the BounceClass / EventType enums
* add Config::host(), SparkPost::config() and Transmission::deliverTo()
* tested against Guzzle 7 and 8
