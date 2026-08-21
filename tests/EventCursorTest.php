<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\MessageEvent\EventCursor;
use PHPUnit\Framework\TestCase;

/**
 * The cursor is a string and nothing more, which is the whole point of it - a job that
 * cannot run to completion stores its place and comes back for it. These tests cover the
 * ways it gets stored, since a cursor that does not survive being written down is no use.
 */
final class EventCursorTest extends TestCase
{
    public function test_it_casts_to_a_string_and_comes_back(): void
    {
        $cursor = EventCursor::fromString('/api/v1/events/message?page=2&cursor=abc');

        $this->assertSame('/api/v1/events/message?page=2&cursor=abc', (string) $cursor);
        $this->assertTrue(EventCursor::fromString((string) $cursor)->equals($cursor));
    }

    /**
     * Job payloads are routinely JSON, so the cursor has to survive json_encode() as a
     * bare string rather than as an object with a uri key - otherwise fromString() gets
     * handed an array on the way back.
     */
    public function test_it_survives_a_round_trip_through_json(): void
    {
        $cursor = EventCursor::fromString('/api/v1/events/message?page=3');

        $encoded = json_encode(['cursor' => $cursor]);
        $this->assertSame('{"cursor":"\/api\/v1\/events\/message?page=3"}', $encoded);

        // PHPStan can see the whole round trip here, so the decoded shape needs no
        // assertion - what is worth asserting is that a bare string came back out
        $decoded = json_decode((string) $encoded, true);

        $this->assertTrue(EventCursor::fromString($decoded['cursor'])->equals($cursor));
    }

    public function test_cursors_for_different_pages_are_not_equal(): void
    {
        $this->assertFalse(
            EventCursor::fromString('/api/v1/events/message?page=2')
                ->equals(EventCursor::fromString('/api/v1/events/message?page=3'))
        );
    }
}
