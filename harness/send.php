<?php

/**
 * Exercise: send a real transmission and look at what came back.
 *
 * The suite proves the package handles a response correctly; it cannot tell you whether
 * SparkPost accepts the payload we build, which is the only question that matters before
 * a release. This sends one message through a real key and prints the result.
 *
 * Note what it demonstrates as much as what it sends: SparkPost answers 200 with a body
 * that says how many recipients it actually took, and those two facts disagree more often
 * than you would like. wasAccepted() is the check a caller has to make.
 *
 * Set SPARKPOST_RETURN_PATH to exercise the envelope FROM, which is a second thing the
 * suite cannot settle. It is a different address from the header From, and the difference
 * is the whole point: the envelope address is where bounces are delivered and what the
 * receiver runs SPF against, while the header From is what the reader sees and what DMARC
 * aligns against. Note which of the two SparkPost actually polices: the From must be on a
 * configured sending domain or the transmission is rejected, while the return path is not
 * checked at all and a bogus one is accepted. The cost of that shows up as mail that never
 * arrives, on somebody else's server, which is exactly why this is an exercise and not a
 * test.
 *
 * **This delivers nothing unless you ask it to.** Recipients are rewritten to SparkPost's
 * sink domain, which accepts, counts and discards the message while still producing real
 * delivery and bounce events. Set SPARKPOST_DELIVER=1 to send for real.
 *
 * The default is that way round on purpose. A session once ran this expecting a
 * missing-credentials guard, not knowing a populated .env was already sitting in the
 * package, and mail went out. An opt-in sink flag would not have prevented that - a session
 * unaware of the .env is equally unaware of the flag. Sinking unless told otherwise makes
 * the accident harmless and makes the real send the thing that takes a deliberate act,
 * which is the right way round for a harness whose job is to prove the API client works
 * rather than to deliver mail.
 *
 * Needs SPARKPOST_API_KEY, SPARKPOST_TO, SPARKPOST_FROM. SPARKPOST_RETURN_PATH and
 * SPARKPOST_DELIVER are optional.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Transmission\Address;
use Hampel\SparkPost\Transmission\Transmission;

$io->title('sparkpost · send');

$key = getenv('SPARKPOST_API_KEY');
$to = getenv('SPARKPOST_TO');
$from = getenv('SPARKPOST_FROM');

if ($key === false || $key === '') {
    $io->error('SPARKPOST_API_KEY is not set. Copy .env.example to .env beside the package.');

    exit(1);
}

if ($to === false || $to === '' || $from === false || $from === '') {
    $io->error('SPARKPOST_TO and SPARKPOST_FROM are both needed.');

    exit(1);
}

$region = getenv('SPARKPOST_REGION') ?: null;
$returnPath = getenv('SPARKPOST_RETURN_PATH') ?: null;

// Exactly '1' and nothing else. An environment variable is always a string, so a loose
// test would make SPARKPOST_DELIVER=0 mean "deliver" - precisely the mistake this default
// exists to prevent. rig skips any key already set in the process environment, so
// SPARKPOST_DELIVER=1 vendor/bin/rig send beats whatever the .env says.
$deliver = getenv('SPARKPOST_DELIVER') === '1';

// The suffix goes on the whole address, local part included.
$sink = static fn (string $email): string => $email . '.sink.sparkpostmail.com';

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, $region), new Client(), $factory, $factory);

$io->value('endpoint', Config::forRegion($key, $region)->resolve('transmissions'));
$io->value('from', $from);
$io->value('to', $to);
$io->value('return path', $returnPath ?? '(none - SparkPost picks its own bounce domain)');
$io->value('mode', $deliver ? 'DELIVERING for real' : 'sink - nothing will be delivered');
$io->line();

$transmission = Transmission::make()
    ->from($from, 'Rig')
    ->to($to)
    ->subject('hampel/sparkpost · rig send')
    ->text("Sent by vendor/bin/rig send.\n\nIf you are reading this, the transmissions resource works.")
    ->html('<p>Sent by <code>vendor/bin/rig send</code>.</p><p>If you are reading this, the transmissions resource works.</p>')
    ->transactional()
    ->openTracking(false)
    ->clickTracking(false);

if ($returnPath !== null) {
    $transmission->returnPath($returnPath);
}

// Rewrite where it is delivered, never how it is addressed. deliverTo() sets
// recipients[].address.email, while to() has already produced header_to - so the sink
// message still reads as though addressed normally, same To: line, which is what makes
// exercising it worth anything.
if (!$deliver) {
    $transmission->deliverTo([new Address($sink($to))]);
}

$payload = $transmission->toArray();

// The sandbox domain is switched on by the builder itself - show what that produced.
$io->value('options', $payload['options'] ?? []);

// And show return_path from the payload rather than from the variable, because where it
// lands is the part worth seeing: it is a top-level field, not one of the options, and
// putting it under options is a mistake SparkPost accepts in silence - a 200, a delivered
// message, and the account's default bounce domain still on the envelope.
$io->value('return_path', $payload['return_path'] ?? '(not in the payload)');
$io->line();

try {
    $result = $sparkpost->transmissions()->send($transmission);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$io->success('✓ SparkPost took the transmission');
$io->value('id', $result->id);
$io->value('accepted', $result->totalAcceptedRecipients);
$io->value('rejected', $result->totalRejectedRecipients);
$io->line();

if ($result->wasAccepted()) {
    $io->info($deliver
        ? 'Accepted. It should arrive shortly - check the spam folder before believing otherwise.'
        : 'Accepted, and discarded by the sink. SparkPost parsed this payload and took it.');
} else {
    $io->warn('HTTP 200, and nobody was accepted. This is the case that looks like success and is not.');
}

if (!$deliver) {
    $io->line();
    $io->warn('Sink: nothing was delivered, so there is no message to read the headers of.');
    $io->info('Everything above is real - SparkPost parsed and accepted this payload - but');
    $io->info('anything decided at the far end is not answered here. Re-run with');
    $io->info('SPARKPOST_DELIVER=1 to check delivery, Return-Path or DMARC.');
}

if ($returnPath !== null && $deliver) {
    $io->line();
    // Verified 22 August 2026: a bogus return path is accepted (200), while a From on an
    // unconfigured sending domain is rejected outright. SparkPost checks the two in
    // different places, and conflating them is what put a wrong claim in these files.
    // The bogus-return-path message never arrived, so the cost lands downstream instead.
    $io->info('SparkPost took the return path. That is not proof the domain is anything -');
    $io->info('a bogus one is accepted too, unlike a From on an unconfigured domain, which');
    $io->info('is rejected outright. It is decided at the far end, so read what arrives:');
    $io->line('  Return-Path:                 the envelope address, and where a bounce would go');
    $io->line('  Authentication-Results: spf  authenticates the envelope domain, not the From');
    $io->line('  Authentication-Results: dmarc  passes only if one of SPF or DKIM aligns with From');
    $io->line();
    $io->info("Or ask the API: vendor/bin/rig events reports msg_from, which is this envelope");
    $io->info('address as SparkPost recorded it against the message.');
}
