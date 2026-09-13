<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * The caller got it wrong before we ever reached the network - an empty API key, a
 * payload that will not encode, or no PSR-17 factory passed and none installed to find.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
