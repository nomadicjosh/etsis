<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;

/** An approved visibility interval; null boundaries preserve immediate, ongoing publication. */
final readonly class PublicationWindow
{
    public ?DateTimeImmutable $from;
    public ?DateTimeImmutable $until;

    public function __construct(?string $from, ?string $until)
    {
        $this->from = self::date($from);
        $this->until = self::date($until);
        if ($this->from !== null && $this->until !== null && $this->until <= $this->from) {
            self::invalid("The end must be later than the publication start.");
        }
    }

    public function data(): array
    {
        return [
            "visible_from" => $this->from?->format("Y-m-d\TH:i:s\Z"),
            "visible_until" => $this->until?->format("Y-m-d\TH:i:s\Z"),
        ];
    }

    public function visible(DateTimeImmutable $now): bool
    {
        return ($this->from === null || $this->from <= $now) &&
        ($this->until === null || $this->until > $now);
    }

    public function requireFuture(DateTimeImmutable $now): void
    {
        if (
            ($this->from !== null && $this->from <= $now) ||
            ($this->until !== null && $this->until <= $now)
        ) {
            self::invalid("Choose future publication times, or publish immediately without a start.");
        }
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
                self::invalid("Choose a date and time with an explicit time zone.");
            }
            $date = new DateTimeImmutable($value);
            if (DateTimeImmutable::getLastErrors() !== false) {
                self::invalid("Choose valid publication dates.");
            }
            return $date->setTimezone(new DateTimeZone("UTC"));
        } catch (\Exception) {
            self::invalid("Choose valid publication dates with an explicit time zone.");
        }
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "publication_window", "message" => $message]]);
    }
}
