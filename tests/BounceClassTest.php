<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use Hampel\SparkPost\MessageEvent\BounceClass;
use Hampel\SparkPost\MessageEvent\BounceClassification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BounceClassTest extends TestCase
{
    #[DataProvider('classifications')]
    public function test_it_classifies_a_bounce_class(int $code, BounceClassification $expected): void
    {
        $class = BounceClass::from($code);

        $this->assertSame($expected, $class->classification());
    }

    /**
     * @return array<string, array{int, BounceClassification}>
     */
    public static function classifications(): array
    {
        return [
            'undetermined' => [1, BounceClassification::Undetermined],
            'invalid recipient is permanent' => [10, BounceClassification::Hard],
            'mailbox full is temporary' => [22, BounceClassification::Soft],
            'admin failure' => [25, BounceClassification::Admin],
            'no rcpt is permanent' => [30, BounceClassification::Hard],
            'generic bounce is temporary' => [40, BounceClassification::Soft],
            'spam block' => [51, BounceClassification::Block],
            'auto reply is temporary' => [60, BounceClassification::Soft],
            'subscribe is administrative' => [80, BounceClassification::Admin],
            'unsubscribe is permanent' => [90, BounceClassification::Hard],
            'challenge response is temporary' => [100, BounceClassification::Soft],
        ];
    }

    public function test_every_class_has_a_classification(): void
    {
        foreach (BounceClass::cases() as $class) {
            $this->assertInstanceOf(BounceClassification::class, $class->classification());
        }
    }

    public function test_only_a_hard_bounce_is_permanent(): void
    {
        $this->assertTrue(BounceClass::InvalidRecipient->classification()->isPermanent());
        $this->assertFalse(BounceClass::MailboxFull->classification()->isPermanent());
        $this->assertFalse(BounceClass::SpamBlock->classification()->isPermanent());
    }

    public function test_it_reports_sparkposts_own_name_for_the_class(): void
    {
        $this->assertSame('invalid_recipient', BounceClass::InvalidRecipient->slug());
        $this->assertSame('generic_bounce_no_rcpt', BounceClass::GenericBounceNoRcpt->slug());
        $this->assertSame('dns_failure', BounceClass::DnsFailure->slug());
        $this->assertSame('undetermined', BounceClass::Undetermined->slug());
    }

    /**
     * SparkPost sends bounce_class as a *string* in an event payload, and may add codes
     * this enum has not heard of. Both are why a consumer reads one with tryFrom() on an
     * int cast rather than from().
     */
    #[DataProvider('payloadCodes')]
    public function test_a_bounce_class_is_read_from_an_event_payload(string $raw, ?BounceClass $expected): void
    {
        $this->assertSame($expected, BounceClass::tryFrom((int) $raw));
    }

    /**
     * @return array<string, array{string, BounceClass|null}>
     */
    public static function payloadCodes(): array
    {
        return [
            'a code we know' => ['10', BounceClass::InvalidRecipient],
            'a code SparkPost added later' => ['999', null],
            'no bounce class at all' => ['', null],
        ];
    }
}
