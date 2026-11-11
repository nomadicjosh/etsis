<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

/** Shared reconstructible course-version projection for verified section and transfer references. */
final readonly class SqlCourseVersionProjection
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $version): PublishedCourseDefinition
    {
        try {
            $r = $this->sql->one("sis_course_versions", ["id" => $version->value]);
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
            $definition["classifications"] = $this->classifications(new EntityId($r["id"]));
            $published = PublishedCourseDefinition::fromPublication(new EntityId($r["course_id"]), [
                "version_id" => $r["id"],
                "definition" => $definition,
            ]);
            return $published;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
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

    /** @return array<string, mixed> */
    private function classifications(EntityId $version): array
    {
        $row = $this->sql->one("sis_course_version_classes", ["id" => $version->value]);
        $values = [];
        foreach (\Domain\Catalog\Enum\ClassificationKind::courseKinds() as $kind) {
            $id = $row[$kind->value . "_id"];
            $code = $row[$kind->value . "_code"];
            $title = $row[$kind->value . "_title"];
            if ($id === null && ($code !== null || $title !== null)) {
                throw new CorruptHistoryException();
            }
            $values[$kind->value] = $id === null ? null : ["id" => $id, "code" => $code, "title" => $title];
        }
        return \Domain\Catalog\ValueObject\CourseClassifications::fromArray($values)->describe();
    }

    private function retainClassifications(PublishedCourseDefinition $definition): void
    {
        $row = ["id" => $definition->versionId->value];
        foreach ($definition->definition->classifications->describe() as $kind => $value) {
            $row[$kind . "_id"] = $value["id"] ?? null;
            $row[$kind . "_code"] = $value["code"] ?? null;
            $row[$kind . "_title"] = $value["title"] ?? null;
        }
        $old =
        $this->sql->rows("sis_course_version_classes", ["id" => $definition->versionId->value], 1)[0] ??
        null;
        if ($old === null) {
            $this->sql->insert("sis_course_version_classes", $row);
        } elseif (ProtectedEventCodec::canonical($old) !== ProtectedEventCodec::canonical($row)) {
            throw new CorruptHistoryException();
        }
    }

    public function retain(PublishedCourseDefinition $definition): void
    {
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
        $this->retainClassifications($definition);
    }
}
