<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * A 429. Worth its own type because it is the one 4xx that is worth retrying, and callers
 * branch on it - a background job reschedules itself, a queue worker releases with a delay.
 *
 * $retryAfter carries the header when SparkPost sent one.
 */
final class RateLimitException extends ClientException
{
}
