<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Result;

/**
 * What SparkPost did with a transmission.
 *
 * Worth having as a type rather than an array because of one trap: SparkPost returns
 * HTTP 200 having accepted zero recipients, so a caller that checks only the status code
 * reports a send that never happened. wasAccepted() is the check that matters.
 */
final class TransmissionResult
{
    public function __construct(
        public readonly string $id,
        public readonly int $totalAcceptedRecipients,
        public readonly int $totalRejectedRecipients,
    ) {
    }

    /**
     * @param  array<mixed>  $body  the decoded response body
     */
    public static function fromResponse(array $body): self
    {
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : [];

        return new self(
            isset($results['id']) && is_scalar($results['id']) ? (string) $results['id'] : '',
            isset($results['total_accepted_recipients']) && is_numeric($results['total_accepted_recipients'])
                ? (int) $results['total_accepted_recipients']
                : 0,
            isset($results['total_rejected_recipients']) && is_numeric($results['total_rejected_recipients'])
                ? (int) $results['total_rejected_recipients']
                : 0,
        );
    }

    public function totalRecipients(): int
    {
        return $this->totalAcceptedRecipients + $this->totalRejectedRecipients;
    }

    public function hasRejections(): bool
    {
        return $this->totalRejectedRecipients > 0;
    }

    public function wasAccepted(): bool
    {
        return $this->totalAcceptedRecipients > 0;
    }
}
