<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\SparkPost;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
    use InspectsPayloads;

    protected StubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new StubClient();
    }

    protected function sparkpost(?Config $config = null, ?LoggerInterface $logger = null): SparkPost
    {
        $factory = new HttpFactory();

        return new SparkPost(
            $config ?? new Config('test-api-key'),
            $this->client,
            $factory,
            $factory,
            $logger ?? new NullLogger()
        );
    }
    /**
     * The body of the last request the stub client was given, decoded.
     *
     * @return array<mixed>
     */
    protected function sentBody(): array
    {
        $decoded = json_decode((string) $this->client->lastRequest()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
