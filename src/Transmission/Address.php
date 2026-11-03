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
     * is no name. This is what SparkPost expects for header_to, reply_to and the CC
     * header, all of which are strings rather than structured addresses.
     *
     * A name holding any of RFC 5322's specials is written as a quoted-string, with `\`
     * and `"` escaped inside it. Unquoted, `Smith, John <x@example.com>` is two addresses
     * to a receiver - "Smith", and "John" at x@example.com - and a list of them is joined
     * with the same comma. A name with no specials is left bare, as it always was.
     */
    public function format(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        $name = preg_match('/[()<>\[\]:;@\\,."]/', $this->name) === 1
            ? '"' . addcslashes($this->name, '\\"') . '"'
            : $this->name;

        return sprintf('%s <%s>', $name, $this->email);
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
