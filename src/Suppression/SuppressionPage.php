<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Suppression;

/**
 * One page of suppression list entries.
 *
 * @implements \IteratorAggregate<int, SuppressionEntry>
 */
final class SuppressionPage implements \Countable, \IteratorAggregate
{
    /**
     * @param  list<SuppressionEntry>  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly ?int $totalCount = null,
        public readonly bool $hasMore = false,
    ) {
    }

    /**
     * @param  array<mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        $results = [];

        if (isset($body['results']) && is_array($body['results'])) {
            foreach ($body['results'] as $entry) {
                if (is_array($entry)) {
                    /** @var array<string, mixed> $entry */
                    $results[] = SuppressionEntry::fromArray($entry);
                }
            }
        }

        $totalCount = isset($body['total_count']) && is_numeric($body['total_count'])
            ? (int) $body['total_count']
            : null;

        return new self($results, $totalCount, self::hasNextLink($body['links'] ?? null));
    }

    /**
     * This endpoint does NOT return links in the shape the events endpoint does, and the
     * difference is invisible until it silently costs you every page after the first.
     *
     *   events:      {"next": "/api/v1/events/message?..."}
     *   suppression: [{"href": "...", "rel": "next"}, {"href": "...", "rel": "last"}]
     *
     * So a list of objects to search by `rel`, not a lookup by key. Reusing EventPage here
     * would find no `next` key, report a single page, and stop - which looks exactly like
     * having read the whole list.
     */
    private static function hasNextLink(mixed $links): bool
    {
        if (!is_array($links)) {
            return false;
        }

        foreach ($links as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'next') {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return \ArrayIterator<int, SuppressionEntry>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }
}
