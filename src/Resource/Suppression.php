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
 * add() exists for the third case - putting an address on deliberately - and is mostly
 * there so the two above can be exercised against the real API without touching an entry
 * SparkPost put there itself. Driving application policy through it, mirroring every
 * unsubscribe into SparkPost, is a decision for the application rather than something this
 * class encourages.
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
     * Put an address on the list.
     *
     * This is a PUT and therefore an upsert: an address already on the list has its entry
     * replaced rather than being rejected, so check with isSuppressed() first if you would
     * rather not overwrite what SparkPost recorded for it.
     *
     * **The list is eventually consistent.** Measured against the live API, an added address
     * took seconds rather than milliseconds to become readable, and a deleted one stayed
     * readable for a similar stretch after the delete succeeded. The lag is not a constant -
     * repeat measurements varied by a factor of two - so treat it as an order of magnitude.
     * add() returning true means SparkPost accepted the write, not that isSuppressed() will
     * agree yet. Nothing here retries on your behalf - a caller that needs to see the change
     * has to poll, and hiding that behind a sleep would make every genuine miss slow.
     *
     * `list_id` is not exposed: it addresses SparkPost's own mailing lists, which nothing
     * using this package has.
     */
    public function add(string $recipient, string $description = '', bool $transactional = true): bool
    {
        $payload = ['type' => $transactional ? 'transactional' : 'non_transactional'];

        if ($description !== '') {
            $payload['description'] = $description;
        }

        $this->logger->debug('SparkPost suppression add', ['recipient' => $recipient] + $payload);

        $this->connection->put('suppression-list/' . rawurlencode($recipient), $payload);

        return true;
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
