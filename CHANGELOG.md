CHANGELOG
=========

Unreleased
----------

* nothing here reaches an installed package - `src/` is untouched since 0.2.0, and every
  file below is either development-only or `export-ignore`d out of the dist archive
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
