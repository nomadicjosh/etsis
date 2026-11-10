<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Catalog\Enum\ClassificationKind;
use InvalidArgumentException;

final readonly class ProgramClassifications
{
    public function __construct(
        public ?ClassificationSnapshot $department = null,
        public ?ClassificationSnapshot $academicLevel = null,
        public ?ClassificationSnapshot $award = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 3) {
            throw new InvalidArgumentException("Provide all three program classification fields.");
        }
        $values = [];
        foreach (ClassificationKind::programKinds() as $kind) {
            if (
                !array_key_exists($kind->value, $data) ||
                ($data[$kind->value] !== null && !is_array($data[$kind->value]))
            ) {
                throw new InvalidArgumentException(
                    "Use complete program classifications or explicit blanks.",
                );
            }
            $values[] =
            $data[$kind->value] === null ? null : ClassificationSnapshot::fromArray($data[$kind->value]);
        }
        return new self(...$values);
    }

    /** @return array<string, array{id:string, code:string, title:string}|null> */
    public function describe(): array
    {
        return [
            "department" => $this->department?->describe(),
            "academic_level" => $this->academicLevel?->describe(),
            "award" => $this->award?->describe(),
        ];
    }
}
