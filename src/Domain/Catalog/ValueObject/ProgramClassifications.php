<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

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
        public ?ClassificationSnapshot $campus = null,
        public bool $campusRecorded = true,
    ) {
        if (!$schoolAndFieldRecorded && ($school !== null || $fieldOfStudy !== null)) {
            throw new InvalidArgumentException(
                "Unrecorded school and field classifications cannot contain values.",
            );
        }
        if (!$campusRecorded && $campus !== null) {
            throw new InvalidArgumentException("An unrecorded program campus cannot contain a value.");
        }
        if ($campusRecorded && !$schoolAndFieldRecorded) {
            throw new InvalidArgumentException(
                "Provide the complete current program classification definition.",
            );
        }
    }

    /** Missing historical keys remain absent; an explicit blank is a different recorded fact. */
    public static function fromArray(array $data): self
    {
        $extended = array_key_exists("school", $data) || array_key_exists("field_of_study", $data);
        $campusRecorded = array_key_exists("campus", $data);
        $keys = ["department", "academic_level", "award"];
        if ($extended) {
            array_push($keys, "school", "field_of_study");
        }
        if ($campusRecorded) {
            $keys[] = "campus";
        }
        if (count($data) !== count($keys) || array_diff(array_keys($data), $keys) !== []) {
            throw new InvalidArgumentException("Provide complete program classification fields.");
        }
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || ($data[$key] !== null && !is_array($data[$key]))) {
                throw new InvalidArgumentException(
                    "Use complete program classifications or explicit blanks.",
                );
            }
            $values[$key] = $data[$key] === null ? null : ClassificationSnapshot::fromArray($data[$key]);
        }
        return new self(
            department: $values["department"],
            academicLevel: $values["academic_level"],
            award: $values["award"],
            school: $values["school"] ?? null,
            fieldOfStudy: $values["field_of_study"] ?? null,
            schoolAndFieldRecorded: $extended,
            campus: $values["campus"] ?? null,
            campusRecorded: $campusRecorded,
        );
    }

    /** @return array<string, array{id:string, code:string, title:string}|null> */
    public function describe(): array
    {
        $values = [
            "department" => $this->department?->describe(),
            "academic_level" => $this->academicLevel?->describe(),
            "award" => $this->award?->describe(),
        ];
        if ($this->schoolAndFieldRecorded) {
            $values += [
                "school" => $this->school?->describe(),
                "field_of_study" => $this->fieldOfStudy?->describe(),
            ];
        }
        return $this->campusRecorded ? $values + ["campus" => $this->campus?->describe()] : $values;
    }
}
