<?php

/**
 * Exercise: fetch real message events, page them, and check a filter is still honoured.
 *
 * The suite proves the cursor round trip against a stub. What it cannot show you is what
 * SparkPost actually returns - which event types are in play on a real account, how big
 * the pages are, and whether the links come back in the shape the cursor expects.
 *
 * The last part answers a question nothing local can. Every query parameter this package
 * sends is a factual claim about someone else's API, and a claim that stops being true
 * does not arrive as an error: an unrecognised filter is dropped and the call succeeds
 * with everything in the account in it. A stub cannot catch that, because the stub is
 * built from the same assumption the query builder is - both stay agreed with each other
 * and wrong about SparkPost. So the run asks twice over one window, once unfiltered and
 * once for a recipient that has never existed, and prints both counts. Two numbers that
 * match is the failure; the exercise says so and still asserts nothing.
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

$io->line();
$io->info('Is the filter actually being honoured? A parameter SparkPost stops recognising is');
$io->info('not an error - the call comes back 200 with everything in the account in it.');
$io->line();

// The dangerous answer here is not an exception, it is two numbers that match. Ask twice
// over the same window - once for everything, once for a recipient that has never existed.
// An honoured filter answers zero; a silently dropped one hands back the unfiltered count,
// and nothing about the response says which happened. A stub cannot settle this: it would
// be answering from the same assumption the query builder is making.
$window = fn (): EventQuery => EventQuery::make()
    ->from(new DateTimeImmutable("-{$days} days"))
    ->to(new DateTimeImmutable())
    ->perPage(1);

$needle = sprintf('zzz-no-such-recipient-%s@example.org', bin2hex(random_bytes(4)));

try {
    $everything = $sparkpost->messageEvents()->search($window())->totalCount;
    $filtered = $sparkpost->messageEvents()->search($window()->recipients($needle))->totalCount;
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

// Both labels are kept under Io::LABEL_WIDTH (14) so the two numbers pad into the same
// column. Over that, value() emits a single space and the figures land at different
// indents - which defeats the one thing this probe asks a person to do.
$io->value('unfiltered', $everything ?? 'not reported');
$io->value('filtered', $filtered ?? 'not reported');

if ($everything === null || $filtered === null) {
    $io->warn('SparkPost did not return total_count, so there is nothing to compare. That is');
    $io->warn('itself worth knowing - EventPage treats it as optional and this is why.');
} elseif ($everything === 0) {
    $io->warn('Both zero only because the window is empty, which proves nothing either way.');
    $io->info('Raise SPARKPOST_DAYS until the first number is not zero, then read this again.');
} elseif ($filtered === $everything) {
    $io->error('Those match, and that is the failure this probe exists for: the recipients');
    $io->error('filter is not being applied, so every query relying on one is quietly');
    $io->error('returning the whole account and reporting 200.');
} elseif ($filtered === 0) {
    $io->success('✓ zero against ' . $everything . ' - the filter is being applied.');
} else {
    $io->warn('Neither zero nor the unfiltered count. Worth understanding before trusting it.');
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
