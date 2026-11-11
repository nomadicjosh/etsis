<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Registration\Repository\SectionCourseBindingRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlSectionCourseBindingRepository implements SectionCourseBindingRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function bound(EntityId $section): ?PublishedCourseDefinition
    {
        $binding = $this->sql->rows("sis_section_course_versions", ["id" => $section->value], 1)[0] ?? null;
        if ($binding === null) {
            return null;
        }
        try {
            $published = new SqlCourseVersionProjection($this->sql)->get(new EntityId($binding["course_version_id"]));
            $owner = $this->sql->one("sis_sections", ["id" => $section->value]);
            if ($owner["course_id"] !== $published->courseId->value) {
                throw new CorruptHistoryException();
            }
            return $published;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function hasStudentRecords(EntityId $section): bool
    {
        return $this->sql->rows("sis_enrollments", ["section_id" => $section->value], 1) !== [];
    }

    public function retain(EntityId $section, PublishedCourseDefinition $definition): void
    {
        $owner = $this->sql->one("sis_sections", ["id" => $section->value]);
        if ($owner["course_id"] !== $definition->courseId->value) {
            throw new CorruptHistoryException();
        }
        new SqlCourseVersionProjection($this->sql)->retain($definition);
        $oldBinding =
        $this->sql->rows("sis_section_course_versions", ["id" => $section->value], 1)[0] ?? null;
        if ($oldBinding === null) {
            $this->sql->insert("sis_section_course_versions", [
                "id" => $section->value,
                "course_version_id" => $definition->versionId->value,
            ]);
        } elseif ($oldBinding["course_version_id"] !== $definition->versionId->value) {
            throw new CorruptHistoryException();
        }
    }
}
