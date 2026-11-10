<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Catalog\Enum\ClassificationKind;
use InvalidArgumentException;

final readonly class CourseClassifications
{
    public function __construct(
        public ?ClassificationSnapshot $department = null,
        public ?ClassificationSnapshot $academicLevel = null,
        public ?ClassificationSnapshot $courseLevel = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $values = [];
        if (count($data) !== 3) {
            throw new InvalidArgumentException("Provide all three course classification fields.");
        }
        foreach (ClassificationKind::cases() as $kind) {
            if (!array_key_exists($kind->value, $data)) {
                throw new InvalidArgumentException("Provide all three course classification fields.");
            }
            $value = $data[$kind->value];
            if ($value !== null && !is_array($value)) {
                throw new InvalidArgumentException("Use a classification definition or an explicit blank.");
            }
            $values[] = $value === null ? null : ClassificationSnapshot::fromArray($value);
        }
        return new self(...$values);
    }

    /** @return array<string, array{id: string, code: string, title: string}|null> */
    public function describe(): array
    {
        return [
            "department" => $this->department?->describe(),
            "academic_level" => $this->academicLevel?->describe(),
            "course_level" => $this->courseLevel?->describe(),
        ];
    }
}
