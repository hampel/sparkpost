<?php

/**
 * Exercise: read the sending domains, and derive the subaccount from one.
 *
 * Read-only throughout - there is nothing here that can change the account, which is the
 * point. An API key able to write sending domains can redirect where an account's mail
 * appears to come from, so this package only reads them and the key only needs the read
 * grant. Verified 22 August 2026: with sending domains set to read-only, GET works and
 * POST comes back 403.
 *
 * The reason it exists is the last line of output. Suppression is per-subaccount, and a
 * subaccount API key cannot read the subaccounts endpoint at all - SparkPost has no such
 * permission for one. The sending domain carries `subaccount_id`, so going from the address
 * an application sends as to the subaccount it is operating in is the only route available.
 *
 * Needs SPARKPOST_API_KEY. Uses SPARKPOST_FROM when it is set.
 *
 * @var Hampel\Rig\Io $io
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\ExceptionInterface;
use Hampel\SparkPost\SparkPost;

$io->title('sparkpost · sending domains');

$key = getenv('SPARKPOST_API_KEY');

if ($key === false || $key === '') {
    $io->error('SPARKPOST_API_KEY is not set. Copy .env.example to .env beside the package.');

    exit(1);
}

$factory = new HttpFactory();
$sparkpost = new SparkPost(Config::forRegion($key, getenv('SPARKPOST_REGION') ?: null), new Client(), $factory, $factory);

try {
    $domains = $sparkpost->sendingDomains()->all();
} catch (ExceptionInterface $e) {
    $io->error('✗ ' . $e::class);
    $io->value('message', $e->getMessage());
    $io->info('A 403 here means the key has no sending domains grant. Read is enough.');

    exit(1);
}

$io->success(sprintf('✓ %d sending domain(s)', count($domains)));
$io->line();

foreach ($domains as $domain) {
    $io->line(sprintf(
        '  %-34s subaccount %-8s %s%s',
        $domain->domain,
        $domain->subaccountId ?? '-',
        ($domain->status['ownership_verified'] ?? false) ? 'verified' : 'unverified',
        $domain->isDefaultBounceDomain ? '  [default bounce domain]' : ''
    ));
}

$io->line();

// The flow this resource exists for: an address is all an application knows, and the
// subaccount is what suppression needs.
$from = getenv('SPARKPOST_FROM') ?: null;

if ($from === null) {
    $io->info('Set SPARKPOST_FROM to see the address-to-subaccount lookup.');

    exit(0);
}

$io->value('from', $from);
$found = $sparkpost->sendingDomains()->forAddress($from);

if ($found === null) {
    $io->warn('Not a sending domain on this account - which is why a send from it is refused.');

    exit(0);
}

$io->success(sprintf('✓ %s', $found->domain));
$io->value('subaccount', $found->hasSubaccount() ? (string) $found->subaccountId : 'none - the primary account');
$io->info('That is the number suppression is scoped by.');
