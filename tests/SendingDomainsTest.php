<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Exception\ClientException;

final class SendingDomainsTest extends TestCase
{
    /**
     * A list row, exactly as the live API returned one on 22 August 2026.
     *
     * @return array<string, mixed>
     */
    private function row(string $domain = 'mail.example.com', int $subaccount = 123): array
    {
        return [
            'creation_time' => '2026-08-12T23:41:18+00:00',
            'is_default_bounce_domain' => false,
            'subaccount_id' => $subaccount,
            'domain' => $domain,
            'status' => ['ownership_verified' => true, 'dkim_status' => 'unverified'],
        ];
    }

    public function test_it_lists_the_domains_with_their_subaccount(): void
    {
        $this->client->pushJson(200, ['results' => [$this->row(), $this->row('other.example.com', 0)]]);

        $domains = $this->sparkpost()->sendingDomains()->all();

        $this->assertCount(2, $domains);
        $this->assertSame('mail.example.com', $domains[0]->domain);
        $this->assertSame(123, $domains[0]->subaccountId);
        $this->assertTrue($domains[0]->hasSubaccount());
        $this->assertSame('2026-08-12T23:41:18+00:00', $domains[0]->created?->format('c'));

        // subaccount 0 is the primary account, not a subaccount
        $this->assertSame(0, $domains[1]->subaccountId);
        $this->assertFalse($domains[1]->hasSubaccount());
    }

    /**
     * The single-domain response is NOT the same shape as a list row: it leaves out
     * `domain`, because that is in the URL, and adds `dkim`. Taken from a live response -
     * without passing the requested domain through, find() returns an object whose own
     * name is an empty string.
     */
    public function test_the_single_domain_response_keeps_the_domain_it_was_asked_for(): void
    {
        $this->client->pushJson(200, ['results' => [
            'dkim' => ['selector' => 'scph0820', 'signing_domain' => ''],
            'creation_time' => '2026-08-12T23:41:18+00:00',
            'is_default_bounce_domain' => false,
            'subaccount_id' => 123,
            'status' => ['ownership_verified' => true],
        ]]);

        $domain = $this->sparkpost()->sendingDomains()->find('mail.example.com');

        $this->assertNotNull($domain);
        $this->assertSame('mail.example.com', $domain->domain);
        $this->assertSame(123, $domain->subaccountId);
        $this->assertSame('scph0820', $domain->dkim['selector'] ?? null);
        $this->assertSame('/api/v1/sending-domains/mail.example.com', $this->client->lastRequest()->getUri()->getPath());
    }

    public function test_a_domain_the_account_does_not_have_is_null(): void
    {
        $this->client->pushJson(404, ['errors' => [['message' => 'Resource could not be found']]]);

        $this->assertNull($this->sparkpost()->sendingDomains()->find('not-ours.example.com'));
    }

    public function test_a_non_404_is_still_thrown(): void
    {
        $this->client->pushJson(403, ['errors' => [['message' => 'Forbidden.']]]);

        $this->expectException(ClientException::class);
        $this->sparkpost()->sendingDomains()->find('mail.example.com');
    }

    public function test_it_finds_the_domain_behind_a_bare_address(): void
    {
        $this->client->pushJson(200, ['results' => $this->row()]);

        $this->assertNotNull($this->sparkpost()->sendingDomains()->forAddress('noreply@mail.example.com'));
        $this->assertSame('/api/v1/sending-domains/mail.example.com', $this->client->lastRequest()->getUri()->getPath());
    }

    /**
     * A configured From is as likely to carry a display name as not, and taking everything
     * after the last @ keeps the closing bracket - which then gets asked for as a domain.
     */
    public function test_it_reads_through_the_display_name_form(): void
    {
        $this->client->pushJson(200, ['results' => $this->row()]);

        $this->assertNotNull($this->sparkpost()->sendingDomains()->forAddress('Bounces <noreply@mail.example.com>'));
        $this->assertSame('/api/v1/sending-domains/mail.example.com', $this->client->lastRequest()->getUri()->getPath());
    }

    public function test_something_that_is_not_an_address_is_null_without_a_request(): void
    {
        $sendingDomains = $this->sparkpost()->sendingDomains();

        $this->assertNull($sendingDomains->forAddress('not-an-address'));
        $this->assertNull($sendingDomains->forAddress('trailing@'));
        $this->assertNull($sendingDomains->forAddress('two words@example com'));
        $this->assertNull($sendingDomains->forAddress(''));

        // nothing was asked of SparkPost on the way to any of those
        $this->assertSame([], $this->client->requests);
    }

    public function test_a_row_missing_everything_optional_still_builds(): void
    {
        $this->client->pushJson(200, ['results' => [['domain' => 'bare.example.com']]]);

        $domains = $this->sparkpost()->sendingDomains()->all();

        $this->assertSame('bare.example.com', $domains[0]->domain);
        $this->assertNull($domains[0]->subaccountId);
        $this->assertFalse($domains[0]->hasSubaccount());
        $this->assertNull($domains[0]->created);
        $this->assertSame([], $domains[0]->status);
    }

    public function test_an_unparseable_creation_time_is_null(): void
    {
        $this->client->pushJson(200, ['results' => [['domain' => 'a.example.com', 'creation_time' => 'whenever']]]);

        $this->assertNull($this->sparkpost()->sendingDomains()->all()[0]->created);
    }
}
