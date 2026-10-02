<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * SparkPost answered, and the answer was not a success.
 */
abstract class ApiException extends SparkPostException
{
    /** How much of an unparseable body the message may quote; see summarise(). */
    private const DETAIL_LENGTH = 200;

    /**
     * @param  list<array<string, mixed>>  $errors  the API's own errors[], decoded
     * @param  string  $body  the raw response body, for when it was not JSON at all
     */
    final public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $errors = [],
        public readonly string $body = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param  array<mixed>|null  $decoded  the decoded body, or null if it was not JSON
     */
    public static function fromResponse(
        string $method,
        string $uri,
        ResponseInterface $response,
        ?array $decoded,
        string $body,
    ): self {
        $status = $response->getStatusCode();
        $errors = self::extractErrors($decoded);

        $detail = $errors !== []
            ? self::describe($errors)
            : self::summarise($body);

        $message = sprintf(
            'SparkPost rejected %s %s (HTTP %d)%s',
            $method,
            $uri,
            $status,
            $detail === '' ? '' : ': ' . $detail
        );

        $retryAfter = self::retryAfter($response);

        return match (true) {
            $status === 429 => new RateLimitException($message, $status, $errors, $body, $retryAfter),
            $status >= 500 => new ServerException($message, $status, $errors, $body, $retryAfter),
            default => new ClientException($message, $status, $errors, $body, $retryAfter),
        };
    }

    /**
     * A body that did not parse, trimmed to a length an exception message can carry.
     *
     * The whole body stays on $body, where a caller that wants it knows what it is asking
     * for. The message is the part that gets logged by anything catching this and writing
     * `['exception' => $e]`, and the body is of unknown origin and unknown size - an error
     * page from whatever answered. A first line is enough to tell a gateway failure from a
     * SparkPost refusal; the rest is of no use in a log and may be of no use anywhere.
     */
    private static function summarise(string $body): string
    {
        $body = trim($body);

        return strlen($body) > self::DETAIL_LENGTH
            ? substr($body, 0, self::DETAIL_LENGTH) . '...'
            : $body;
    }

    /**
     * @param  array<mixed>|null  $decoded
     * @return list<array<string, mixed>>
     */
    private static function extractErrors(?array $decoded): array
    {
        if (!isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return [];
        }

        $errors = [];

        foreach ($decoded['errors'] as $error) {
            if (is_array($error)) {
                /** @var array<string, mixed> $error */
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     */
    private static function describe(array $errors): string
    {
        $described = [];

        foreach ($errors as $error) {
            $parts = [];

            foreach (['message', 'description'] as $key) {
                if (isset($error[$key]) && is_scalar($error[$key]) && (string) $error[$key] !== '') {
                    $parts[] = (string) $error[$key];
                }
            }

            if ($parts !== []) {
                $described[] = implode(' ', $parts);
            }
        }

        return implode('; ', $described);
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }
}
