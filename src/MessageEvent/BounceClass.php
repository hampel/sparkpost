<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * SparkPost's bounce classification codes.
 *
 * https://support.sparkpost.com/docs/tech-resources/bounce-classification-codes
 *
 * These are SparkPost's own numbers and meanings, which is why they belong here. What an
 * application does about each one - disable the account, stop one kind of email, ignore
 * it - is that application's policy and belongs to it.
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
            self::Unsubscribe => BounceClassification::Hard,

            self::SoftBounce,
            self::DnsFailure,
            self::MailboxFull,
            self::TooLarge,
            self::Timeout,
            self::GenericBounce,
            self::AutoReply,
            self::TransientFailure,
            self::ChallengeResponse => BounceClassification::Soft,

            self::MailBlock,
            self::SpamBlock,
            self::SpamContent,
            self::ProhibitedAttachment,
            self::RelayingDenied => BounceClassification::Block,

            self::AdminFailure,
            self::SmartSendSuppression,
            self::Subscribe => BounceClassification::Admin,

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
