<?php

/**
 * Exercise: read the live suppression list - and, if asked, add and delete entries on it.
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
 * Both switches are ignored in an agent session unless SPARKPOST_AGENT_MAY_WRITE_SUPPRESSION=1
 * is passed with them, for the same reason SPARKPOST_DELIVER is ignored in send.php: they
 * are read from a .env that belongs to whoever owns the key, and an agent would inherit
 * an authorisation it never asked for. Reading is unaffected and happens either way.
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

require __DIR__ . '/lib/agent.php';

$io->title('sparkpost · suppression');

$key = getenv('SPARKPOST_API_KEY');

if ($key === false || $key === '') {
    $io->error('SPARKPOST_API_KEY is not set. Copy .env.example to .env beside the package.');

    exit(1);
}

// Both write switches are settled before anything runs, so the mode can be stated above
// the work rather than discovered from what it did. See harness/lib/agent.php.
$roundTrip = getenv('SPARKPOST_SUPPRESSION_ROUNDTRIP') === '1';
$remove = getenv('SPARKPOST_SUPPRESSION_DELETE') ?: null;
$refused = ($roundTrip || $remove !== null) && harness_agent_refuses('SPARKPOST_AGENT_MAY_WRITE_SUPPRESSION');

if ($refused) {
    $roundTrip = false;
    $remove = null;
}

$io->value('mode', match (true) {
    $roundTrip => 'ROUND TRIP - will add and then delete an invented address',
    $remove !== null => 'DELETING ' . $remove . ' - a real entry, for real',
    $refused => 'read only - the write switches are ignored, this is an agent session',
    default => 'read only - nothing will be changed',
});
$io->line();

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
$io->values([
    'total on the list' => $page->totalCount,
    'this page' => count($page),
    'another page' => $page->hasMore ? 'yes - rel=next was found' : 'no',
]);
$io->line();

foreach ($page as $entry) {
    $io->line(sprintf('  %-38s %-18s %s', $entry->recipient, $entry->source, $entry->created?->format('Y-m-d') ?? '?'));

    // the field that answers "why is this address not receiving mail"
    if ($entry->description !== '') {
        $io->line(sprintf('    %s', substr(str_replace(["\r", "\n"], ' ', $entry->description), 0, 100)));
    }
}

$io->line();

// The 404-is-not-an-error path, both ways round. One values() call rather than two
// value()s: these are meant to be read against each other, and values() aligns a group to
// its own widest label, so they can be written as the calls they are.
$known = $page->results[0]->recipient ?? null;

$answers = [];

if ($known !== null) {
    $answers['isSuppressed(a listed address)'] = $suppression->isSuppressed($known) ? 'true' : 'false';
}

$answers['isSuppressed(an address that is not)'] =
    $suppression->isSuppressed('definitely-not-on-the-list-9f3a@example.org') ? 'true' : 'false';

$io->values($answers);
$io->info('The second one is a real 404 from SparkPost, turned back into a plain false.');

// A full add -> read -> delete round trip, on an address invented for the purpose. This is
// how delete() gets exercised without touching an entry SparkPost put there itself.
if ($roundTrip) {
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

    // From here to the delete, every line is a live call that can throw - and a throw that
    // skipped the delete would leave this address sitting on a real suppression list with
    // nobody aware it was ever put there. So the delete goes in a finally. Note that the
    // exit() is outside it: PHP does not run finally blocks on exit(), which would undo the
    // whole point of writing one.
    $failure = null;
    $leaked = false;

    try {
        // The list is eventually consistent: the write is accepted well before it can be
        // read back. Poll rather than assume either way - and give it room, because the
        // lag is not stable. Measured at ~6-7s on 22 August 2026 and ~10.3s on the 27th,
        // against a ceiling that was 15s until the second of those left under five
        // seconds of headroom. A slower day would have printed the "still not readable"
        // warning, which reads as a finding about the API rather than about this loop.
        $started = microtime(true);
        $visible = false;

        for ($attempt = 1; $attempt <= 60; $attempt++) {
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
    } catch (ExceptionInterface $e) {
        $failure = $e;
    } finally {
        try {
            // Two value() calls and deliberately not one values(): values() builds its
            // array before it prints anything, so if the second delete threw, the first
            // one's result would never reach the screen - and "the first delete worked"
            // is exactly what you need to know at that point.
            $io->value('delete', $suppression->delete($probe) ? 'true' : 'false');
            $io->value('delete again', $suppression->delete($probe) ? 'true' : 'false');
            $io->info('The second false is the 404 path: nothing to remove is not a failure.');
        } catch (ExceptionInterface $cleanup) {
            // Loud, and naming the address, because nothing else will ever mention it again.
            $leaked = true;
            $io->error('✗ CLEANUP FAILED - ' . $cleanup::class);
            $io->value('message', $cleanup->getMessage());
            $io->error(sprintf('Remove %s from the suppression list by hand.', $probe));
        }
    }

    if ($failure !== null) {
        $io->line();
        $io->error('✗ ' . $failure::class);
        $io->value('message', $failure->getMessage());
        $io->info($leaked
            ? 'And the delete failed as well, so the address is still on the list.'
            : 'The address above was still deleted - that is what the finally is for.');
    }

    // A leak exits non-zero even when the probes themselves were fine. The messages above
    // are loud, but a zero exit is what a person skims for, and this run left litter.
    if ($failure !== null || $leaked) {
        exit(1);
    }

    $io->info('isSuppressed() may still say true for a few seconds - the same lag, backwards.');

    exit(0);
}

if ($remove === null) {
    $io->line();
    $io->info('Nothing was changed. Set SPARKPOST_SUPPRESSION_ROUNDTRIP=1 to add, read back and');
    $io->info('delete a throwaway address, or SPARKPOST_SUPPRESSION_DELETE=<address> to remove');
    $io->info('a real one, which lets SparkPost attempt delivery to it again.');

    if ($refused) {
        $io->line();
        $io->warn('One of those was set and was ignored: an agent is running this.');
        $io->info('That is the guard working. If you have been asked to write to the list,');
        $io->info('SPARKPOST_AGENT_MAY_WRITE_SUPPRESSION=1 on the command line says so - and it');
        $io->info('belongs there rather than in .env, where it would stop being a deliberate act.');
    }

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

$io->value('isSuppressed', $suppression->isSuppressed($remove) ? 'true' : 'false');
