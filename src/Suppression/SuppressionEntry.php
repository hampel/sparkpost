<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Suppression;

/**
 * One entry on the suppression list.
 *
 * Typed, where message events are deliberately not, and the difference is the point:
 * an event's shape varies enormously by type, while every suppression entry has the same
 * fields. Where the shape is stable, pinning it down costs nothing and documents the API.
 *
 * `description` is the field worth having. SparkPost puts the remote server's own
 * rejection text in it - the 550 and everything after it - which is the answer to "why is
 * this address not receiving mail", and it is otherwise only visible by reading raw JSON.
 */
final class SuppressionEntry
{
    /**
     * @param  array<string, mixed>  $raw  the entry exactly as SparkPost sent it
     */
    public function __construct(
        public readonly string $recipient,
        public readonly string $type = '',
        public readonly string $source = '',
        public readonly string $description = '',
        public readonly ?\DateTimeImmutable $created = null,
        public readonly ?\DateTimeImmutable $updated = null,
        public readonly ?string $subaccountId = null,
        public readonly bool $transactional = false,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry): self
    {
        // SparkPost sends both a `type` string and a `transactional` boolean, and they say
        // the same thing. Prefer the boolean when it is there and fall back to the string,
        // so an entry is read correctly whichever one an endpoint happens to include.
        $transactional = is_bool($entry['transactional'] ?? null)
            ? $entry['transactional']
            : (($entry['type'] ?? null) === 'transactional');

        return new self(
            is_string($entry['recipient'] ?? null) ? $entry['recipient'] : '',
            is_string($entry['type'] ?? null) ? $entry['type'] : '',
            is_string($entry['source'] ?? null) ? $entry['source'] : '',
            is_string($entry['description'] ?? null) ? $entry['description'] : '',
            self::date($entry['created'] ?? null),
            self::date($entry['updated'] ?? null),
            is_string($entry['subaccount_id'] ?? null) ? $entry['subaccount_id'] : null,
            $transactional,
            $entry,
        );
    }

    /**
     * A malformed date is null rather than fatal - the entry is still worth having, and
     * this is a report about somebody else's data.
     */
    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
