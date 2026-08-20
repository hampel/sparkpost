<?php

declare(strict_types=1);

namespace Hampel\SparkPost;

use Hampel\SparkPost\Exception\InvalidArgumentException;

/**
 * Where the API lives, and the key we present to it.
 */
final class Config
{
    public const DEFAULT_HOST = 'api.sparkpost.com';

    public const API_PREFIX = 'api/v1';

    public readonly string $baseUri;

    public function __construct(
        public readonly string $apiKey,
        ?string $baseUri = null,
    ) {
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('A SparkPost API key is required.');
        }

        $this->baseUri = rtrim($baseUri ?? self::hostUri(self::DEFAULT_HOST), '/');
    }

    /**
     * SparkPost's EU tenancy lives on api.eu.sparkpost.com; everything else is the
     * default host.
     */
    public static function forRegion(string $apiKey, ?string $region = null): self
    {
        $region = trim($region ?? '');

        $host = $region === ''
            ? self::DEFAULT_HOST
            : sprintf('api.%s.sparkpost.com', $region);

        return new self($apiKey, self::hostUri($host));
    }

    /**
     * Turn a path into an absolute URI.
     *
     * Three shapes arrive here, and the third is the one that matters: SparkPost's
     * pagination links come back as `/api/v1/events/message?...`, already carrying the
     * version prefix that baseUri also ends with. Handling that here is what lets a
     * caller feed `links.next` straight back in, instead of stripping the prefix at the
     * call site the way every consumer of this API has had to so far.
     *
     * @param  array<string, scalar>  $query
     */
    public function resolve(string $path, array $query = []): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $uri = $path;
        } else {
            $path = ltrim($path, '/');

            if (str_starts_with($path, self::API_PREFIX . '/')) {
                $path = substr($path, strlen(self::API_PREFIX) + 1);
            }

            $uri = $this->baseUri . '/' . $path;
        }

        if ($query !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query);
        }

        return $uri;
    }

    /**
     * The host the client will talk to, for anything that needs to name the endpoint - a
     * transport's __toString(), a log line, a settings screen.
     */
    public function host(): string
    {
        return parse_url($this->baseUri, PHP_URL_HOST) ?: self::DEFAULT_HOST;
    }

    private static function hostUri(string $host): string
    {
        return sprintf('https://%s/%s', $host, self::API_PREFIX);
    }
}
