<?php

declare(strict_types=1);

namespace Hampel\SparkPost\SendingDomain;

/**
 * A sending domain, and the account it belongs to.
 *
 * The reason this is here is `subaccountId`. Suppression is per-subaccount, and an
 * application that knows only the address it sends from has no other way to work out which
 * subaccount that is - the sending domain carries it, and nothing else the API offers to a
 * subaccount key does.
 *
 * `status` and `dkim` stay as arrays. They are diagnostic detail with a shape SparkPost
 * extends over time, and every field in them is a string a human reads rather than
 * something a caller branches on.
 */
final class SendingDomain
{
    /**
     * status and dkim are array<mixed> rather than array<string, mixed> because that is
     * all a decoded JSON value can be shown to be - claiming string keys here would be an
     * assertion rather than a fact.
     *
     * @param  array<mixed>  $status
     * @param  array<mixed>  $dkim
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $domain,
        public readonly ?int $subaccountId = null,
        public readonly bool $isDefaultBounceDomain = false,
        public readonly ?\DateTimeImmutable $created = null,
        public readonly array $status = [],
        public readonly array $dkim = [],
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  string  $domain  what was asked for, because the single-domain response does
     *                          not repeat it - it is in the URL. Without this, find() hands
     *                          back a domain that does not know its own name.
     */
    public static function fromArray(array $row, string $domain = ''): self
    {
        $created = null;

        if (is_string($row['creation_time'] ?? null) && $row['creation_time'] !== '') {
            try {
                $created = new \DateTimeImmutable($row['creation_time']);
            } catch (\Exception) {
                $created = null;
            }
        }

        return new self(
            is_string($row['domain'] ?? null) && $row['domain'] !== '' ? $row['domain'] : $domain,
            is_numeric($row['subaccount_id'] ?? null) ? (int) $row['subaccount_id'] : null,
            (bool) ($row['is_default_bounce_domain'] ?? false),
            $created,
            is_array($row['status'] ?? null) ? $row['status'] : [],
            is_array($row['dkim'] ?? null) ? $row['dkim'] : [],
            $row,
        );
    }

    /**
     * Whether this domain sits under a subaccount at all. Zero and absent both mean the
     * primary account; SparkPost uses 0 for it in some responses and omits the key in
     * others.
     */
    public function hasSubaccount(): bool
    {
        return $this->subaccountId !== null && $this->subaccountId > 0;
    }
}
