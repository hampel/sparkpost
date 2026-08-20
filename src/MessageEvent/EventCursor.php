<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * A position in a message events search, which is a string and nothing more.
 *
 * That is the whole design. Fetching events takes longer than one request is allowed to
 * live, so the work has to be picked up again later - in a queue job, a cron run, a
 * resumable task - and whatever does that needs somewhere to keep its place. A cursor
 * casts to a string, so it can be stored anywhere a string can, and comes back with
 * fromString().
 *
 * The URI SparkPost hands back carries the /api/v1 prefix that the configured base URI
 * also ends with; Config::resolve() sorts that out, so it can be passed straight through.
 */
final class EventCursor implements \JsonSerializable, \Stringable
{
    public function __construct(public readonly string $uri)
    {
    }

    public static function fromString(string $uri): self
    {
        return new self($uri);
    }

    public function __toString(): string
    {
        return $this->uri;
    }

    public function jsonSerialize(): string
    {
        return $this->uri;
    }

    public function equals(self $other): bool
    {
        return $this->uri === $other->uri;
    }
}
