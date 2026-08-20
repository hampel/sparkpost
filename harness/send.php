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
 * Needs SPARKPOST_API_KEY, SPARKPOST_TO, SPARKPOST_FROM.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\SparkPost;
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

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, $region), new Client(), $factory, $factory);

$io->value('endpoint', Config::forRegion($key, $region)->resolve('transmissions'));
$io->value('from', $from);
$io->value('to', $to);
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

// The sandbox domain is switched on by the builder itself - show what that produced.
$io->value('payload', $transmission->toArray()['options'] ?? []);
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
    $io->info('Accepted. It should arrive shortly - check the spam folder before believing otherwise.');
} else {
    $io->warn('HTTP 200, and nobody was accepted. This is the case that looks like success and is not.');
}
