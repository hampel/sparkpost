<?php

/**
 * Exercise: fetch real message events, and watch the paging work.
 *
 * The suite proves the cursor round trip against a stub. What it cannot show you is what
 * SparkPost actually returns - which event types are in play on a real account, how big
 * the pages are, and whether the links come back in the shape the cursor expects.
 *
 * Needs SPARKPOST_API_KEY. Reports on the last 24 hours by default; set SPARKPOST_DAYS to
 * widen it, and SPARKPOST_EVENTS to a comma-separated list to narrow the types.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\MessageEvent\BounceClass;
use Hampel\SparkPost\MessageEvent\EventCursor;
use Hampel\SparkPost\MessageEvent\EventQuery;
use Hampel\SparkPost\SparkPost;

$io->title('sparkpost · events');

$key = getenv('SPARKPOST_API_KEY');

if ($key === false || $key === '') {
    $io->error('SPARKPOST_API_KEY is not set. Copy .env.example to .env beside the package.');

    exit(1);
}

$days = (int) (getenv('SPARKPOST_DAYS') ?: 1);

$query = EventQuery::make()
    ->from(new DateTimeImmutable("-{$days} days"))
    ->to(new DateTimeImmutable())
    ->perPage(10);

$events = getenv('SPARKPOST_EVENTS');

if (is_string($events) && $events !== '') {
    $query->events(...array_map(trim(...), explode(',', $events)));
}

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

$io->value('window', "last {$days} day(s)");
$io->value('parameters', $query->toArray());
$io->line();

try {
    $page = $sparkpost->messageEvents()->search($query);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$io->success('✓ searched');
$io->value('total_count', $page->totalCount);
$io->value('this page', count($page));
$io->value('has more', $page->hasMore() ? 'yes' : 'no');
$io->line();

foreach ($page as $event) {
    $type = is_string($event['type'] ?? null) ? $event['type'] : '?';
    $rcpt = is_string($event['rcpt_to'] ?? null) ? $event['rcpt_to'] : '-';

    $class = BounceClass::tryFrom((int) ($event['bounce_class'] ?? 0));
    $detail = $class === null ? '' : sprintf(' [%s / %s]', $class->slug(), $class->classification()->value);

    // SparkPost records both senders: friendly_from is the header From the reader sees,
    // msg_from is the envelope address bounces are delivered to and SPF authenticates.
    // They differ whenever a return path is in play, which is the normal case for a
    // bounce domain - so show the envelope only when it is not the obvious one.
    $from = is_string($event['friendly_from'] ?? null) ? $event['friendly_from'] : null;
    $envelope = is_string($event['msg_from'] ?? null) ? $event['msg_from'] : null;

    if ($envelope !== null && strcasecmp($envelope, (string) $from) !== 0) {
        $detail .= sprintf(' <%s>', $envelope);
    }

    $io->line(sprintf('  %-22s %s%s', $type, $rcpt, $detail));
}

if (! $page->hasMore()) {
    exit(0);
}

$io->line();
$io->info('Storing the cursor as a string and resuming, which is what a queue job does:');

$stored = (string) $page->next();
$io->value('stored cursor', $stored);

$resumed = $sparkpost->messageEvents()->next(EventCursor::fromString($stored));

$io->success(sprintf('✓ resumed - %d more event(s)', count($resumed)));
