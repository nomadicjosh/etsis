<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** A retained publication identity and its complete course definition. */
final readonly class PublishedCourseDefinition
{
    public function __construct(
        public EntityId $courseId,
        public EntityId $versionId,
        public CourseDefinition $definition,
    ) {
    }

    /** @param array<string, mixed> $item */
    public static function fromPublication(EntityId $course, array $item): self
    {
        return new self(
            $course,
            new EntityId($item["version_id"]),
            CourseDefinition::fromArray($item["definition"]),
        );
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            "course_id" => $this->courseId->value,
            "version_id" => $this->versionId->value,
            "definition" => $this->definition->describe(),
        ];
    }
}
