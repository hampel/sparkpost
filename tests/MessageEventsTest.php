<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\MessageEvent\EventCursor;
use Hampel\SparkPost\MessageEvent\EventQuery;
use Hampel\SparkPost\MessageEvent\EventType;

final class MessageEventsTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private static function page(array $results, ?string $next = null, ?int $total = null): array
    {
        $body = ['results' => $results];

        if ($total !== null) {
            $body['total_count'] = $total;
        }

        if ($next !== null) {
            $body['links'] = ['next' => $next];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $id, string $type = 'bounce'): array
    {
        return ['event_id' => $id, 'type' => $type, 'rcpt_to' => 'alice@example.com', 'bounce_class' => '10'];
    }

    public function test_it_searches_the_message_events_endpoint(): void
    {
        $this->client->pushJson(200, self::page([]));

        $this->sparkpost()->messageEvents()->search();

        $this->assertSame(
            'https://api.sparkpost.com/api/v1/events/message',
            (string) $this->client->lastRequest()->getUri()
        );
    }

    public function test_it_sends_the_query_as_parameters(): void
    {
        $this->client->pushJson(200, self::page([]));

        $query = EventQuery::make()
            ->events(EventType::Bounce, EventType::SpamComplaint)
            ->from(new \DateTimeImmutable('2026-08-01 09:30:00', new \DateTimeZone('UTC')))
            ->to(new \DateTimeImmutable('2026-08-02 09:30:00', new \DateTimeZone('UTC')))
            ->perPage(50);

        $this->sparkpost()->messageEvents()->search($query);

        parse_str((string) $this->client->lastRequest()->getUri()->getQuery(), $parameters);

        $this->assertSame('bounce,spam_complaint', $parameters['events'] ?? null);
        $this->assertSame('2026-08-01T09:30', $parameters['from'] ?? null);
        $this->assertSame('2026-08-02T09:30', $parameters['to'] ?? null);
        $this->assertSame('50', $parameters['per_page'] ?? null);
    }

    /**
     * SparkPost's from/to carry no offset, so a local time has to be converted rather
     * than formatted as it stands - otherwise a query means something different depending
     * on where the server is.
     */
    public function test_dates_are_converted_to_utc(): void
    {
        $this->client->pushJson(200, self::page([]));

        $melbourne = new \DateTimeImmutable('2026-08-01 09:30:00', new \DateTimeZone('Australia/Melbourne'));

        $this->sparkpost()->messageEvents()->search(EventQuery::make()->from($melbourne));

        parse_str((string) $this->client->lastRequest()->getUri()->getQuery(), $parameters);

        // Melbourne is UTC+10 in August
        $this->assertSame('2026-07-31T23:30', $parameters['from'] ?? null);
    }

    public function test_it_reports_the_results_and_the_total(): void
    {
        $this->client->pushJson(200, self::page([self::event('a'), self::event('b')], total: 17));

        $page = $this->sparkpost()->messageEvents()->search();

        $this->assertCount(2, $page);
        $this->assertSame(17, $page->totalCount);
        $this->assertFalse($page->isEmpty());
        $this->assertFalse($page->hasMore());
        $this->assertNull($page->next());
        $this->assertSame('a', $page->results[0]['event_id']);
    }

    public function test_a_page_is_iterable(): void
    {
        $this->client->pushJson(200, self::page([self::event('a'), self::event('b')]));

        $ids = [];

        foreach ($this->sparkpost()->messageEvents()->search() as $event) {
            $ids[] = $event['event_id'];
        }

        $this->assertSame(['a', 'b'], $ids);
    }

    public function test_it_hands_back_a_cursor_when_there_is_more(): void
    {
        $this->client->pushJson(200, self::page([self::event('a')], next: '/api/v1/events/message?page=2&per_page=1'));

        $page = $this->sparkpost()->messageEvents()->search();

        $this->assertTrue($page->hasMore());
        $this->assertSame('/api/v1/events/message?page=2&per_page=1', (string) $page->next());
    }

    /**
     * The cursor is a string so a job can put it in its own state and come back later.
     * This is the round trip that has to survive.
     */
    public function test_a_cursor_survives_being_stored_as_a_string(): void
    {
        $this->client->pushJson(200, self::page([self::event('a')], next: '/api/v1/events/message?page=2'));
        $this->client->pushJson(200, self::page([self::event('b')]));

        $events = $this->sparkpost()->messageEvents();

        $stored = (string) $events->search()->next();

        // ... a request later, in another process
        $page = $events->next(EventCursor::fromString($stored));

        $this->assertSame('b', $page->results[0]['event_id']);
    }

    /**
     * The link SparkPost returns already carries the /api/v1 prefix that the base URI
     * ends with. Every consumer of this API has had to strip it by hand; Config::resolve()
     * is where that now happens once.
     */
    public function test_following_a_cursor_does_not_double_the_api_prefix(): void
    {
        $this->client->pushJson(200, self::page([]));

        $this->sparkpost()->messageEvents()->next(EventCursor::fromString('/api/v1/events/message?page=2'));

        $this->assertSame(
            'https://api.sparkpost.com/api/v1/events/message?page=2',
            (string) $this->client->lastRequest()->getUri()
        );
    }

    public function test_each_walks_every_page(): void
    {
        $this->client->pushJson(200, self::page([self::event('a')], next: '/api/v1/events/message?page=2'));
        $this->client->pushJson(200, self::page([self::event('b')], next: '/api/v1/events/message?page=3'));
        $this->client->pushJson(200, self::page([self::event('c')]));

        $ids = [];

        foreach ($this->sparkpost()->messageEvents()->each() as $event) {
            $ids[] = $event['event_id'];
        }

        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertCount(3, $this->client->requests);
    }

    /**
     * Lazy, so a caller that stops early stops making requests - which matters when the
     * search matches a hundred pages and the caller wanted the first ten events.
     */
    public function test_each_does_not_fetch_a_page_nobody_asked_for(): void
    {
        $this->client->pushJson(200, self::page([self::event('a'), self::event('b')], next: '/api/v1/events/message?page=2'));

        $first = null;

        foreach ($this->sparkpost()->messageEvents()->each() as $event) {
            $first = $event['event_id'];
            break;
        }

        $this->assertSame('a', $first);
        $this->assertCount(1, $this->client->requests);
    }

    public function test_each_stops_rather_than_looping_on_a_repeating_link(): void
    {
        $this->client->pushJson(200, self::page([self::event('a')], next: '/api/v1/events/message?page=2'));
        $this->client->pushJson(200, self::page([self::event('b')], next: '/api/v1/events/message?page=2'));

        $logger = new RecordingLogger();

        $ids = [];

        foreach ($this->sparkpost(null, $logger)->messageEvents()->each() as $event) {
            $ids[] = $event['event_id'];
        }

        $this->assertSame(['a', 'b'], $ids);
        $this->assertNotEmpty(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'warning'));
    }

    public function test_an_empty_result_set_is_not_an_error(): void
    {
        $this->client->pushJson(200, self::page([], total: 0));

        $page = $this->sparkpost()->messageEvents()->search();

        $this->assertTrue($page->isEmpty());
        $this->assertSame(0, $page->totalCount);
        $this->assertFalse($page->hasMore());
    }

    public function test_each_over_an_empty_result_set_yields_nothing(): void
    {
        $this->client->pushJson(200, self::page([], total: 0));

        $this->assertSame([], iterator_to_array($this->sparkpost()->messageEvents()->each()));
    }
}
