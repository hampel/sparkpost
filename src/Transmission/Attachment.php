<?php

declare(strict_types=1);

namespace Hampel\SparkPost\Transmission;

use Hampel\SparkPost\Exception\InvalidArgumentException;

/**
 * A file on a transmission - either an ordinary attachment or an inline image.
 *
 * Holds raw bytes and base64-encodes on the way out, so nothing has to remember which
 * side of the encoding it is holding.
 */
final class Attachment
{
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $data,
        public readonly bool $inline,
    ) {
    }

    public static function fromData(string $name, string $type, string $data): self
    {
        return new self($name, $type, $data, false);
    }

    public static function fromPath(string $path, ?string $name = null, string $type = 'application/octet-stream'): self
    {
        $data = @file_get_contents($path);

        if ($data === false) {
            throw new InvalidArgumentException(sprintf('Could not read the attachment at "%s".', $path));
        }

        return new self($name ?? basename($path), $type, $data, false);
    }

    /**
     * An image referenced from the HTML body by `cid:`.
     *
     * $cid is what SparkPost matches against, so it must be exactly what the `src` refers
     * to - `<img src="cid:logo">` needs a $cid of `logo`.
     */
    public static function inline(string $cid, string $type, string $data): self
    {
        return new self($cid, $type, $data, true);
    }

    /**
     * @return array{name: string, type: string, data: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'data' => base64_encode($this->data),
        ];
    }
}
