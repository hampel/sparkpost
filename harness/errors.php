<?php

/**
 * Exercise: the error paths, which are the ones worth looking at.
 *
 * Every call here is meant to fail. What you are checking is that each failure arrives as
 * the right type, with a message that would help at 2am - the status, the endpoint, and
 * whatever SparkPost said about it.
 *
 * Needs nothing. A deliberately invalid key gets a real 401 or 403 from the real API; the
 * unroutable host produces a genuine transport failure.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;

$io->title('sparkpost · errors');

$factory = new HttpFactory();

$live = new SparkPost(new Config('definitely-not-a-valid-api-key'), new Client(), $factory, $factory);

$io->info('A real request with an invalid key - expect ClientException, HTTP 401 or 403:');
$io->attempt('transmissions()->send() with a bad key', fn () => $live->transmissions()->send([
    'recipients' => [['address' => ['email' => 'nobody@example.com']]],
    'content' => ['from' => ['email' => 'nobody@example.com'], 'subject' => 'x', 'text' => 'x'],
]));

$io->line();

$unreachable = new SparkPost(
    new Config('key', 'https://sparkpost.invalid/api/v1'),
    new Client(['timeout' => 5]),
    $factory,
    $factory
);

$io->info('A host that does not resolve - expect RequestException, not an API error:');
$io->attempt('transmissions()->send() against an unroutable host', fn () => $unreachable->transmissions()->send([]));

$io->line();

$io->info('Caller error, caught before the network - expect InvalidArgumentException:');
$io->attempt('new Config() with an empty key', fn () => new Config('  '));

$io->line();
$io->info('Note which of these is which: only the first one reached SparkPost, so only the');
$io->info('first one could have had a side effect. That distinction is what RequestException is for.');
