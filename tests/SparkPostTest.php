<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Config;
use Hampel\SparkPost\Connection;

final class SparkPostTest extends TestCase
{
    public function test_it_hands_back_the_config_it_was_given(): void
    {
        $config = new Config('a-key');

        $this->assertSame($config, $this->sparkpost($config)->config());
    }

    /**
     * The escape hatch: an endpoint with no Resource yet is a call away rather than a
     * release away, so it has to actually work rather than merely exist.
     */
    public function test_the_connection_is_exposed_and_usable_directly(): void
    {
        $sparkpost = $this->sparkpost();
        $this->client->pushJson(200, ['results' => ['ok' => true]]);

        $body = $sparkpost->connection()->get('suppression-list', ['limit' => 50]);

        $this->assertSame(['results' => ['ok' => true]], $body);

        $request = $this->client->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/api/v1/suppression-list', $request->getUri()->getPath());
        $this->assertSame('limit=50', $request->getUri()->getQuery());
    }

    /**
     * One Connection, built once. Resources are memoised for the same reason, and a fresh
     * Connection per call would quietly discard whatever the client is holding.
     */
    public function test_the_connection_and_resources_are_memoised(): void
    {
        $sparkpost = $this->sparkpost();

        $this->assertInstanceOf(Connection::class, $sparkpost->connection());
        $this->assertSame($sparkpost->connection(), $sparkpost->connection());
        $this->assertSame($sparkpost->transmissions(), $sparkpost->transmissions());
        $this->assertSame($sparkpost->messageEvents(), $sparkpost->messageEvents());
    }
}
