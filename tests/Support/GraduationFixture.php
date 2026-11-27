<?php

declare(strict_types=1);

namespace Tests\Support;

use StudentSpecializationRequirementsFixture;
use SisFixture;
use ReusableGroupFixture;
use DegreeProgressFixture;
use Domain\Graduation\Service\GraduationService;
use Domain\Shared\ValueObject\EntityId;

final class GraduationFixture
{
    public StudentSpecializationRequirementsFixture $curriculum;
    public SisFixture $sis;
    public GraduationService $service;
    public array $proposal;

    public function __construct(bool $complete = true, ?SisFixture $sis = null)
    {
        require_once __DIR__ . "/StudentSpecializationRequirementsFixture.php";
        require_once __DIR__ . "/DegreeProgressFixture.php";
        $r = new ReusableGroupFixture($sis);
        $r->sis->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 0,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ]);
        $row = $r->groups->view($r->group);
        $d = \Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2::blank(
            "Graduation capstone",
        )->definition;
        $d["courses"] = array_slice($row["definition"]["courses"], 0, 1);
        $d["min_credits_milli"] = 3000;
        $d["rules"] = [
            [
                "id" => EntityId::generate()->value,
                "mode" => "all",
                "minimum_courses" => 1,
                "course_ids" => array_column($d["courses"], "course_id"),
            ],
        ];
        $r->groups->save(
            $r->group,
            $row["revision"],
            new \Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2($d),
        );
        $r->groupVersion = $r->publishGroup();
        $r->groupDefinition = $d;
        $r->definition["groups"] = [
            [
                "group_id" => $r->group->value,
                "version_id" => $r->groupVersion->value,
            ],
        ];
        $r->definition["min_credits_milli"] = 3000;
        $r->definition["min_resident_milli"] = 3000;
        $this->curriculum = $f = new StudentSpecializationRequirementsFixture(
            $r,
            "multiple_components",
        );
        $this->sis = $s = $r->sis;
        $r->saveDegree();
        $version = new EntityId($r->publishDegree()["operation_id"]);
        $requirements = $f->requirements();
        $review = $requirements->review(
            $f->student,
            $f->assignment,
            $r->degree,
            $version,
            new \Domain\DegreeAudit\ValueObject\PursuitStart("2026-01-31"),
            "Fictional retained curriculum.",
        );
        $requirements->assign(
            $f->student,
            $f->assignment,
            $review["review_token"],
        );
        $f->assign();
        if ($complete) {
            $course = $d["courses"][0];
            $section = new \Domain\Registration\Section(
                EntityId::generate(),
                new EntityId($course["course_id"]),
                $s->term,
                $s->access->actor,
                "GRAD.CAPSTONE",
                20,
                true,
                true,
                new \Domain\Finance\ValueObject\Money(0, "USD"),
            );
            $s->registration->addSection($section);
            $bindings = $r->catalog->bindings();
            $review = $bindings->review(
                $section->id,
                new EntityId($course["version_id"]),
                "Fictional offering.",
            );
            $bindings->commit($section->id, $review["review_token"]);
            $enrollment = $s->service->register($f->student, $section->id)->id;
            $grades = array_column(
                $s
                    ->academicCredits()
                    ->choices($f->student, $section->id, $enrollment)["items"],
                "id",
                "code",
            );
            $review = $s
                ->academicCredits()
                ->review(
                    $f->student,
                    $section->id,
                    $enrollment,
                    new EntityId($grades["A"]),
                    0,
                );
            $s->academicCredits()->commit(
                $f->student,
                $section->id,
                $review["review_token"],
            );
            $review = $s
                ->academicDates()
                ->review(
                    $f->student,
                    $section->id,
                    $enrollment,
                    "2026-08-01",
                    1,
                    "Fictional verified capstone completion.",
                );
            $s->academicDates()->commit(
                $f->student,
                $section->id,
                $review["review_token"],
            );
        }
        $this->service = $this->service();
        $this->proposal = [
            "title" => "Bachelor of Science",
            "awarded_on" => $s->clock->now()->format("Y-m-d"),
            "term_id" => $s->term->value,
            "honors" => "",
        ];
    }

    public function anotherCandidate(): self
    {
        $other = clone $this;
        $other->curriculum = $f = clone $this->curriculum;
        $s = $this->sis;
        $person = $s->legacyPerson($s->user(), "Fictional", "Graduate Two");
        $entryTerm = $s->admissions->student($this->curriculum->student)
            ->entryTermId;
        $application = $s->legacyApplication(
            $person->id,
            $s->program,
            $entryTerm,
        );
        $s->legacyAdmissionDecision($application->id, true);
        $f->student = $s->matriculate($application->id)->id;
        $f->assignment = new EntityId(
            $s->sql->rows(
                "sis_student_programs",
                ["student_id" => $f->student->value],
                1,
            )[0]["id"],
        );
        $f->select(["DATA"]);
        $requirements = $f->requirements();
        $version = $s->sql->rows(
            "sis_student_requirements",
            ["id" => $this->curriculum->assignment->value],
            1,
        )[0]["requirements_version_id"];
        $review = $requirements->review(
            $f->student,
            $f->assignment,
            $f->r->degree,
            new EntityId($version),
            new \Domain\DegreeAudit\ValueObject\PursuitStart("2026-01-31"),
            "Fictional second curriculum.",
        );
        $requirements->assign(
            $f->student,
            $f->assignment,
            $review["review_token"],
        );
        $f->assign();
        $first = $s->sql->rows(
            "sis_enrollments",
            ["student_id" => $this->curriculum->student->value],
            1,
        )[0];
        $section = new EntityId($first["section_id"]);
        $enrollment = $s->service->register($f->student, $section)->id;
        $grades = array_column(
            $s->academicCredits()->choices($f->student, $section, $enrollment)[
                "items"
            ],
            "id",
            "code",
        );
        $review = $s
            ->academicCredits()
            ->review(
                $f->student,
                $section,
                $enrollment,
                new EntityId($grades["A"]),
                0,
            );
        $s->academicCredits()->commit(
            $f->student,
            $section,
            $review["review_token"],
        );
        $review = $s
            ->academicDates()
            ->review(
                $f->student,
                $section,
                $enrollment,
                "2026-08-01",
                1,
                "Fictional completion.",
            );
        $s->academicDates()->commit(
            $f->student,
            $section,
            $review["review_token"],
        );
        $other->service = $other->service();
        return $other;
    }

    public function facts(): array
    {
        $result = [];
        foreach (
            [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_graduation_cases",
                "sis_student_programs",
                "sis_students",
                "sis_academic_credits",
                "sis_audit",
            ] as $table
        ) {
            $result[$table] = $this->sis->sql->rows($table);
        }
        return $result;
    }

    public function service(
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): GraduationService {
        $s = $this->sis;
        $f = $this->curriculum;
        return new GraduationService(
            $s->graduationReader(),
            new \Infrastructure\Persistence\Repository\SqlGraduationRepository(
                $s->sql,
            ),
            $f->r->history,
            DegreeProgressFixture::service(
                $s,
                $f->r->history,
                $f->requirements(),
                $f->r->catalog->fixture->cipher,
                specializations: $f->service,
            ),
            new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository(
                $s->sql,
            ),
            $s->programStatusLedger(),
            $f->service,
            $s->admissions,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
            $s->clock,
            $s->profileLedger(),
            new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository(
                $s->sql,
            ),
        );
    }

    public function review(
        string $action = "eligibility",
        ?array $request = null,
    ): array {
        return $this->service->review(
            $this->curriculum->student,
            $this->curriculum->assignment,
            $action,
            $request ??
                ($action === "eligibility"
                    ? [
                        "proposal" => $this->proposal,
                        "attested" => true,
                        "history_acknowledged" => true,
                    ]
                    : []),
            "Fictional reviewed graduation evidence.",
        );
    }

    public function confirm(
        array $review,
        ?GraduationService $service = null,
    ): array {
        return ($service ?? $this->service)->confirm(
            $this->curriculum->student,
            $this->curriculum->assignment,
            new EntityId($review["operation_id"]),
            $review["action"],
            $review["request"],
            $review["revision"],
            $review["reason"],
            $review["review_token"],
        );
    }

    public function current(): array
    {
        return $this->service->current(
            $this->curriculum->student,
            $this->curriculum->assignment,
            "staff",
        );
    }
}
