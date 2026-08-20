<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transmission;

/**
 * An email address, and the display name that may go with it.
 */
final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
    }

    /**
     * RFC 5322 display form: `Alice <alice@example.com>`, or the bare address when there
     * is no name. This is what SparkPost expects for header_to and reply_to, both of
     * which are strings rather than structured addresses.
     */
    public function format(): string
    {
        return $this->name === '' ? $this->email : sprintf('%s <%s>', $this->name, $this->email);
    }

    /**
     * @return array{email: string, name?: string}
     */
    public function toArray(): array
    {
        return $this->name === ''
            ? ['email' => $this->email]
            : ['email' => $this->email, 'name' => $this->name];
    }

    /**
     * @param  list<self>  $addresses
     */
    public static function formatList(array $addresses): string
    {
        return implode(', ', array_map(static fn (self $address): string => $address->format(), $addresses));
    }
}
