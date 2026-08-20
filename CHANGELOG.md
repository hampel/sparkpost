CHANGELOG
=========

Unreleased
----------

* initial development - PSR-18 connection, configuration, exception hierarchy and the
  transmissions resource
* add the Transmission builder, with Address and Attachment, checked against fixtures
  captured from the hampel/sparkpost-mailer WordPress plugin
* add the message events resource, with a serialisable cursor, a lazy iterator across
  pages, and the BounceClass / EventType enums
