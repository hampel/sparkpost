<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\InvalidArgumentException;
use Hampel\SparkPost\SparkPost;
use Hampel\SparkPost\Support\Psr17Discovery;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

final class Psr17DiscoveryTest extends TestCase
{
    public function test_sparkpost_can_be_built_without_naming_a_factory(): void
    {
        $sparkpost = new SparkPost(new Config('a-key'), $this->client);

        // POST, so both the request factory and the stream factory are exercised end to end
        $this->client->pushJson(200, ['results' => ['ok' => true]]);
        $sparkpost->connection()->post('transmissions', ['campaign_id' => 'found']);

        $this->assertSame('POST', $this->client->lastRequest()->getMethod());
        $this->assertSame(['campaign_id' => 'found'], $this->sentBody());
    }

    public function test_on_this_machine_it_finds_guzzle(): void
    {
        [$request, $stream] = Psr17Discovery::find();

        $this->assertInstanceOf(HttpFactory::class, $request);
        $this->assertSame($request, $stream, 'Guzzle ships one class for both roles; it should be built once.');
    }

    /**
     * Given one and not the other, the given one is used and only the gap is filled.
     */
    public function test_a_factory_that_is_given_is_used_rather_than_discovered(): void
    {
        $given = new class () implements RequestFactoryInterface {
            /**
             * @param  UriInterface|string  $uri
             */
            public function createRequest(string $method, $uri): RequestInterface
            {
                return (new HttpFactory())->createRequest($method, $uri)->withHeader('X-Given-Factory', 'yes');
            }
        };

        $sparkpost = new SparkPost(new Config('a-key'), $this->client, $given);

        $this->client->pushJson(200, ['results' => ['ok' => true]]);
        $sparkpost->connection()->post('transmissions', ['campaign_id' => 'given']);

        $this->assertSame('yes', $this->client->lastRequest()->getHeaderLine('X-Given-Factory'));
        $this->assertSame(['campaign_id' => 'given'], $this->sentBody());
    }

    /**
     * The not-found path cannot be reached with Guzzle installed, so it is reached through
     * the list instead.
     */
    public function test_nothing_found_says_what_to_pass_and_what_to_install(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(RequestFactoryInterface::class);
        $this->expectExceptionMessage('guzzlehttp/psr7');

        Psr17Discovery::from([['No\Such\Factory', 'No\Such\Factory']]);
    }

    /**
     * A class that exists but is not a factory is skipped, not returned - the instanceof is
     * what makes discovery by name safe.
     */
    public function test_a_class_that_exists_but_is_not_a_factory_is_skipped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Psr17Discovery::from([[\stdClass::class, \stdClass::class]]);
    }

    public function test_later_candidates_are_tried_when_earlier_ones_are_absent(): void
    {
        [$request] = Psr17Discovery::from([
            ['No\Such\Factory', 'No\Such\Factory'],
            [\stdClass::class, \stdClass::class],
            [HttpFactory::class, HttpFactory::class],
        ]);

        $this->assertInstanceOf(HttpFactory::class, $request);
    }
}
