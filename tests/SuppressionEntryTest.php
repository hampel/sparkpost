<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Suppression\SuppressionEntry;
use Hampel\SparkPost\Suppression\SuppressionPage;
use PHPUnit\Framework\TestCase;

/**
 * The defensive half. Everything here is about a response that is not shaped the way the
 * live API shaped it on the day this was written - a field missing, a date that will not
 * parse, links absent entirely. None of it should be fatal: this is a report about
 * somebody else's data, and an entry with a bad date is still worth having.
 */
final class SuppressionEntryTest extends TestCase
{
    public function test_transactional_falls_back_to_the_type_string_when_the_boolean_is_absent(): void
    {
        // Both fields say the same thing and not every endpoint sends both, so the string
        // has to be readable on its own.
        $this->assertTrue(SuppressionEntry::fromArray([
            'recipient' => 'a@example.com',
            'type' => 'transactional',
        ])->transactional);

        $this->assertFalse(SuppressionEntry::fromArray([
            'recipient' => 'a@example.com',
            'type' => 'non_transactional',
        ])->transactional);
    }

    public function test_the_boolean_wins_when_both_are_present(): void
    {
        $entry = SuppressionEntry::fromArray([
            'recipient' => 'a@example.com',
            'type' => 'non_transactional',
            'transactional' => true,
        ]);

        $this->assertTrue($entry->transactional);
    }

    public function test_missing_fields_become_empty_rather_than_fatal(): void
    {
        $entry = SuppressionEntry::fromArray(['recipient' => 'a@example.com']);

        $this->assertSame('a@example.com', $entry->recipient);
        $this->assertSame('', $entry->type);
        $this->assertSame('', $entry->source);
        $this->assertSame('', $entry->description);
        $this->assertNull($entry->created);
        $this->assertNull($entry->updated);
        $this->assertNull($entry->subaccountId);
    }

    public function test_a_date_that_will_not_parse_is_null(): void
    {
        $entry = SuppressionEntry::fromArray([
            'recipient' => 'a@example.com',
            'created' => 'the day before yesterday-ish',
            'updated' => '',
        ]);

        $this->assertNull($entry->created);
        $this->assertNull($entry->updated);
    }

    public function test_a_date_that_is_not_even_a_string_is_null(): void
    {
        $entry = SuppressionEntry::fromArray(['recipient' => 'a@example.com', 'created' => 1628642504]);

        $this->assertNull($entry->created);
    }

    public function test_a_page_with_no_links_at_all_has_no_next(): void
    {
        $page = SuppressionPage::fromResponse(['results' => [['recipient' => 'a@example.com']]]);

        $this->assertFalse($page->hasMore);
        $this->assertNull($page->totalCount);
        $this->assertCount(1, $page);
        $this->assertFalse($page->isEmpty());
    }

    public function test_a_page_iterates_its_entries(): void
    {
        $page = SuppressionPage::fromResponse([
            'results' => [['recipient' => 'a@example.com'], ['recipient' => 'b@example.com'], 'not an entry'],
        ]);

        $recipients = [];

        foreach ($page as $entry) {
            $recipients[] = $entry->recipient;
        }

        // the string in results is skipped rather than crashing the page
        $this->assertSame(['a@example.com', 'b@example.com'], $recipients);
    }

    public function test_an_empty_page_reports_itself_empty(): void
    {
        $page = SuppressionPage::fromResponse(['results' => [], 'total_count' => 0]);

        $this->assertTrue($page->isEmpty());
        $this->assertCount(0, $page);
        $this->assertSame(0, $page->totalCount);
    }
}
