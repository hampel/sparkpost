<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * A 5xx. SparkPost's problem, probably temporary, and the natural candidate for a retry.
 */
final class ServerException extends ApiException
{
}
