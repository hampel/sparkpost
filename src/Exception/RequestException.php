<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

/**
 * The request never reached SparkPost - DNS, TLS, connect timeout, a proxy refusing it.
 *
 * Distinct from ApiException on purpose: SparkPost has not seen this request, so retrying
 * it cannot duplicate anything.
 */
final class RequestException extends SparkPostException
{
    public static function for(string $method, string $uri, \Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach SparkPost (%s %s): %s', $method, $uri, $previous->getMessage()),
            0,
            $previous
        );
    }
}
