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
     * Reach into a decoded structure by dotted path.
     *
     * Every nested read into a decoded JSON body or a log context is an offset access on
     * mixed, which PHPStan rejects at level 10 - correctly, since nothing guarantees the
     * shape. This keeps the assertions readable and gives them one place to be honest
     * about that: a path that is not there comes back null and the assertion fails.
     *
     * @param  array<mixed>|null  $data
     */
    protected static function path(?array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
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
