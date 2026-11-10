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
    ) {
        if ($position < 1 || $position > 2147483646) {
            throw new InvalidArgumentException('A valid publication position is required.');
        }
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['section_id' => $this->section->value, 'version_id' => $this->publication->value,
            'version' => $this->position, 'definition' => $this->definition->data];
    }
    public function fingerprint(): string
    {
        $data = $this->describe();
        ksort($data['definition']);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function matchesOffering(\Domain\Registration\Section $section, \Domain\Catalog\Course $course): bool
    {
        $d = $this->definition->data;
        return $this->section->value === $section->id->value
        && $d['course_id'] === $section->courseId->value && $d['term_id'] === $section->termId->value
        && $d['course_version_id'] === $course->publicationVersionId?->value
        && $d['code'] === $section->code && $d['capacity'] === $section->capacity
        && $d['faculty_user_id'] === $section->facultyUserId?->value
        && $d['delivery_mode'] === $section->deliveryMode->value
        && $d['fee_minor'] === $section->fee->minor && $d['currency'] === $section->fee->currency;
    }
}
