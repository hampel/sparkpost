<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Resource;

use Hampel\SparkPost\Connection;
use Hampel\SparkPost\Exception\ClientException;
use Hampel\SparkPost\SendingDomain\SendingDomain;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * https://developers.sparkpost.com/api/sending-domains/
 *
 * Read-only, deliberately. Creating and verifying a sending domain is an account
 * administration task done once, in SparkPost's own UI, by someone looking at DNS records
 * - not something an application does at runtime. An API key that can write here can
 * redirect where an account's mail appears to come from, so the narrow grant is worth
 * keeping narrow.
 *
 * What this is actually for is `subaccount_id`. Suppression is per-subaccount, and an
 * application that knows only the address it sends from needs some way to find out which
 * subaccount it is operating in. A subaccount API key cannot read the subaccounts endpoint
 * at all - SparkPost offers no such permission for one - so the sending domain is the only
 * route to that number.
 */
final class SendingDomains
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return list<SendingDomain>
     */
    public function all(): array
    {
        $this->logger->debug('SparkPost sending domains list');

        $body = $this->connection->get('sending-domains');
        $domains = [];

        if (isset($body['results']) && is_array($body['results'])) {
            foreach ($body['results'] as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    $domains[] = SendingDomain::fromArray($row);
                }
            }
        }

        return $domains;
    }

    /**
     * One domain, or null when the account does not have it.
     *
     * Null rather than an exception for the same reason as Suppression::find(): "not one of
     * ours" is an ordinary answer, and only the 404 is translated.
     */
    public function find(string $domain): ?SendingDomain
    {
        $this->logger->debug('SparkPost sending domain lookup', ['domain' => $domain]);

        try {
            $body = $this->connection->get('sending-domains/' . rawurlencode($domain));
        } catch (ClientException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }

        $row = $body['results'] ?? null;

        // The single-domain response omits `domain` - it is in the URL - and adds `dkim`,
        // so the list rows and this one are not the same shape. Pass what was asked for.
        /** @var array<string, mixed>|null $row */
        return is_array($row) ? SendingDomain::fromArray($row, $domain) : null;
    }

    /**
     * The sending domain behind an email address, which is the way in when all an
     * application knows is who it sends as.
     *
     * Accepts the display-name form as well as a bare address, because a configured From is
     * as likely to be `Support <noreply@example.com>` as not. Taking everything after the
     * last @ is not enough on its own - it keeps the closing bracket, and the lookup then
     * asks for a domain that has one in it.
     */
    public function forAddress(string $address): ?SendingDomain
    {
        $address = trim($address);

        // Prefer what is inside the angle brackets when there are any.
        if (preg_match('/<([^<>]+)>\s*$/', $address, $matches) === 1) {
            $address = trim($matches[1]);
        }

        $at = strrpos($address, '@');

        if ($at === false) {
            return null;
        }

        $domain = trim(substr($address, $at + 1));

        // Anything left that cannot be a hostname means this was not an address we can read
        // - better to say so than to ask SparkPost about it.
        if ($domain === '' || preg_match('/^[A-Za-z0-9.-]+$/', $domain) !== 1) {
            return null;
        }

        return $this->find($domain);
    }
}
