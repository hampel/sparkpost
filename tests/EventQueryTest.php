<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Exception\InvalidArgumentException;
use Hampel\SparkPost\MessageEvent\BounceClass;
use Hampel\SparkPost\MessageEvent\EventQuery;
use Hampel\SparkPost\MessageEvent\EventType;
use PHPUnit\Framework\TestCase;

final class EventQueryTest extends TestCase
{
    public function test_an_empty_query_has_no_parameters(): void
    {
        $this->assertSame([], EventQuery::make()->toArray());
    }

    public function test_events_accept_the_enum_or_a_string(): void
    {
        $query = EventQuery::make()->events(EventType::Bounce, 'out_of_band');

        $this->assertSame(['events' => 'bounce,out_of_band'], $query->toArray());
    }

    public function test_it_joins_the_list_filters(): void
    {
        $query = EventQuery::make()
            ->recipients('alice@example.com', 'bob@example.com')
            ->campaignIds('spring', 'summer')
            ->transmissionIds('123')
            ->bounceClasses(BounceClass::InvalidRecipient, 22);

        $parameters = $query->toArray();

        $this->assertSame('alice@example.com,bob@example.com', $parameters['recipients']);
        $this->assertSame('spring,summer', $parameters['campaign_ids']);
        $this->assertSame('123', $parameters['transmission_ids']);
        $this->assertSame('10,22', $parameters['bounce_classes']);
    }

    public function test_an_arbitrary_filter_can_be_set(): void
    {
        $this->assertSame(['reasons' => 'mailbox full'], EventQuery::make()->filter('reasons', 'mailbox full')->toArray());
    }

    public function test_per_page_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EventQuery::make()->perPage(0);
    }
}
