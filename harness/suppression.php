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
 * Reading is safe and happens every run. **Writing is not**, so it takes an explicit act:
 *
 *   SPARKPOST_SUPPRESSION_ROUNDTRIP=1   add, read back and delete an invented address
 *   SPARKPOST_SUPPRESSION_DELETE=<addr> remove a real one
 *
 * The round trip is how delete() gets exercised without touching an entry SparkPost put
 * there itself, and it checks the address is not already listed before creating it - a
 * create-then-delete over something real would silently remove a genuine suppression.
 * Deleting a real address means SparkPost will attempt delivery to it again.
 *
 * Note that the list is eventually consistent. A write is accepted several seconds before
 * it can be read back, and a delete stays readable for about as long afterwards, so the
 * round trip polls rather than asserting immediately.
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

// A full add -> read -> delete round trip, on an address invented for the purpose. This is
// how delete() gets exercised without touching an entry SparkPost put there itself.
if (getenv('SPARKPOST_SUPPRESSION_ROUNDTRIP') === '1') {
    $io->line();
    $io->info('Round trip: add, wait for it to be readable, delete.');

    // example.org is reserved and cannot receive mail, and the random part means this
    // address has never existed before.
    $probe = sprintf('sparkpost-harness-%s@example.org', bin2hex(random_bytes(4)));
    $io->value('address', $probe);

    // Check before creating, always. If this address were somehow already on the list, the
    // delete at the end would remove a suppression that someone else's bounce put there.
    if ($suppression->isSuppressed($probe)) {
        $io->error('Already on the list. Not touching it - a delete here would remove a real entry.');

        exit(1);
    }

    $suppression->add($probe, 'hampel/sparkpost harness round trip');
    $io->success('✓ added');

    // The list is eventually consistent: the write is accepted well before it can be read
    // back. Measured at about six seconds. Poll rather than assume either way.
    $started = microtime(true);
    $visible = false;

    for ($attempt = 1; $attempt <= 30; $attempt++) {
        if ($suppression->isSuppressed($probe)) {
            $visible = true;

            break;
        }

        usleep(500_000);
    }

    $waited = round(microtime(true) - $started, 1);

    if (!$visible) {
        $io->warn(sprintf('Still not readable after %ss. Deleting anyway.', $waited));
    } else {
        $io->success(sprintf('✓ readable after ~%ss', $waited));
        $io->value('entry', $suppression->find($probe)?->raw);
    }

    $io->value('delete returned', $suppression->delete($probe) ? 'true' : 'false');
    $io->value('delete again returned', $suppression->delete($probe) ? 'true' : 'false');
    $io->info('The second false is the 404 path: nothing to remove is not a failure.');
    $io->info('isSuppressed() may still say true for a few seconds - the same lag, backwards.');

    exit(0);
}

$remove = getenv('SPARKPOST_SUPPRESSION_DELETE') ?: null;

if ($remove === null) {
    $io->line();
    $io->info('Nothing was changed. Set SPARKPOST_SUPPRESSION_ROUNDTRIP=1 to add, read back and');
    $io->info('delete a throwaway address, or SPARKPOST_SUPPRESSION_DELETE=<address> to remove');
    $io->info('a real one, which lets SparkPost attempt delivery to it again.');

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
