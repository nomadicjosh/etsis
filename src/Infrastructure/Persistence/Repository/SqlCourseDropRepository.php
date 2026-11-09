<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeZone;
use Domain\Registration\Enrollment;
use Domain\Registration\Repository\CourseDropRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCourseDropRepository implements CourseDropRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function credit(Enrollment $enrollment): array
    {
        $r = $this->sql->one("sis_academic_credits", [
            "source_id" => $enrollment->id->value,
            "student_id" => $enrollment->studentId->value,
            "term_id" => $enrollment->termId->value,
            "credit_type" => "institutional",
        ]);
        foreach (["version", "attempted_milli", "completed_milli", "grade_points_milli"] as $key) {
            $r[$key] = (int) $r[$key];
        }
        $reference = $this->sql->rows("sis_credit_course_versions", ["id" => $r["id"]], 1)[0] ?? null;
        $r["course_version_id"] = $reference["course_version_id"] ?? null;
        return $r;
    }
    public function receipt(EntityId $operation): ?array
    {
        return $this->sql->rows("sis_course_drop_receipts", ["id" => $operation->value], 1)[0] ?? null;
    }
    public function record(
        EntityId $operation,
        EntityId $student,
        EntityId $section,
        EntityId $actor,
        EntityId $enrollment,
        int $version,
        string $hash,
        string $encrypted,
        string $createdAt,
    ): void {
        $this->sql->insert("sis_course_drop_receipts", [
            "id" => $operation->value,
            "student_id" => $student->value,
            "section_id" => $section->value,
            "actor_id" => $actor->value,
            "enrollment_id" => $enrollment->value,
            "version" => $version,
            "request_hash" => $hash,
            "encrypted_content" => $encrypted,
            "created_at" => $createdAt,
        ]);
    }
}
