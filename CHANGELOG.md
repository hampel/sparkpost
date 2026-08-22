CHANGELOG
=========

Unreleased
----------

* add the suppression resource - search, find, isSuppressed, add and delete

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
