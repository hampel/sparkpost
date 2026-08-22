<?php

/**
 * Exercise: read the suppression list, and answer the two questions it exists for.
 *
 * The suite drives all of this through a stub, which proves the package handles the shapes
 * correctly. What it cannot prove is that those are the shapes SparkPost sends - and this
 * endpoint has two that are easy to get wrong:
 *
 *   - links come back as a list of {href, rel}, NOT as the {"next": "..."} object the
 *     events endpoint returns. Reading it the events way finds no next page and reports
 *     the first one as the whole list, which looks exactly like success.
 *   - an address that is not suppressed answers 404, so "this address is fine" arrives as
 *     an error and has to be turned back into a plain no.
 *
 * Reading is safe and happens every run. **Deleting is not**, so it only happens when
 * SPARKPOST_SUPPRESSION_DELETE names the address to remove - deliberate, one address at a
 * time, never inferred. Taking an address off the list means SparkPost will attempt
 * delivery to it again.
 *
 * Needs SPARKPOST_API_KEY.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\SparkPost;

$io->title('sparkpost · suppression');

$key = getenv('SPARKPOST_API_KEY');

if ($key === false || $key === '') {
    $io->error('SPARKPOST_API_KEY is not set. Copy .env.example to .env beside the package.');

    exit(1);
}

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);
$suppression = $sparkpost->suppression();

try {
    $page = $suppression->search(1, 5);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

$io->success('✓ read the list');
$io->value('total on the list', $page->totalCount);
$io->value('this page', count($page));
$io->value('another page', $page->hasMore ? 'yes - rel=next was found' : 'no');
$io->line();

foreach ($page as $entry) {
    $io->line(sprintf('  %-38s %-18s %s', $entry->recipient, $entry->source, $entry->created?->format('Y-m-d') ?? '?'));

    // the field that answers "why is this address not receiving mail"
    if ($entry->description !== '') {
        $io->line(sprintf('    %s', substr(str_replace(["\r", "\n"], ' ', $entry->description), 0, 100)));
    }
}

$io->line();

// The 404-is-not-an-error path, both ways round.
$known = $page->results[0]->recipient ?? null;

if ($known !== null) {
    $io->value('isSuppressed(a listed address)', $suppression->isSuppressed($known) ? 'true' : 'false');
}

$io->value(
    'isSuppressed(an address that is not)',
    $suppression->isSuppressed('definitely-not-on-the-list-9f3a@example.org') ? 'true' : 'false'
);
$io->info('The second one is a real 404 from SparkPost, turned back into a plain false.');

$remove = getenv('SPARKPOST_SUPPRESSION_DELETE') ?: null;

if ($remove === null) {
    $io->line();
    $io->info('Nothing was deleted. Set SPARKPOST_SUPPRESSION_DELETE=<address> to remove one,');
    $io->info('which lets SparkPost attempt delivery to it again.');

    exit(0);
}

$io->line();
$io->value('deleting', $remove);

try {
    $removed = $suppression->delete($remove);
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());

    exit(1);
}

if ($removed) {
    $io->success('✓ removed - SparkPost will attempt delivery to it again');
} else {
    $io->warn('It was not on the list. That is a false, not a failure.');
}

$io->value('isSuppressed now', $suppression->isSuppressed($remove) ? 'true' : 'false');
