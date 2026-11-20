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
        public ?ClassificationSnapshot $school = null,
        public ?ClassificationSnapshot $fieldOfStudy = null,
        public bool $schoolAndFieldRecorded = true,
    ) {
        if (!$schoolAndFieldRecorded && ($school !== null || $fieldOfStudy !== null)) {
            throw new InvalidArgumentException('Unrecorded school and field classifications cannot contain values.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $extended = array_key_exists('school', $data) || array_key_exists('field_of_study', $data);
        if (count($data) !== ($extended ? 5 : 3)) {
            throw new InvalidArgumentException('Provide complete program classification fields.');
        }
        $values = [];
        $kinds = $extended ? ClassificationKind::programKinds() :
        [ClassificationKind::Department, ClassificationKind::AcademicLevel, ClassificationKind::Award];
        foreach ($kinds as $kind) {
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
        if (!$extended) {
            return new self(...$values, school: null, fieldOfStudy: null, schoolAndFieldRecorded: false);
        }
        return new self(...$values);
    }

    /** @return array<string, array{id:string, code:string, title:string}|null> */
    public function describe(): array
    {
        $values = [
            "department" => $this->department?->describe(),
            "academic_level" => $this->academicLevel?->describe(),
            "award" => $this->award?->describe(),
        ];
        return $this->schoolAndFieldRecorded ? $values + ['school' => $this->school?->describe(),
            'field_of_study' => $this->fieldOfStudy?->describe()] : $values;
    }
}
