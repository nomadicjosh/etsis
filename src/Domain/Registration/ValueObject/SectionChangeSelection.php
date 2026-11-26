<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class SectionChangeSelection
{
    public function __construct(
        public EntityId $student,
        public EntityId $from,
        public EntityId $to,
        public ?int $creditsMilli = null,
    ) {
        RequestedCreditChoices::validate($creditsMilli === null ? [] : [$to->value => $creditsMilli], [$to]);
        if ($from->value === $to->value) {
            throw new ValidationException([
                ["field" => "to_section_id", "message" => "Choose another section."],
            ]);
        }
    }

    public function requestHash(string $revision, bool $byStaff): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new ValidationException([
                ["field" => "revision", "message" => "Review the section change again."],
            ]);
        }
        return hash(
            "sha256",
            json_encode(
                [
                    "section-change",
                    $this->student->value,
                    $this->from->value,
                    $this->to->value,
                    $revision,
                    $byStaff,
                    ...($this->creditsMilli === null ? [] : [$this->creditsMilli]),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
