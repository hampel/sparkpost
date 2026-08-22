<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Exception\ClientException;
use Hampel\SparkPost\Exception\ServerException;

final class SuppressionTest extends TestCase
{
    /**
     * The real shape, from a live response on 22 August 2026.
     *
     * @return array<string, mixed>
     */
    private function entry(string $recipient = 'bounced@example.com'): array
    {
        return [
            'recipient' => $recipient,
            'type' => 'transactional',
            'source' => 'Bounce Rule',
            'description' => '550: 550-5.1.1 The email account that you tried to reach does not exist.',
            'created' => '2021-08-11T00:41:44+00:00',
            'updated' => '2021-08-12T09:00:00+00:00',
            'subaccount_id' => '3629',
            'transactional' => true,
        ];
    }

    public function test_it_finds_a_suppressed_recipient_and_types_the_entry(): void
    {
        $this->client->pushJson(200, ['results' => [$this->entry()], 'links' => [], 'total_count' => 1]);

        $found = $this->sparkpost()->suppression()->find('bounced@example.com');

        $this->assertNotNull($found);
        $this->assertSame('bounced@example.com', $found->recipient);
        $this->assertSame('Bounce Rule', $found->source);
        $this->assertTrue($found->transactional);
        $this->assertSame('3629', $found->subaccountId);
        $this->assertSame('2021-08-11T00:41:44+00:00', $found->created?->format('c'));
        $this->assertStringContainsString('550-5.1.1', $found->description);

        // the untouched response is kept, so a field this class has not grown yet is reachable
        $this->assertSame($this->entry(), $found->raw);
    }

    public function test_the_recipient_is_url_encoded_into_the_path(): void
    {
        $this->client->pushJson(200, ['results' => [$this->entry('a+b@example.com')]]);

        $this->sparkpost()->suppression()->find('a+b@example.com');

        $this->assertSame(
            '/api/v1/suppression-list/a%2Bb%40example.com',
            $this->client->lastRequest()->getUri()->getPath()
        );
    }

    /**
     * The one that matters: SparkPost answers an address that is not on the list with 404,
     * so "not suppressed" arrives as an error and must not reach the caller as one.
     */
    public function test_a_recipient_that_is_not_suppressed_is_null_rather_than_an_exception(): void
    {
        $this->client->pushJson(404, ['errors' => [['message' => 'Resource could not be found']]]);

        $this->assertNull($this->sparkpost()->suppression()->find('fine@example.com'));
    }

    public function test_is_suppressed_answers_both_ways(): void
    {
        $this->client->pushJson(200, ['results' => [$this->entry()]]);
        $this->assertTrue($this->sparkpost()->suppression()->isSuppressed('bounced@example.com'));

        $this->client->pushJson(404, ['errors' => [['message' => 'Resource could not be found']]]);
        $this->assertFalse($this->sparkpost()->suppression()->isSuppressed('fine@example.com'));
    }

    /**
     * Only 404 is translated. A bad key is also a ClientException, and swallowing it would
     * report every address as unsuppressed while nothing worked at all.
     */
    public function test_a_non_404_client_error_is_still_thrown(): void
    {
        $this->client->pushJson(401, ['errors' => [['message' => 'Unauthorized']]]);

        $this->expectException(ClientException::class);
        $this->sparkpost()->suppression()->find('someone@example.com');
    }

    public function test_a_server_error_is_still_thrown(): void
    {
        $this->client->pushJson(500, ['errors' => [['message' => 'Oops']]]);

        $this->expectException(ServerException::class);
        $this->sparkpost()->suppression()->isSuppressed('someone@example.com');
    }

    public function test_delete_issues_a_delete_and_reports_that_something_went(): void
    {
        $this->client->pushRaw(204, '');

        $this->assertTrue($this->sparkpost()->suppression()->delete('bounced@example.com'));
        $this->assertSame('DELETE', $this->client->lastRequest()->getMethod());
        $this->assertSame(
            '/api/v1/suppression-list/bounced%40example.com',
            $this->client->lastRequest()->getUri()->getPath()
        );
    }

    public function test_deleting_something_that_was_never_suppressed_is_false_not_an_error(): void
    {
        $this->client->pushJson(404, ['errors' => [['message' => 'Resource could not be found']]]);

        $this->assertFalse($this->sparkpost()->suppression()->delete('fine@example.com'));
    }

    public function test_a_failed_delete_that_is_not_a_404_still_throws(): void
    {
        $this->client->pushJson(403, ['errors' => [['message' => 'Forbidden']]]);

        $this->expectException(ClientException::class);
        $this->sparkpost()->suppression()->delete('bounced@example.com');
    }
    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function page(array $entries, bool $more, int $total = 9): array
    {
        // The real link shape for this endpoint: a list of {href, rel}, not an object
        // keyed by rel. Captured from a live response rather than written from the docs.
        $links = $more
            ? [
                ['href' => '/api/v1/suppression-list?page=2&per_page=3', 'rel' => 'next'],
                ['href' => '/api/v1/suppression-list?page=3&per_page=3', 'rel' => 'last'],
            ]
            : [];

        return ['results' => $entries, 'links' => $links, 'total_count' => $total];
    }

    public function test_search_pages_by_number(): void
    {
        $this->client->pushJson(200, $this->page([$this->entry()], false));

        $page = $this->sparkpost()->suppression()->search(2, 25);

        $this->assertSame('page=2&per_page=25', $this->client->lastRequest()->getUri()->getQuery());
        $this->assertSame(9, $page->totalCount);
        $this->assertCount(1, $page);
    }

    /**
     * The reason this resource does not reuse EventPage. That class reads links['next'],
     * which does not exist here - it would find no next page, report the first one as the
     * whole list, and look exactly like success.
     */
    public function test_another_page_is_recognised_from_a_rel_next_link(): void
    {
        $this->client->pushJson(200, $this->page([$this->entry()], true));
        $this->assertTrue($this->sparkpost()->suppression()->search()->hasMore);

        $this->client->pushJson(200, $this->page([$this->entry()], false));
        $this->assertFalse($this->sparkpost()->suppression()->search()->hasMore);
    }

    public function test_the_events_style_link_object_is_not_mistaken_for_one(): void
    {
        // Guards the inverse: if this endpoint ever did answer in the events shape, saying
        // so loudly beats quietly paging somewhere unintended.
        $this->client->pushJson(200, [
            'results' => [$this->entry()],
            'links' => ['next' => '/api/v1/suppression-list?page=2'],
        ]);

        $this->assertFalse($this->sparkpost()->suppression()->search()->hasMore);
    }

    public function test_each_walks_every_page_and_stops(): void
    {
        $this->client->pushJson(200, $this->page([$this->entry('one@example.com')], true));
        $this->client->pushJson(200, $this->page([$this->entry('two@example.com')], true));
        $this->client->pushJson(200, $this->page([$this->entry('three@example.com')], false));

        $seen = [];

        foreach ($this->sparkpost()->suppression()->each(1) as $entry) {
            $seen[] = $entry->recipient;
        }

        $this->assertSame(['one@example.com', 'two@example.com', 'three@example.com'], $seen);
    }

    public function test_each_is_lazy_and_stops_making_requests_when_the_caller_does(): void
    {
        $this->client->pushJson(200, $this->page([$this->entry('one@example.com')], true));

        foreach ($this->sparkpost()->suppression()->each(1) as $entry) {
            break;
        }

        // one page fetched, and the queue still holding nothing it was not asked for
        $this->assertCount(1, $this->client->requests);
    }

    /**
     * A page claiming another page and then returning nothing would otherwise spin for
     * ever on a fault we do not control.
     */
    public function test_each_stops_on_an_empty_page_that_still_claims_more(): void
    {
        $this->client->pushJson(200, $this->page([$this->entry()], true));
        $this->client->pushJson(200, $this->page([], true));

        $seen = iterator_to_array($this->sparkpost()->suppression()->each(1), false);

        $this->assertCount(1, $seen);
        $this->assertCount(2, $this->client->requests);
    }
}
