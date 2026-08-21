<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Config;
use Hampel\SparkPost\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_it_defaults_to_the_global_host(): void
    {
        $this->assertSame('https://api.sparkpost.com/api/v1', (new Config('key'))->baseUri);
    }

    public function test_it_builds_a_regional_host(): void
    {
        $this->assertSame('https://api.eu.sparkpost.com/api/v1', Config::forRegion('key', 'eu')->baseUri);
    }

    public function test_an_empty_region_is_the_global_host(): void
    {
        $this->assertSame('https://api.sparkpost.com/api/v1', Config::forRegion('key', '  ')->baseUri);
    }

    public function test_a_custom_base_uri_loses_its_trailing_slash(): void
    {
        $this->assertSame('http://localhost:8080/api/v1', (new Config('key', 'http://localhost:8080/api/v1/'))->baseUri);
    }

    public function test_it_rejects_an_empty_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('   ');
    }

    #[DataProvider('paths')]
    public function test_it_resolves_a_path(string $path, string $expected): void
    {
        $this->assertSame($expected, (new Config('key'))->resolve($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function paths(): array
    {
        return [
            'bare' => ['transmissions', 'https://api.sparkpost.com/api/v1/transmissions'],
            'leading slash' => ['/transmissions', 'https://api.sparkpost.com/api/v1/transmissions'],
            // the shape SparkPost hands back in links.next
            'already prefixed' => ['/api/v1/events/message?page=2', 'https://api.sparkpost.com/api/v1/events/message?page=2'],
            'absolute' => ['https://api.eu.sparkpost.com/api/v1/ping', 'https://api.eu.sparkpost.com/api/v1/ping'],
        ];
    }

    public function test_it_appends_query_parameters(): void
    {
        $this->assertSame(
            'https://api.sparkpost.com/api/v1/events/message?per_page=10',
            (new Config('key'))->resolve('events/message', ['per_page' => 10])
        );
    }

    public function test_it_merges_query_parameters_into_a_path_that_already_has_some(): void
    {
        $this->assertSame(
            'https://api.sparkpost.com/api/v1/events/message?page=2&per_page=10',
            (new Config('key'))->resolve('/api/v1/events/message?page=2', ['per_page' => 10])
        );
    }

    /**
     * host() is for anything that has to name the endpoint without making a request - a
     * transport's __toString(), a log line, a settings screen.
     */
    public function test_it_reports_the_host_for_the_default_and_for_a_region(): void
    {
        $this->assertSame('api.sparkpost.com', (new Config('key'))->host());
        $this->assertSame('api.eu.sparkpost.com', Config::forRegion('key', 'eu')->host());
    }
}
