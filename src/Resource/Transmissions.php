<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Resource;

use Hampel\SparkPost\Connection;
use Hampel\SparkPost\Result\TransmissionResult;
use Hampel\SparkPost\Transmission\Transmission;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * https://developers.sparkpost.com/api/transmissions/
 */
final class Transmissions
{
    /** How many recipient addresses one log line may carry; see describe(). */
    private const RECIPIENTS_LOGGED = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Send a transmission, and report what SparkPost did with it.
     *
     * This does not throw when recipients are rejected - the API call succeeded, and what
     * counts as failure is the caller's policy. A mail transport should treat
     * wasAccepted() === false as a failed send; a bulk job may well not.
     *
     * @param  Transmission|array<mixed>  $transmission
     */
    public function send(Transmission|array $transmission): TransmissionResult
    {
        $transmission = $transmission instanceof Transmission ? $transmission->toArray() : $transmission;

        $this->logger->debug('SparkPost transmission', self::describe($transmission));

        $result = TransmissionResult::fromResponse($this->connection->post('transmissions', $transmission));

        $this->logger->debug('SparkPost transmission sent', [
            'transmission_id' => $result->id,
            'total_accepted_recipients' => $result->totalAcceptedRecipients,
            'total_rejected_recipients' => $result->totalRejectedRecipients,
        ]);

        return $result;
    }

    /**
     * What the log may carry about a transmission, which is deliberately not the payload.
     *
     * **A transmission is made of the things that must never be logged, at any level.** The
     * subject and both message bodies are the message; `substitution_data` is whatever the
     * caller put there, which may be a token; `headers` is open-ended. An email body is also
     * where a password-reset or confirmation link lives, and such a link does for whoever
     * reads the log what a password would. `debug` is not an exemption from that - a
     * development database is usually a copy of production, and a timed trial on a live site
     * writes to a store that keeps the lines for months.
     *
     * So the log gets a description of the transmission: enough to tell which send a line
     * belongs to and what shape it was, and nothing that says what it said. Logging a
     * payload wholesale is the specific habit this avoids, because the next field added to
     * the API would then be logged without anyone deciding that it should be.
     *
     * **Recipient addresses stay**, and they are the one piece of personal data here. For a
     * mail transport, which recipient a transmission went to is the whole of answering "this
     * person says they never got it", and the address is the only handle on that this package
     * has. The list is capped because a bulk send would otherwise put thousands on one line;
     * `recipient_count` is always the true number.
     *
     * @param  array<mixed>  $transmission
     * @return array<string, mixed>
     */
    private static function describe(array $transmission): array
    {
        $content = self::arrayAt($transmission, 'content');
        $options = self::arrayAt($transmission, 'options');
        $recipients = self::arrayAt($transmission, 'recipients');

        $addresses = [];

        foreach ($recipients as $recipient) {
            $address = is_array($recipient) ? ($recipient['address'] ?? null) : null;
            $email = is_array($address) ? ($address['email'] ?? null) : $address;

            if (is_string($email)) {
                $addresses[] = $email;
            }
        }

        return [
            'campaign_id' => $transmission['campaign_id'] ?? null,
            'template_id' => $content['template_id'] ?? null,
            'recipient_count' => count($recipients),
            'recipients' => array_slice($addresses, 0, self::RECIPIENTS_LOGGED),
            'transactional' => $options['transactional'] ?? null,
            'sandbox' => $options['sandbox'] ?? null,
            'attachment_count' => count(self::arrayAt($content, 'attachments')),
            'inline_image_count' => count(self::arrayAt($content, 'inline_images')),
            'has_substitution_data' => self::arrayAt($transmission, 'substitution_data') !== [],
            'return_path' => $transmission['return_path'] ?? null,
        ];
    }

    /**
     * @param  array<mixed>  $array
     * @return array<mixed>
     */
    private static function arrayAt(array $array, string $key): array
    {
        $value = $array[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
