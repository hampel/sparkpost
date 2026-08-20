<?php

declare(strict_types=1);

namespace Hampel\SparkPost\MessageEvent;

use Hampel\SparkPost\Exception\InvalidArgumentException;

/**
 * The filters for a message events search.
 *
 * https://developers.sparkpost.com/api/events/
 */
final class EventQuery
{
    /**
     * SparkPost's datetime format for from/to. It carries no offset, so the values are
     * always converted to UTC on the way in - which is the timezone the API assumes when
     * none is given.
     */
    private const DATE_FORMAT = 'Y-m-d\TH:i';

    /** @var list<string> */
    private array $events = [];

    private ?string $from = null;

    private ?string $to = null;

    private ?int $perPage = null;

    /** @var array<string, string> */
    private array $filters = [];

    public static function make(): self
    {
        return new self();
    }

    public function events(EventType|string ...$events): self
    {
        foreach ($events as $event) {
            $this->events[] = $event instanceof EventType ? $event->value : $event;
        }

        return $this;
    }

    public function from(\DateTimeInterface $from): self
    {
        $this->from = self::format($from);

        return $this;
    }

    public function to(\DateTimeInterface $to): self
    {
        $this->to = self::format($to);

        return $this;
    }

    public function perPage(int $perPage): self
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException('perPage must be at least 1.');
        }

        $this->perPage = $perPage;

        return $this;
    }

    public function recipients(string ...$recipients): self
    {
        return $this->filter('recipients', implode(',', $recipients));
    }

    public function campaignIds(string ...$campaignIds): self
    {
        return $this->filter('campaign_ids', implode(',', $campaignIds));
    }

    public function transmissionIds(string ...$transmissionIds): self
    {
        return $this->filter('transmission_ids', implode(',', $transmissionIds));
    }

    public function bounceClasses(BounceClass|int ...$classes): self
    {
        return $this->filter('bounce_classes', implode(',', array_map(
            static fn (BounceClass|int $class): string => (string) ($class instanceof BounceClass ? $class->value : $class),
            $classes
        )));
    }

    /**
     * Any filter this class has not grown a method for.
     */
    public function filter(string $key, string $value): self
    {
        $this->filters[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, scalar>
     */
    public function toArray(): array
    {
        $query = $this->filters;

        if ($this->events !== []) {
            $query['events'] = implode(',', $this->events);
        }

        if ($this->from !== null) {
            $query['from'] = $this->from;
        }

        if ($this->to !== null) {
            $query['to'] = $this->to;
        }

        if ($this->perPage !== null) {
            $query['per_page'] = (string) $this->perPage;
        }

        return $query;
    }

    private static function format(\DateTimeInterface $moment): string
    {
        return \DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::DATE_FORMAT);
    }
}
