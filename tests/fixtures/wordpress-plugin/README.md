# Fixtures captured from the WordPress plugin

These are not hand-written. Each file is the exact array returned by
`SparkPostMailer\Mailer::build_transmission()` in
`/srv/www/wordpress.local/wp-content/plugins/sparkpost-mailer`, captured by loading that
plugin file verbatim outside WordPress with the handful of WordPress functions stubbed,
and invoking the private method by reflection.

The plugin solved the transmission payload before this package existed, and solved it
better than the two implementations that came before it. `TransmissionParityTest` asserts
that `Transmission::toArray()` reproduces each of these from the equivalent inputs, so the
knowledge in that plugin cannot quietly drift out of this package.

If the plugin changes, recapture rather than editing these by hand.
