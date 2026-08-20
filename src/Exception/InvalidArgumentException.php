<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * The caller got it wrong before we ever reached the network - an empty API key, a
 * payload that will not encode.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
