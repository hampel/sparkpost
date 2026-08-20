<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * A 4xx. The request was wrong, or the key was - retrying it unchanged will fail again.
 */
class ClientException extends ApiException
{
}
