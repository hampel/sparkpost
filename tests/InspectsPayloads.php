<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Tests;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Reach into a built payload by dotted path.
 *
 * Every nested read into a payload or a decoded body is an offset access on mixed, which
 * PHPStan rejects at level 10 - correctly, since nothing guarantees the shape. This keeps
 * the assertions readable and gives them one honest place to admit that: a path that is
 * not there comes back null, and the assertion fails.
 */
trait InspectsPayloads
{
    /**
     * @param  array<mixed>|null  $data
     */
    protected static function path(?array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * The value at $path, when it must be an array - counting it or encoding it otherwise
     * means casting mixed, which hides a wrong path instead of reporting one.
     *
     * @param  array<mixed>|null  $data
     * @return array<mixed>
     */
    protected static function arrayAt(?array $data, string $path): array
    {
        $value = self::path($data, $path);

        if (!is_array($value)) {
            throw new AssertionFailedError(sprintf('Expected an array at "%s", found %s.', $path, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    protected static function asJson(array $data): string
    {
        return (string) json_encode($data, JSON_THROW_ON_ERROR);
    }
}
