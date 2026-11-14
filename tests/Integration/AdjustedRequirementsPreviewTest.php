<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class AdjustedRequirementsPreviewTest extends TestCase
{
    private StudentRequirementsFixture $f;
    private RequirementAdjustmentService $service;

    protected function setUp(): void
    {
        $this->f = new StudentRequirementsFixture();
        $this->f->assign();
        $this->service = $this->service();
    }

    private function service(): RequirementAdjustmentService
    {
        $f = $this->f;
        $s = $f->f->sis;
        return new RequirementAdjustmentService(
            new SqlRequirementAdjustmentRepository($s->sql),
            $f->service,
            new SqlStudentRecordRepository($s->sql),
            $f->f->history,
            $s->access,
            $s->transactions,
            $s->audit,
            $f->f->catalog->fixture->cipher,
            $s->clock,
            new PublishedRequirementsReader($f->f->history),
            new SqlReplacementCourseRepository($s->sql),
            new \Domain\DegreeAudit\Service\StudentCurriculumContext($f->service, $s->specializations($f->f->history)),
        );
    }

    private function grant(array $definition): array
    {
        $f = $this->f;
        $review = $this->service->reviewGrant(
            $f->student,
            $f->assignment,
            $definition,
            "Fictional protected preview approval",
        );
        $this->service->commit($f->student, $f->assignment, $review["review_token"]);
        return $review["adjustment"];
    }

    private function preview(): array
    {
        return $this->service->preview($this->f->student, $this->f->assignment);
    }

    private function snapshot(): array
    {
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_requirement_adjustments",
            "sis_student_requirements",
            "sis_academic_credits",
            "sis_audit",
        ];
        return array_map($this->f->f->digest(...), $tables);
    }

    public function testPreviewRetainsTheBaselineAndCreatesNoDecisionsOrAcademicWork(): void
    {
        $f = $this->f;
        $baseline = $f->service->view($f->student, $f->assignment)["requirements"];
        $before = $this->snapshot();
        $preview = $this->preview();
        self::assertSame($baseline, $preview["requirements"]);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $preview["items"]);
        self::assertSame($f->version->value, $preview["selection"]["requirements_version_id"]);
    }

    public function testPursuitWideWaiverAndGroupReductionKeepBothPublishedGroupsIntact(): void
    {
        $f = $this->f;
        $r = $f->f;
        $second = EntityId::generate();
        $definition = $r->groupDefinition;
        $definition["name"] = "Fictional second reusable block";
        $r->groups->create($second, new CourseGroupDefinition($definition));
        $review = $r->groups->review($second, "Fictional second block publication");
        $published = $r->groups->publish($second, $review["review_token"]);
        $degree = $r->definition;
        $degree["groups"][] = ["group_id" => $second->value, "version_id" => $published["operation_id"]];
        $r->degrees->save($r->degree, 1, new AuditRequirementsV3($degree));
        $f->version = new EntityId($r->publishDegree()["operation_id"]);
        $f->assign();
        $course = $r->groupDefinition["courses"][0]["course_id"];
        $this->grant(["kind" => "waiver", "course_id" => $course]);
        $this->grant([
            "kind" => "minimum",
            "group_id" => $r->group->value,
            "minimum" => "min_credits_milli",
            "required_milli" => 9000,
        ]);
        $this->grant([
            "kind" => "minimum",
            "group_id" => null,
            "minimum" => "min_credits_milli",
            "required_milli" => 108000,
        ]);
        $before = $this->snapshot();
        $preview = $this->preview();
        self::assertCount(2, $preview["requirements"]["group_details"]);
        foreach ($preview["requirements"]["group_details"] as $group) {
            self::assertArrayHasKey($course, $group["course_details"]);
            self::assertSame(12000, $group["definition"]["min_credits_milli"]);
        }
        self::assertSame([$course], $preview["effective_minimums"]["waived_course_ids"]);
        self::assertSame(
            9000,
            $preview["effective_minimums"]["groups"][$r->group->value]["min_credits_milli"],
        );
        self::assertSame(
            12000,
            $preview["effective_minimums"]["groups"][$second->value]["min_credits_milli"],
        );
        self::assertSame(108000, $preview["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertSame(120000, $preview["requirements"]["definition"]["min_credits_milli"]);
        self::assertSame($before, $this->snapshot());
    }

    public function testApprovedAlternativeUsesItsRetainedPublicationAfterCatalogChanges(): void
    {
        $f = $this->f;
        $courses = $f->f->groupDefinition["courses"];
        $approval = $this->grant([
            "kind" => "substitution",
            "course_id" => $courses[0]["course_id"],
            "replacement_course_id" => $courses[1]["course_id"],
            "replacement_version_id" => $courses[1]["version_id"],
        ]);
        $f->f->sis->sql->update(
            "sis_courses",
            ["title" => "Fictional later English title"],
            ["id" => $courses[1]["course_id"]],
        );
        $course = new EntityId($courses[1]["course_id"]);
        $review = $f->f->catalog->publications()->review($course, "Fictional later course publication");
        $f->f->catalog->publications()->publish($course, $review["review_token"]);
        $preview = $this->preview();
        self::assertSame(
            "Fictional ENG.203",
            $preview["substitution_details"][$approval["adjustment_id"]]["title"],
        );
        self::assertSame(
            $courses[1]["version_id"],
            $preview["effective_minimums"]["substitutions"][$courses[0]["course_id"]]["version_id"],
        );
        self::assertSame(
            $courses[0],
            $preview["requirements"]["group_details"][$f->f->group->value]["definition"]["courses"][0],
        );
        self::assertSame([], $f->f->sis->sql->rows("sis_academic_credits"));
    }

    public function testLaterDegreeSelectionOmitsOldMinimumReductionAndKeepsPursuitWaiver(): void
    {
        $f = $this->f;
        $this->grant([
            "kind" => "minimum",
            "group_id" => null,
            "minimum" => "min_credits_milli",
            "required_milli" => 108000,
        ]);
        $course = $f->f->groupDefinition["courses"][0]["course_id"];
        $this->grant(["kind" => "waiver", "course_id" => $course]);
        $degree = $f->f->definition;
        $degree["name"] = "Fictional later selected degree";
        $f->f->degrees->save($f->f->degree, 1, new AuditRequirementsV3($degree));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $preview = $this->preview();
        self::assertSame($f->version->value, $preview["requirements"]["version_id"]);
        self::assertSame(120000, $preview["effective_minimums"]["degree"]["min_credits_milli"]);
        self::assertSame([$course], $preview["effective_minimums"]["waived_course_ids"]);
        self::assertCount(
            1,
            array_filter($preview["items"], static fn(array $item): bool => !$item["applicable"]),
        );
    }

    public function testOwnerNeedsNoApprovalAuthorityAndCannotPreviewAnotherStudent(): void
    {
        $f = $this->f;
        $this->grant(["kind" => "waiver", "course_id" => $f->f->groupDefinition["courses"][0]["course_id"]]);
        $f->f->sis->access->staff = false;
        self::assertCount(1, $this->preview()["items"]);
        $body = json_encode($this->preview(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional protected preview approval", $body);
        self::assertArrayNotHasKey("actor_id", $this->preview()["items"][0]);
        $f->f->sis->access->actor = $f->f->sis->user();
        $this->expectException(AccessDeniedException::class);
        $this->preview();
    }

    public function testMissingSelectionReturnsSetupStateWithoutInventedRequirements(): void
    {
        $this->f = new StudentRequirementsFixture();
        $this->service = $this->service();
        $before = $this->snapshot();
        $preview = $this->preview();
        self::assertNull($preview["requirements"]);
        self::assertNull($preview["selection"]);
        self::assertSame([], $preview["items"]);
        self::assertSame($before, $this->snapshot());
    }

    public function testCorruptApprovalProjectionBlocksPreviewWithoutRepairingIt(): void
    {
        $f = $this->f;
        $this->grant(["kind" => "waiver", "course_id" => $f->f->groupDefinition["courses"][0]["course_id"]]);
        $f->f->sis->sql->execute("DELETE FROM sis_requirement_adjustments WHERE assignment_id = ?", [
            $f->assignment->value,
        ]);
        $before = $this->snapshot();
        $this->expectException(CorruptHistoryException::class);
        try {
            $this->preview();
        } finally {
            self::assertSame($before, $this->snapshot());
        }
    }
}
