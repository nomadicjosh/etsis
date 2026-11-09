<?php

declare(strict_types=1);

namespace Domain\Student;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use DomainException;
use InvalidArgumentException;
use JsonException;

final readonly class StudentHiatus
{
    public function __construct(
        public EntityId $id,
        public EntityId $studentId,
        public HiatusCode $code,
        public DateRange $dates,
        public bool $voided = false,
        public int $version = 0,
    ) {
        if ($version < 0) {
            throw new InvalidArgumentException("A nonnegative hiatus version is required.");
        }
    }

    public function withEnd(DateTimeImmutable $on): self
    {
        if ($this->voided) {
            throw new DomainException("A voided hiatus is historical.");
        }
        if ($on->format("Y-m-d") < $this->dates->start->format("Y-m-d")) {
            throw new ValidationException([
                ["field" => "ends_on", "message" => "Use an end date on or after the start."],
            ]);
        }
        return new self(
            $this->id,
            $this->studentId,
            $this->code,
            new DateRange($this->dates->start->setTime(0, 0), $on->setTime(0, 0)),
            false,
            $this->version + 1,
        );
    }

    public function void(): self
    {
        if ($this->voided) {
            throw new DomainException("This hiatus has already been voided.");
        }
        return new self($this->id, $this->studentId, $this->code, $this->dates, true, $this->version + 1);
    }

    public function state(DateTimeImmutable $on): string
    {
        if ($this->voided) {
            return "voided";
        }
        $date = $on->format("Y-m-d");
        return $date < $this->dates->start->format("Y-m-d")
        ? "scheduled"
        : ($this->dates->end !== null && $date > $this->dates->end->format("Y-m-d")
                ? "ended"
                : "active");
    }

    /**
     * @throws JsonException
     */
    public function revision(): string
    {
        return hash(
            "sha256",
            json_encode(
                [
                    $this->id->value,
                    $this->studentId->value,
                    $this->code->value,
                    $this->dates->start->format("Y-m-d"),
                    $this->dates->end?->format("Y-m-d"),
                    $this->voided,
                    $this->version,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
