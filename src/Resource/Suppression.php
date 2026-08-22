<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Resource;

use Hampel\SparkPost\Connection;
use Hampel\SparkPost\Exception\ClientException;
use Hampel\SparkPost\Suppression\SuppressionEntry;
use Hampel\SparkPost\Suppression\SuppressionPage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * https://developers.sparkpost.com/api/suppression-list/
 *
 * Two questions drive this resource, and everything here serves one of them:
 *
 *   1. is this address suppressed, and why?   isSuppressed(), find()
 *   2. take it off the list.                  delete()
 *
 * The second matters because SparkPost's suppression list and an application's own idea of
 * who may be emailed are two lists that drift apart. SparkPost suppresses on a hard bounce
 * by itself; when the address is later fixed and the application re-enables the user, the
 * suppression is still there and the mail silently does not send. Nothing in the sending
 * path reports that - it is not an error, the message is simply dropped.
 *
 * Inserting is deliberately absent. Putting an address ON the list expresses a policy about
 * who may be emailed, and that belongs to the application; the two operations here are
 * about SparkPost's own state.
 */
final class Suppression
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Page-based rather than cursor-based on purpose. This endpoint offers both, but the
     * cursor tokens run to well over a kilobyte and a suppression list is small - paging by
     * number is easier to log, easier to resume and easier to read.
     */
    public function search(int $page = 1, int $perPage = 100): SuppressionPage
    {
        $parameters = ['page' => max(1, $page), 'per_page' => max(1, $perPage)];

        $this->logger->debug('SparkPost suppression search', $parameters);

        return SuppressionPage::fromResponse($this->connection->get('suppression-list', $parameters));
    }

    /**
     * Every entry, a page at a time and only as far as it is consumed.
     *
     * @return \Generator<int, SuppressionEntry>
     */
    public function each(int $perPage = 100): \Generator
    {
        $page = 1;

        while (true) {
            $result = $this->search($page, $perPage);

            yield from $result->results;

            // isEmpty() as well as hasMore(): a page that reports another page and then
            // returns nothing would otherwise spin forever on somebody else's bug.
            if (!$result->hasMore || $result->isEmpty()) {
                return;
            }

            $page++;
        }
    }

    /**
     * The entry for one address, or null when it is not suppressed.
     *
     * Null rather than an exception is the whole design of this method. SparkPost answers a
     * recipient that is not on the list with 404, so "this address is fine" arrives as an
     * error - and making a caller catch ClientException to hear good news would also have
     * them swallowing a 401 from a bad key. Only 404 is translated; everything else is
     * still thrown.
     */
    public function find(string $recipient): ?SuppressionEntry
    {
        $this->logger->debug('SparkPost suppression lookup', ['recipient' => $recipient]);

        try {
            $body = $this->connection->get('suppression-list/' . rawurlencode($recipient));
        } catch (ClientException $e) {
            if ($e->statusCode === 404) {
                return null;
            }

            throw $e;
        }

        $entry = (is_array($body['results'] ?? null) ? ($body['results'][0] ?? null) : null);

        /** @var array<string, mixed>|null $entry */
        return is_array($entry) ? SuppressionEntry::fromArray($entry) : null;
    }

    public function isSuppressed(string $recipient): bool
    {
        return $this->find($recipient) !== null;
    }

    /**
     * Remove an address, so mail to it is attempted again.
     *
     * Returns false when there was nothing to remove, on the same reasoning as find():
     * deleting an address that was never suppressed is the outcome the caller wanted, not
     * a failure. Anything other than a 404 still throws.
     */
    public function delete(string $recipient): bool
    {
        $this->logger->debug('SparkPost suppression delete', ['recipient' => $recipient]);

        try {
            $this->connection->delete('suppression-list/' . rawurlencode($recipient));
        } catch (ClientException $e) {
            if ($e->statusCode === 404) {
                $this->logger->debug('SparkPost suppression delete: not on the list', ['recipient' => $recipient]);

                return false;
            }

            throw $e;
        }

        return true;
    }
}
