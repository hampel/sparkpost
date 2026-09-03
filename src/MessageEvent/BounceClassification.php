<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * What kind of event a bounce class represents, which is what decides how to respond.
 *
 * Four of these are failures. Informational is not, and it is the one to notice - it
 * exists so that a match() over this enum cannot silently act against a recipient whose
 * message was delivered.
 */
enum BounceClassification: string
{
    /** The address will never accept mail. Stop sending to it. */
    case Hard = 'hard';

    /** A temporary condition - a full mailbox, a timeout. Worth trying again. */
    case Soft = 'soft';

    /** The receiver refused the message rather than the address, usually as spam. */
    case Block = 'block';

    /** SparkPost itself declined to send, or the recipient is suppressed. */
    case Admin = 'admin';

    /**
     * The message was delivered, and the recipient's system answered. Nothing failed and
     * there is nothing to act on - note it if it is useful, and carry on.
     *
     * Two classes land here, and both are worth surfacing rather than swallowing:
     * AutoReply (60) is a vacation responder, and Subscribe (80) is someone opting back
     * in, which is the one thing arriving on the bounce channel that is good news.
     *
     * **This grouping is ours, not SparkPost's.** Their table put 60 under soft and 80
     * under admin - faithful to the SMTP exchange, and misleading about what to do with
     * it, because the obvious match() arm then treats an opt-in as a delivery failure.
     * Do not go looking for 'informational' in SparkPost's documentation; it is not there.
     */
    case Informational = 'informational';

    /** The response arrived on the bounce channel and could not be read either way. */
    case Undetermined = 'undetermined';

    /**
     * Whether continuing to send to this address is pointless.
     */
    public function isPermanent(): bool
    {
        return $this === self::Hard;
    }
}
