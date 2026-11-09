<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Catalog\ValueObject\CourseText;
use Domain\Shared\ValueObject\EntityId;

/** Owned by the course; writes participate in its enclosing course lock and transaction. */
final readonly class SqlCourseText
{
    public function __construct(private SqlRecords $sql)
    {
    }

    /** @return array{transcript_title: ?string, description: ?string} */
    public function get(EntityId $id): array
    {
        $row = $this->sql->rows("sis_course_descriptions", ["course_id" => $id->value], 1)[0] ?? null;
        return ["transcript_title" => $row["transcript_title"] ?? null, "description" => $row["description"] ?? null];
    }

    public function save(EntityId $id, CourseText $text): void
    {
        $existing = $this->sql->rows("sis_course_descriptions", ["course_id" => $id->value], 1);
        if ($existing === []) {
            if ($text->transcriptTitle !== null || $text->description !== null) {
                $this->sql->insert(
                    "sis_course_descriptions",
                    ["id" => EntityId::generate()->value, "course_id" => $id->value] + $text->describe(),
                );
            }
        } else {
            $this->sql->update("sis_course_descriptions", $text->describe(), ["course_id" => $id->value]);
        }
    }
}
