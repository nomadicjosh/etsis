<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\CourseTextProjection;
use Domain\Catalog\ValueObject\CourseText;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCourseTextProjection implements CourseTextProjection
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function apply(EntityId $course, CourseText $text, EntityId $version, int $position): void
    {
        if ($position < 1) {
            throw new CorruptHistoryException();
        }
        $existing = $this->sql->rows("sis_course_descriptions", ["course_id" => $course->value], 1)[0] ?? null;
        $values = [
            "published_transcript_title" => $text->transcriptTitle,
            "published_description" => $text->description,
            "publication_version_id" => $version->value,
            "published_position" => $position,
        ];
        if (
            $existing !== null &&
            $existing["published_position"] !== null &&
            (int) $existing["published_position"] >= $position
        ) {
            if (
                (int) $existing["published_position"] !== $position ||
                $existing["publication_version_id"] !== $version->value ||
                $existing["published_transcript_title"] !== $text->transcriptTitle ||
                $existing["published_description"] !== $text->description
            ) {
                throw new CorruptHistoryException();
            }
            return;
        }
        if ($existing === null) {
            $this->sql->insert(
                "sis_course_descriptions",
                [
                    "id" => EntityId::generate()->value,
                    "course_id" => $course->value,
                    "transcript_title" => null,
                    "description" => null,
                ] + $values,
            );
        } else {
            $this->sql->update("sis_course_descriptions", $values, ["course_id" => $course->value]);
        }
    }
}
