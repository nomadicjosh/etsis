<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use InvalidArgumentException;

final readonly class ProgramClassifications
{
    /** @var list<ClassificationSnapshot> */
    public array $additionalCampuses;

    /** @param list<ClassificationSnapshot> $additionalCampuses */
    public function __construct(
        public ?ClassificationSnapshot $department = null,
        public ?ClassificationSnapshot $academicLevel = null,
        public ?ClassificationSnapshot $award = null,
        public ?ClassificationSnapshot $school = null,
        public ?ClassificationSnapshot $fieldOfStudy = null,
        public bool $schoolAndFieldRecorded = true,
        public ?ClassificationSnapshot $campus = null,
        public bool $campusRecorded = true,
        array $additionalCampuses = [],
        public bool $additionalCampusesRecorded = true,
    ) {
        if (
            count($additionalCampuses) > 20 ||
            !array_is_list($additionalCampuses) ||
            (!$additionalCampusesRecorded && $additionalCampuses !== []) ||
            ($additionalCampusesRecorded && !$campusRecorded)
        ) {
            throw new InvalidArgumentException(
                "Use up to twenty additional program campuses and preserve unknown earlier locations.",
            );
        }
        $sites = [];
        foreach ($additionalCampuses as $site) {
            if (
                !($site instanceof ClassificationSnapshot) ||
                isset($sites[$site->id->value]) ||
                $site->id->value === $campus?->id->value
            ) {
                throw new InvalidArgumentException(
                    "Choose distinct additional campuses, separate from the primary campus.",
                );
            }
            $sites[$site->id->value] = $site;
        }
        ksort($sites, SORT_STRING);
        $this->additionalCampuses = array_values($sites);
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
        $sitesRecorded = array_key_exists("additional_campuses", $data);
        $sites = $sitesRecorded ? $data["additional_campuses"] : [];
        if ($sitesRecorded && (!is_array($sites) || !array_is_list($sites) || count($sites) > 20)) {
            throw new InvalidArgumentException("Use a bounded list of complete program campuses.");
        }
        unset($data["additional_campuses"]);
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
            additionalCampuses: array_map(static function (mixed $site): ClassificationSnapshot {
                if (!is_array($site)) {
                    throw new InvalidArgumentException("Provide complete campus snapshots.");
                }
                return ClassificationSnapshot::fromArray($site);
            }, $sites),
            additionalCampusesRecorded: $sitesRecorded,
        );
    }

    /** @return array<string, mixed> */
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
        if ($this->campusRecorded) {
            $values["campus"] = $this->campus?->describe();
        }
        if ($this->additionalCampusesRecorded) {
            $values["additional_campuses"] = array_map(
                static fn(ClassificationSnapshot $site): array => $site->describe(),
                $this->additionalCampuses,
            );
        }
        return $values;
    }
}
