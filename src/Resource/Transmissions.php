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

        $this->logger->debug('SparkPost transmission', self::redact($transmission));

        return TransmissionResult::fromResponse($this->connection->post('transmissions', $transmission));
    }

    /**
     * Attachments are base64 in the payload. Left alone they turn a debug log into
     * megabytes of noise, so truncate them on the way past - the log wants to show that
     * an attachment was there, not what was in it.
     *
     * @param  array<mixed>  $transmission
     * @return array<mixed>
     */
    private static function redact(array $transmission): array
    {
        $content = $transmission['content'] ?? null;

        if (!is_array($content)) {
            return $transmission;
        }

        foreach (['attachments', 'inline_images'] as $key) {
            $files = $content[$key] ?? null;

            if (is_array($files)) {
                $content[$key] = array_map(self::truncate(...), $files);
            }
        }

        $transmission['content'] = $content;

        return $transmission;
    }

    private static function truncate(mixed $file): mixed
    {
        if (is_array($file) && isset($file['data']) && is_string($file['data']) && strlen($file['data']) > 100) {
            $file['data'] = '<<<truncated>>>';
        }

        return $file;
    }
}
