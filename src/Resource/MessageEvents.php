<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Resource;

use Hampel\SparkPost\Connection;
use Hampel\SparkPost\MessageEvent\EventCursor;
use Hampel\SparkPost\MessageEvent\EventPage;
use Hampel\SparkPost\MessageEvent\EventQuery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * https://developers.sparkpost.com/api/events/
 *
 * Paging is the substance of this resource rather than a detail of it. There are usually
 * more events than one request should return, and often more than one request has time to
 * collect, so both ways of getting through them are first class:
 *
 *   - each() walks the lot lazily, for a script that can run to completion;
 *   - search() and next() hand back a cursor that casts to a string, for a job that has
 *     to stop and be resumed later.
 *
 * Every consumer of this API so far has had to write the second one by hand, including
 * stripping the /api/v1 prefix off the URI SparkPost returns.
 */
final class MessageEvents
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function search(?EventQuery $query = null): EventPage
    {
        $parameters = ($query ?? EventQuery::make())->toArray();

        $this->logger->debug('SparkPost message events search', $parameters);

        return EventPage::fromResponse($this->connection->get('events/message', $parameters));
    }

    /**
     * The page a cursor points at.
     */
    public function next(EventCursor $cursor): EventPage
    {
        $this->logger->debug('SparkPost message events page', ['cursor' => $cursor->uri]);

        return EventPage::fromResponse($this->connection->get($cursor->uri));
    }

    /**
     * Every event matching the query, fetching pages as it goes.
     *
     * Lazy: a page is only requested once the previous one has been consumed, so a caller
     * that stops early stops making requests.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function each(?EventQuery $query = null): \Generator
    {
        $page = $this->search($query);
        $seen = null;

        while (true) {
            yield from $page->results;

            $cursor = $page->next();

            if ($cursor === null) {
                return;
            }

            // A link that points back at the page it came from would otherwise spin
            // forever, and it is the API, not the caller, that would have caused it.
            if ($seen !== null && $cursor->equals($seen)) {
                $this->logger->warning('SparkPost returned a repeating page link; stopping', [
                    'cursor' => $cursor->uri,
                ]);

                return;
            }

            $seen = $cursor;
            $page = $this->next($cursor);
        }
    }
}
