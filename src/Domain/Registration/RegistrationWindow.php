<?php

declare(strict_types=1);

namespace Domain\Registration;

use DateTimeImmutable;
use Domain\Shared\ValueObject\DateRange;
use InvalidArgumentException;

final readonly class RegistrationWindow
{
    public function __construct(
        public DateRange $dates,
        public int $courseLimit,
        public ?array $reference = null,
    ) {
        if ($reference !== null) {
            \Domain\Registration\ValueObject\RegistrationWindowDefinition::reference(
                $reference,
            );
        }
        if ($dates->end === null || $courseLimit < 1 || $courseLimit > 1000) {
            throw new InvalidArgumentException(
                "A finite registration window and positive course limit are required.",
            );
        }
    }

    public function values(): array
    {
        return [
            "opens_at" => \Domain\Registration\ValueObject\EnrollmentFacts::utc(
                $this->dates->start,
            ),
            "closes_at" => \Domain\Registration\ValueObject\EnrollmentFacts::utc(
                $this->dates->end,
            ),
            "course_limit" => $this->courseLimit,
        ];
    }
    public function fingerprint(): string
    {
        return hash(
            "sha256",
            json_encode(
                $this->values() + ["reference" => $this->reference],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    public function summary(): array
    {
        return [
            "opens_at" => $this->dates->start->format("Y-m-d\\TH:i:sP"),
            "closes_at" => $this->dates->end->format("Y-m-d\\TH:i:sP"),
            "course_limit" => $this->courseLimit,
        ] + [
            "published" => $this->reference !== null,
            "revision" => $this->fingerprint(),
        ];
    }
    public function isOpen(DateTimeImmutable $on): bool
    {
        return $this->dates->contains($on);
    }
}
