<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * SparkPost's bounce classification codes.
 *
 * These are SparkPost's own numbers and meanings, which is why they belong here. What an
 * application does about each one - disable the account, stop one kind of email, ignore
 * it - is that application's policy and belongs to it.
 *
 * The 21-code table these were taken from was published at
 * support.sparkpost.com/docs/tech-resources/bounce-classification-codes. That URL now
 * redirects to bird.com and the table is gone; what replaced it is a coarser rollup at
 * https://bird.com/docs/guides/email/events#bounce-classification, which agrees with
 * every classification below that it lists and simply omits 26, 60, 80 and 90. Checked
 * 2026-09-03 - do not "correct" a case against that page alone, it is not the same table.
 */
enum BounceClass: int
{
    case Undetermined = 1;
    case InvalidRecipient = 10;
    case SoftBounce = 20;
    case DnsFailure = 21;
    case MailboxFull = 22;
    case TooLarge = 23;
    case Timeout = 24;
    case AdminFailure = 25;
    case SmartSendSuppression = 26;
    case GenericBounceNoRcpt = 30;
    case GenericBounce = 40;
    case MailBlock = 50;
    case SpamBlock = 51;
    case SpamContent = 52;
    case ProhibitedAttachment = 53;
    case RelayingDenied = 54;
    case AutoReply = 60;
    case TransientFailure = 70;
    case Subscribe = 80;
    case Unsubscribe = 90;
    case ChallengeResponse = 100;

    public function classification(): BounceClassification
    {
        return match ($this) {
            self::InvalidRecipient,
            self::GenericBounceNoRcpt,

            // Delivered, then opted out - so not a delivery failure, and Informational
            // would be the honest description of it. It stays Hard because Hard is the
            // one classification isPermanent() reports true for, and "stop sending to
            // this address" is exactly the right consequence here. Reclassifying it
            // would be accurate about the delivery and wrong about what to do next.
            self::Unsubscribe => BounceClassification::Hard,

            self::SoftBounce,
            self::DnsFailure,
            self::MailboxFull,
            self::TooLarge,
            self::Timeout,
            self::GenericBounce,
            self::TransientFailure,

            // Challenge-response is a real failure: the mailbox held the message pending
            // a challenge the sender never answered, so it did not reach anyone. Bird's
            // current table lists 100 as soft, which agrees.
            self::ChallengeResponse => BounceClassification::Soft,

            self::MailBlock,
            self::SpamBlock,
            self::SpamContent,
            self::ProhibitedAttachment,
            self::RelayingDenied => BounceClassification::Block,

            self::AdminFailure,
            self::SmartSendSuppression => BounceClassification::Admin,

            // Both describe a message that arrived and drew a reply, which is why they
            // are not grouped with the failures. See BounceClassification::Informational.
            self::AutoReply,
            self::Subscribe => BounceClassification::Informational,

            self::Undetermined => BounceClassification::Undetermined,
        };
    }

    /**
     * SparkPost's own name for the class, in the snake_case its documentation uses.
     */
    public function slug(): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $this->name));
    }
}
