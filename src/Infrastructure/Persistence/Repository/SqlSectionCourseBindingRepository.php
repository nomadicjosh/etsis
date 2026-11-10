<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Registration\Repository\SectionCourseBindingRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
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
            $r = $this->sql->one("sis_course_versions", ["id" => $binding["course_version_id"]]);
            $definition = [
                "code" => $r["code"],
                "title" => $r["title"],
                "credits_milli" => (int) $r["credits_milli"],
                "prerequisites" => json_decode($r["prerequisites"], true, 8, JSON_THROW_ON_ERROR),
            ];
            if ((int) $r["schema_version"] !== 1) {
                throw new CorruptHistoryException();
            }
            $definition += ["transcript_title" => $r["transcript_title"], "description" => $r["description"]];
            $subject = $this->sql->rows("sis_course_version_subjects", ["id" => $r["id"]], 1)[0] ?? null;
            $definition["subject"] =
            $subject === null
            ? null
            : [
                        "id" => $subject["subject_id"],
                        "code" => $subject["code"],
                        "title" => $subject["title"],
                    ];
            $published = PublishedCourseDefinition::fromPublication(new EntityId($r["course_id"]), [
                "version_id" => $r["id"],
                "definition" => $definition,
            ]);
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

    private function retainSubject(PublishedCourseDefinition $definition): void
    {
        $old =
        $this->sql->rows("sis_course_version_subjects", ["id" => $definition->versionId->value], 1)[0] ??
        null;
        $s = $definition->definition->subject;
        if ($s === null) {
            if ($old !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        $row = [
            "id" => $definition->versionId->value,
            "subject_id" => $s->id->value,
            "code" => $s->code,
            "title" => $s->title,
        ];
        if ($old === null) {
            $this->sql->insert("sis_course_version_subjects", $row);
        } elseif (ProtectedEventCodec::canonical($old) !== ProtectedEventCodec::canonical($row)) {
            throw new CorruptHistoryException();
        }
    }

    public function retain(EntityId $section, PublishedCourseDefinition $definition): void
    {
        $owner = $this->sql->one("sis_sections", ["id" => $section->value]);
        if ($owner["course_id"] !== $definition->courseId->value) {
            throw new CorruptHistoryException();
        }
        $r = [
            "id" => $definition->versionId->value,
            "course_id" => $definition->courseId->value,
            "schema_version" => 1,
            "code" => $definition->definition->code,
            "title" => $definition->definition->title,
            "credits_milli" => $definition->definition->creditsMilli,
            "transcript_title" => $definition->definition->text->transcriptTitle,
            "description" => $definition->definition->text->description,
            "prerequisites" => json_encode($definition->definition->prerequisites, JSON_THROW_ON_ERROR),
        ];
        $old = $this->sql->rows("sis_course_versions", ["id" => $definition->versionId->value], 1)[0] ?? null;
        if ($old === null) {
            $this->sql->insert("sis_course_versions", $r);
        } else {
            $old["schema_version"] = (int) $old["schema_version"];
            $old["credits_milli"] = (int) $old["credits_milli"];
            if (ProtectedEventCodec::canonical($old) !== ProtectedEventCodec::canonical($r)) {
                throw new CorruptHistoryException();
            }
        }
        $this->retainSubject($definition);
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
