<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable PSR1.Files.SideEffects -- Explicitly loads isolated fictional fixture dependencies.

require_once __DIR__ . "/StudentRequirementsFixture.php";
require_once __DIR__ . "/ProgramPublicationFixture.php";
require_once __DIR__ . "/OfferingCreditFixture.php";

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Registration\Service\GraduateCourseApprovalService;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlCatalogYearRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;

final class GraduateRegistrationFixture
{
    public \StudentRequirementsFixture $requirements;
    public \SisFixture $sis;
    public EntityId $student;
    public EntityId $pursuit;
    public EntityId $undergraduate;
    public EntityId $graduate;
    public EntityId $advisorUser;
    public EntityId $registrar;
    public OfferingCreditFixture $target;
    public array $grades;

    public function __construct()
    {
        $this->requirements = $r = new \StudentRequirementsFixture();
        $this->sis = $s = $r->f->sis;
        $this->student = $r->student;
        $this->pursuit = $r->assignment;
        $this->registrar = $s->access->actor;
        $program = new \ProgramPublicationFixture($s);
        $ug = $program->classifications->save(
            ClassificationKind::AcademicLevel,
            null,
            "UG",
            "Undergraduate",
            true,
            null,
        );
        $gr = $program->classifications->save(
            ClassificationKind::AcademicLevel,
            null,
            "GR",
            "Graduate",
            true,
            null,
        );
        $this->undergraduate = new EntityId($ug["id"]);
        $this->graduate = new EntityId($gr["id"]);
        $draft = $program->service->draft($s->program);
        $program->service->save(
            $s->program,
            "ARTS.UG",
            "Fictional undergraduate program",
            null,
            null,
            $this->undergraduate,
            null,
            $draft["revision"],
        );
        $review = $program->service->review($s->program, $r->f->year, "Fictional program publication.");
        $catalog = new EntityId(
            $program->service->publish($s->program, $review["review_token"])["operation_id"],
        );
        $assignment = new SqlStudentRecordRepository($s->sql)->assignment($r->assignment);
        $context = $s->programStatusLedger()->cohortContext($assignment);
        $release = new SqlCatalogYearRepository($s->sql)->release($catalog);
        $s->transactions->run(
            fn() => $s
                ->programStatusLedger()
                ->changeCohort(
                    $assignment,
                    \Domain\Student\Service\StudentProgramStatusLedger::catalogSnapshot($release),
                    EntityId::generate(),
                    $s->access->actor,
                    "Fictional cohort selection.",
                    $context["stream_version"],
                    $context["binding"]["version"] ?? -1,
                ),
        );
        $s->gradeScale([
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "C",
                "min_score_milli" => 70000,
                "max_score_milli" => 89999,
                "points_milli" => 2000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "F",
                "min_score_milli" => 0,
                "max_score_milli" => 69999,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => true,
            ],
            [
                "code" => "W",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => false,
            ],
        ]);
        $r->f->saveDegree();
        $r->version = new EntityId($r->f->publishDegree()["operation_id"]);
        $r->assign();
        $source = $this->offering($this->undergraduate);
        $enrolled = $s->service->register($this->student, $source->section->id, true);
        $this->grade($source, $enrolled->id, "C");
        $advisor = $s->legacyPerson($this->advisorUser = $s->user(), "Fictional", "Advisor");
        $s->sql->insert("sis_employees", [
            "id" => EntityId::generate()->value,
            "person_id" => $advisor->id->value,
            "job_title" => "Fictional advisor",
            "active" => 1,
        ]);
        $service = $s->advisors();
        $review = $service->review(
            $this->student,
            $this->pursuit,
            [
                [
                    "id" => EntityId::generate()->value,
                    "advisor_person_id" => $advisor->id->value,
                    "role" => "primary",
                    "starts_on" => "2026-01-01",
                    "ends_on" => null,
                ],
            ],
            $service->view($this->student, $this->pursuit)["revision"],
            "Fictional advisor assignment.",
        );
        $service->commit(
            $this->student,
            $this->pursuit,
            new EntityId($review["operation_id"]),
            $review["rows"],
            $review["revision"],
            $review["reason"],
            $review["review_token"],
        );
        $this->target = $this->offering($this->graduate);
        $this->policy();
    }

    public function policy(?int $gradeMinimum = null, int $gpaMinimum = 2000, bool $enabled = true): array
    {
        $service = $this->sis->graduatePolicies();
        $rules = GraduateRegistrationRules::DEFAULT;
        $rules["enabled"] = $enabled;
        $rules["undergraduate_level_id"] = $this->undergraduate->value;
        $rules["graduate_level_id"] = $this->graduate->value;
        $rules["minimum_gpa_milli"] = $gpaMinimum;
        $rules["minimum_graduate_grade_milli"] = $gradeMinimum;
        $review = $service->review($rules, $service->view()["version"], "Fictional graduate policy.");
        return $service->publish(
            new EntityId($review["publication_id"]),
            $review["rules"],
            $review["version"],
            $review["reason"],
            $review["review_token"],
        );
    }

    public function offering(EntityId $level): OfferingCreditFixture
    {
        $f = new OfferingCreditFixture($this->sis);
        $row = new SqlClassificationRepository($this->sis->sql)->get($level);
        new SqlClassificationRepository($this->sis->sql)->assign(
            $f->section->courseId,
            new CourseClassifications(
                academicLevel: new ClassificationSnapshot($level, $row["code"], $row["title"]),
            ),
        );
        $f->bind();
        $f->select($f->publish());
        return $f;
    }

    public function grade(OfferingCreditFixture $offering, EntityId $enrollment, string $code): EntityId
    {
        $s = $this->sis;
        $ledger = $s->academicCredits();
        $this->grades = array_column(
            $ledger->choices($this->student, $offering->section->id, $enrollment)["items"],
            "id",
            "code",
        );
        $review = $ledger->review(
            $this->student,
            $offering->section->id,
            $enrollment,
            new EntityId($this->grades[$code]),
            0,
        );
        $ledger->commit($this->student, $offering->section->id, $review["review_token"]);
        return new EntityId($s->sql->one("sis_academic_credits", ["source_id" => $enrollment->value])["id"]);
    }

    public function decide(string $action, ?OfferingCreditFixture $offering = null): array
    {
        $s = $this->sis;
        $s->access->actor = str_starts_with($action, "advisor_") ? $this->advisorUser : $this->registrar;
        $service = $s->graduateApprovals();
        $section = ($offering ?? $this->target)->section->id;
        $review = $service->review(
            $this->student,
            $section,
            $this->pursuit,
            $action,
            $service->view($this->student, $section, $this->pursuit)["version"],
            "Fictional graduate decision.",
        );
        $s->clock->time = $s->clock->time->modify("+1 minute");
        $service->confirm(
            $this->student,
            $section,
            $this->pursuit,
            new EntityId($review["operation_id"]),
            $review["action"],
            $review["version"],
            $review["reason"],
            $review["revision"],
            $review["effective_at"],
            $review["review_token"],
        );
        $s->access->actor = $this->registrar;
        return $review;
    }
}
