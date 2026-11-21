<?php

declare(strict_types=1);

namespace Tests\Support;

use SisFixture;
use EventStoreFixture;
use DateTimeImmutable;
use Domain\Shared\ValueObject\EntityId;

final class EnrollmentReinstatementFixture
{
    public SisFixture $sis;
    public \Domain\Registration\Section $section;
    public \Domain\Student\Student $student;
    public \Domain\Registration\Enrollment $enrollment;
    public array $drop;
    public function __construct(?SisFixture $sis = null, bool $selfRegistered = false)
    {
        $this->sis = $s = $sis ?? new SisFixture();
        $s->gradeScale([
            [
                "code" => "W",
                "min_score_milli" => 0,
                "max_score_milli" => 49999,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => false,
            ],
            [
                "code" => "F",
                "min_score_milli" => 50000,
                "max_score_milli" => 100000,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => true,
            ],
        ]);
        $this->student = $s->student($s->user());
        $this->section = $s->section(capacity: 1);
        $version = $s->publishCourse($this->section->courseId);
        $bindings = new \Domain\Registration\Service\SectionCourseBindingService(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->coursePublications(),
            new \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository($s->sql),
            EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
        $binding = $bindings->review(
            $this->section->id,
            $version,
            "Fictional original course definition.",
        );
        $bindings->commit($this->section->id, $binding["review_token"]);
        $registrar = $s->access->actor;
        if ($selfRegistered) {
            $s->access->actor = $s->admissions->person($this->student->personId)->ownerUserId;
        }
        $this->enrollment = $s->service->register(
            $this->student->id,
            $this->section->id,
            !$selfRegistered,
        );
        $s->access->actor = $registrar;
        $s->publishDropPolicy(
            $s->term,
            -1,
            new \Domain\Registration\ValueObject\DropWindows([
                ["through" => null, "grade_code" => "W"],
            ]),
        );
        $s->clock->time = new DateTimeImmutable("2026-08-20T10:00:00-07:00");
        $this->drop = $r = $s->drops->review($this->student->id, $this->section->id);
        $s->drops->commit(
            $this->student->id,
            $this->section->id,
            $this->enrollment->id,
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["effective_on"],
        );
    }
    public function review(): array
    {
        return $this->sis
            ->reinstatements()
            ->review(
                $this->student->id,
                $this->section->id,
                $this->enrollment->id,
                "Fictional reviewed correction of a withdrawal.",
            );
    }
    public function confirm(array $r, ?\Domain\Audit\Repository\AuditTrail $audit = null): array
    {
        return $this->sis
            ->reinstatements($audit)
            ->confirm(
                $this->student->id,
                $this->section->id,
                $this->enrollment->id,
                new EntityId($r["operation_id"]),
                $r["revision"],
                $r["reason"],
                $r["review_token"],
            );
    }
}
