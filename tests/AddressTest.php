<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Transmission\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function test_a_bare_address_formats_as_itself(): void
    {
        $this->assertSame('alice@example.com', (new Address('alice@example.com'))->format());
    }

    public function test_a_named_address_formats_in_display_form(): void
    {
        $this->assertSame('Alice <alice@example.com>', (new Address('alice@example.com', 'Alice'))->format());
    }

    public function test_the_name_key_is_omitted_rather_than_empty(): void
    {
        $this->assertSame(['email' => 'alice@example.com'], (new Address('alice@example.com'))->toArray());
    }

    public function test_a_list_is_comma_separated(): void
    {
        $this->assertSame(
            'Alice <alice@example.com>, bob@example.com',
            Address::formatList([new Address('alice@example.com', 'Alice'), new Address('bob@example.com')])
        );
    }

    public function test_an_empty_list_formats_as_an_empty_string(): void
    {
        $this->assertSame('', Address::formatList([]));
    }
}
