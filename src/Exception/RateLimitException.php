<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * A 429. Worth its own type because it is the one 4xx that is worth retrying, and callers
 * branch on it - a background job reschedules itself, a queue worker releases with a delay.
 *
 * The empty body is not an empty type: the payload is inherited. $statusCode, $errors,
 * $body and $retryAfter are all promoted properties on ApiException, two levels up.
 * $retryAfter carries the Retry-After header when SparkPost sent a numeric one, and is
 * null when it did not - so a caller needs its own fallback delay either way.
 */
final class RateLimitException extends ClientException
{
}
