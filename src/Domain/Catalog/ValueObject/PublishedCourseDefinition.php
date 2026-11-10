<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Curated, versioned catalog data; never an event envelope or student record. */
final readonly class PublishedCourseDefinition
{
    public function __construct(
        public EntityId $courseId,
        public EntityId $versionId,
        public int $schemaVersion,
        public CourseDefinition $core,
        public ?CourseText $text,
        public ?SubjectSnapshot $subject = null,
    ) {
        if (
            !in_array($schemaVersion, [1, 2, 3], true) ||
            ($schemaVersion === 1) !== ($text === null) ||
            ($schemaVersion < 3 && $subject !== null)
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<string, mixed> $item */
    public static function fromPublication(EntityId $course, array $item): self
    {
        $d = $item["definition"];
        return new self(
            $course,
            new EntityId($item["version_id"]),
            $item["schema_version"],
            new CourseDefinition($d["code"], $d["title"], $d["credits_milli"], $d["prerequisites"]),
            $item["schema_version"] === 1 ? null : CourseText::fromArray($d),
            $item["schema_version"] === 3 && $d["subject"] !== null
                ? SubjectSnapshot::fromArray($d["subject"])
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            "course_id" => $this->courseId->value,
            "version_id" => $this->versionId->value,
            "schema_version" => $this->schemaVersion,
            "definition" =>
                $this->core->describe() +
                ($this->text?->describe() ?? []) +
                ($this->schemaVersion === 3 ? ["subject" => $this->subject?->describe()] : []),
        ];
    }
}
