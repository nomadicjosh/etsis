<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class PublishedSectionDefinition
{
    public function __construct(
        public EntityId $section,
        public EntityId $publication,
        public int $position,
        public SectionDefinitionV1 $definition,
        public ?SectionClassifications $classifications = null,
    ) {
        if ($position < 1 || $position > 2147483646) {
            throw new InvalidArgumentException(
                "A valid publication position is required.",
            );
        }
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            "section_id" => $this->section->value,
            "version_id" => $this->publication->value,
            "version" => $this->position,
            "definition" => $this->definition->data,
        ] +
        ($this->classifications === null
        ? []
        : ["classifications" => $this->classifications->describe()]);
    }
    public function fingerprint(): string
    {
        $data = $this->describe();
        ksort($data["definition"]);
        ksort($data);
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function matchesClassifications(
        ?EntityId $method = null,
        ?EntityId $campus = null,
        ?EntityId $reportingGroup = null,
    ): bool {
        return $method?->value ===
        $this->classifications?->instructionalMethod?->id->value &&
        $campus?->value === $this->classifications?->campus?->id->value &&
        $reportingGroup?->value === $this->classifications?->reportingGroup?->id->value;
    }

    public function matchesOffering(
        \Domain\Registration\Section $section,
        \Domain\Catalog\Course $course,
    ): bool {
        $d = $this->definition->data;
        return ($d['eligible_program_ids'] ?? []) === $section->programPolicy->programIds &&
        ($d['require_program_campus'] ?? false) === $section->programPolicy->requireCampus &&
        $this->section->value === $section->id->value &&
        $d["course_id"] === $section->courseId->value &&
        $d["term_id"] === $section->termId->value &&
        $d["course_version_id"] === $course->publicationVersionId?->value &&
        (array_key_exists('credits_milli', $d) ? $d['credits_milli'] === $course->registrationCreditsMilli()
        : $section->offeringCreditsMilli === null) &&
        ($d['continuing_education'] ?? null) ===
        $section->continuingEducation->resolve($course->continuingEducation)?->describe() &&
        $d["code"] === $section->code &&
        $d["capacity"] === $section->capacity &&
        (!array_key_exists('minimum_enrollment', $d) || $d['minimum_enrollment'] === $section->minimumEnrollment) &&
        (!array_key_exists('web_registration', $d) || $d['web_registration'] === $section->webRegistration) &&
        $d["faculty_user_id"] === $section->facultyUserId?->value &&
        $d["delivery_mode"] === $section->deliveryMode->value &&
        $this->matchesClassifications(
            $section->instructionalMethodId,
            $section->campusId,
            $section->reportingGroupId,
        ) &&
        $d["fee_minor"] === $section->fee->minor &&
        $d["currency"] === $section->fee->currency;
    }
}
