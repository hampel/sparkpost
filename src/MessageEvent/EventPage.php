<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * One page of message events, and where the next one is.
 *
 * Events are plain arrays rather than typed objects: their shape varies a great deal by
 * event type, and pinning it down would mean either a lowest-common-denominator type that
 * hides most of the payload, or twenty of them.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class EventPage implements \Countable, \IteratorAggregate
{
    /**
     * @param  list<array<string, mixed>>  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly ?int $totalCount = null,
        private readonly ?EventCursor $next = null,
    ) {
    }

    /**
     * @param  array<mixed>  $body
     */
    public static function fromResponse(array $body): self
    {
        $results = [];

        if (isset($body['results']) && is_array($body['results'])) {
            foreach ($body['results'] as $event) {
                if (is_array($event)) {
                    /** @var array<string, mixed> $event */
                    $results[] = $event;
                }
            }
        }

        $totalCount = isset($body['total_count']) && is_numeric($body['total_count'])
            ? (int) $body['total_count']
            : null;

        $next = null;

        if (isset($body['links']) && is_array($body['links'])
            && isset($body['links']['next']) && is_string($body['links']['next'])
            && $body['links']['next'] !== '') {
            $next = new EventCursor($body['links']['next']);
        }

        return new self($results, $totalCount, $next);
    }

    /**
     * Where the next page is, or null if this was the last one.
     */
    public function next(): ?EventCursor
    {
        return $this->next;
    }

    public function hasMore(): bool
    {
        return $this->next !== null;
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
     * @return \ArrayIterator<int, array<string, mixed>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }
}
