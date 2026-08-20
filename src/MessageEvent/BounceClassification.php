<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

/**
 * What kind of failure a bounce class represents, which is what decides how to respond.
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

    case Undetermined = 'undetermined';

    /**
     * Whether continuing to send to this address is pointless.
     */
    public function isPermanent(): bool
    {
        return $this === self::Hard;
    }
}
