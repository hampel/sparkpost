<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\Exception\InvalidArgumentException;
use Hampel\SparkPost\Transmission\Address;
use Hampel\SparkPost\Transmission\Transmission;
use PHPUnit\Framework\TestCase;

final class TransmissionTest extends TestCase
{
    use InspectsPayloads;

    private function minimal(): Transmission
    {
        return Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hello')
            ->text('Body.')
            ->to('alice@example.com');
    }

    public function test_it_refuses_to_build_without_a_recipient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one recipient');

        Transmission::make()->from('webmaster@example.com')->subject('Hi')->text('Body.')->toArray();
    }

    public function test_a_cc_alone_is_enough_to_be_a_recipient(): void
    {
        $payload = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hi')
            ->text('Body.')
            ->cc('bob@example.com')
            ->toArray();

        $this->assertCount(1, self::arrayAt($payload, 'recipients'));
        // no To at all, so there is no To: line to reproduce and the name form is used
        $this->assertSame(['email' => 'bob@example.com'], self::path($payload, 'recipients.0.address'));
    }

    /**
     * The bug this replaces: array_filter() with its default callback drops false, which
     * silently turns "do not track opens" into "use the account default".
     */
    public function test_false_options_survive_into_the_payload(): void
    {
        $payload = $this->minimal()->openTracking(false)->clickTracking(false)->transactional(false)->toArray();

        $this->assertSame(
            ['open_tracking' => false, 'click_tracking' => false, 'transactional' => false],
            self::path($payload, 'options')
        );
    }

    public function test_options_that_were_never_set_are_absent(): void
    {
        $this->assertArrayNotHasKey('options', $this->minimal()->toArray());
    }

    public function test_the_sandbox_domain_switches_the_option_on_by_itself(): void
    {
        $payload = Transmission::make()
            ->from('test@sparkpostbox.com')
            ->subject('Hi')
            ->text('Body.')
            ->to('alice@example.com')
            ->toArray();

        $this->assertTrue(self::path($payload, 'options.sandbox'));
    }

    public function test_the_sandbox_option_can_be_forced_off_for_a_sandbox_sender(): void
    {
        $payload = Transmission::make()
            ->from('test@sparkpostbox.com')
            ->subject('Hi')
            ->text('Body.')
            ->to('alice@example.com')
            ->sandbox(false)
            ->toArray();

        $this->assertArrayNotHasKey('options', $payload);
    }

    public function test_the_sandbox_option_can_be_forced_on_for_any_sender(): void
    {
        $this->assertTrue(self::path($this->minimal()->sandbox()->toArray(), 'options.sandbox'));
    }

    public function test_it_carries_the_top_level_transmission_fields(): void
    {
        $payload = $this->minimal()
            ->campaignId('spring')
            ->description('Spring campaign')
            ->metadata(['user_id' => 7])
            ->substitutionData(['first_name' => 'Alice'])
            ->returnPath('bounces@example.com')
            ->toArray();

        $this->assertSame('spring', self::path($payload, 'campaign_id'));
        $this->assertSame('Spring campaign', self::path($payload, 'description'));
        $this->assertSame(['user_id' => 7], self::path($payload, 'metadata'));
        $this->assertSame(['first_name' => 'Alice'], self::path($payload, 'substitution_data'));
        $this->assertSame('bounces@example.com', self::path($payload, 'return_path'));
    }

    public function test_an_arbitrary_option_can_be_set(): void
    {
        $payload = $this->minimal()->option('ip_pool', 'marketing')->toArray();

        $this->assertSame('marketing', self::path($payload, 'options.ip_pool'));
    }

    public function test_a_template_replaces_the_content_entirely(): void
    {
        $payload = $this->minimal()->template('welcome')->toArray();

        $this->assertSame(['template_id' => 'welcome', 'use_draft_template' => false], self::path($payload, 'content'));
    }

    public function test_a_draft_template(): void
    {
        $payload = $this->minimal()->template('welcome', true)->toArray();

        $this->assertTrue(self::path($payload, 'content.use_draft_template'));
    }

    public function test_an_ab_test_replaces_the_content_entirely(): void
    {
        $this->assertSame(['ab_test_id' => 'subject-line'], self::path($this->minimal()->abTest('subject-line')->toArray(), 'content'));
    }

    public function test_content_can_be_set_verbatim(): void
    {
        $this->assertSame(['email_rfc822' => 'raw'], self::path($this->minimal()->content(['email_rfc822' => 'raw'])->toArray(), 'content'));
    }

    public function test_bcc_never_appears_in_the_headers(): void
    {
        $payload = $this->minimal()->cc('bob@example.com', 'Bob')->bcc('carol@example.com', 'Carol')->toArray();

        $this->assertSame('Bob <bob@example.com>', self::path($payload, 'content.headers.CC'));
        $this->assertStringNotContainsString('carol', self::asJson(self::arrayAt($payload, 'content.headers')));
        // but Carol is still sent to
        $this->assertCount(3, self::arrayAt($payload, 'recipients'));
    }

    public function test_disallowed_headers_are_dropped_case_insensitively(): void
    {
        $payload = $this->minimal()->header('MESSAGE-ID', 'x')->header('Content-Type', 'x')->header('X-Keep', 'y')->toArray();

        $this->assertSame(['X-Keep' => 'y'], self::path($payload, 'content.headers'));
    }
    public function test_deliver_to_replaces_the_recipients_but_not_the_headers(): void
    {
        $payload = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hi')
            ->text('Body.')
            ->to('alice@example.com', 'Alice')
            ->cc('bob@example.com', 'Bob')
            ->deliverTo([new Address('sink@example.test')])
            ->toArray();

        // delivery goes to the sink alone
        $this->assertSame(
            [['address' => ['email' => 'sink@example.test', 'header_to' => 'Alice <alice@example.com>']]],
            self::path($payload, 'recipients')
        );

        // but the message still reads as though it were addressed normally
        $this->assertSame('Bob <bob@example.com>', self::path($payload, 'content.headers.CC'));
    }

    public function test_deliver_to_alone_satisfies_the_recipient_requirement(): void
    {
        $payload = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hi')
            ->text('Body.')
            ->deliverTo([new Address('sink@example.test')])
            ->toArray();

        $this->assertSame([['address' => ['email' => 'sink@example.test']]], self::path($payload, 'recipients'));
    }

    /**
     * The whole payload at once, for the message that puts the most rules in contact.
     *
     * Every rule below is already asserted on its own above, so this deliberately covers
     * the same ground a second time. What it adds is the shape: assertSame compares key
     * order and, more to the point, notices a field that silently appears or disappears -
     * which a per-field assertion structurally cannot, because it only ever looks where it
     * is told.
     *
     * Four recipients from a To of two, a Cc and a Bcc; all four carrying the same
     * header_to built from the To list alone; the CC header that is what makes Bob visible
     * while Carol stays blind; and the three options that had to survive being false.
     *
     * The expected array is written out here rather than loaded from a fixture on purpose.
     * A golden file gets regenerated from the code under test the moment it fails, which
     * certifies nothing; this one has to be read and edited by hand, in the same diff as
     * whatever changed the builder.
     */
    public function test_the_whole_payload_for_a_message_with_to_cc_and_bcc(): void
    {
        $payload = Transmission::make()
            ->from('webmaster@example.com')
            ->subject('Hello')
            ->text('Body.')
            ->to('alice@example.com', 'Alice')
            ->to('amy@example.com')
            ->cc('bob@example.com', 'Bob')
            ->bcc('carol@example.com')
            ->openTracking(false)
            ->clickTracking(false)
            ->transactional()
            ->toArray();

        $headerTo = 'Alice <alice@example.com>, amy@example.com';

        $this->assertSame([
            'options' => [
                'open_tracking' => false,
                'click_tracking' => false,
                'transactional' => true,
            ],
            'recipients' => [
                ['address' => ['email' => 'alice@example.com', 'header_to' => $headerTo]],
                ['address' => ['email' => 'amy@example.com', 'header_to' => $headerTo]],
                ['address' => ['email' => 'bob@example.com', 'header_to' => $headerTo]],
                ['address' => ['email' => 'carol@example.com', 'header_to' => $headerTo]],
            ],
            'content' => [
                'from' => ['email' => 'webmaster@example.com'],
                'subject' => 'Hello',
                'text' => 'Body.',
                'headers' => ['CC' => 'Bob <bob@example.com>'],
            ],
        ], $payload);
    }
}
