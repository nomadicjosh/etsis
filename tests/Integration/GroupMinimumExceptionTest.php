<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/StudentRequirementsFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;

final class GroupMinimumExceptionTest extends TestCase
{
    private StudentRequirementsFixture $f;
    private RequirementAdjustmentService $service;

    protected function setUp(): void
    {
        $this->f = new StudentRequirementsFixture();
        $f = $this->f->f;
        $group = CourseGroupDefinitionV2::blank("General education")->definition;
        $group["requirement_type"] = "courses";
        $group["min_courses"] = 4;
        $group["min_course_grade_points_milli"] = 2000;
        $group["courses"] = $f->groupDefinition["courses"];
        $group["rules"] = $f->groupDefinition["rules"];
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($group));
        $f->definition["groups"] = [
            ["group_id" => $f->group->value, "version_id" => $f->publishGroup()->value],
        ];
        $f->degrees->save($f->degree, 1, new AuditRequirementsV3($f->definition));
        $this->f->version = new EntityId($f->publishDegree()["operation_id"]);
        $this->f->assign();
        $this->service = $this->service();
    }

    private function service(
        ?AuditTrail $audit = null,
        ?HistoricalEventStore $history = null,
    ): RequirementAdjustmentService {
        $f = $this->f;
        $s = $f->f->sis;
        return new RequirementAdjustmentService(
            new SqlRequirementAdjustmentRepository($s->sql),
            $f->service,
            new SqlStudentRecordRepository($s->sql),
            $history ?? $f->f->history,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $f->f->catalog->fixture->cipher,
            $s->clock,
            new PublishedRequirementsReader($f->f->history),
            new SqlReplacementCourseRepository($s->sql),
        );
    }

    private function review(string $key, int $required): array
    {
        return $this->service->reviewGrant(
            $this->f->student,
            $this->f->assignment,
            [
                "kind" => "minimum",
                "group_id" => $this->f->f->group->value,
                "minimum" => $key,
                "required_milli" => $required,
            ],
            "Fictional registrar group exception",
        );
    }

    private function commit(array $review): array
    {
        return $this->service->commit($this->f->student, $this->f->assignment, $review["review_token"]);
    }

    private function view(): array
    {
        return $this->service->view($this->f->student, $this->f->assignment);
    }

    private function group(array $data): array
    {
        return $data["effective_minimums"]["groups"][$this->f->f->group->value];
    }

    public function testWholeCourseCountsRoundTripThroughReviewHistoryAndEffectiveMinimums(): void
    {
        $f = $this->f;
        $before = $f->f->degrees->version($f->f->degree, $f->version);
        $view = $this->view();
        $targets = array_column($view["targets"], null, "minimum");
        self::assertSame(4000, $targets["min_courses"]["original_milli"]);
        self::assertSame(2000, $targets["min_course_grade_points_milli"]["original_milli"]);
        self::assertSame("General education", $view["group_labels"][$f->f->group->value]);
        $history = $f->f->digest("sis_history_events");
        $review = $this->review("min_courses", 3000);
        self::assertSame($history, $f->f->digest("sis_history_events"));
        self::assertSame(3, $this->group($review)["min_courses"]);
        self::assertSame(4000, $review["adjustment"]["definition"]["original_milli"]);
        $this->commit($review);
        self::assertSame(3, $this->group($this->view())["min_courses"]);
        $entry = $this->service->history($f->student, $f->assignment)["items"][0];
        self::assertSame(3000, $entry["definition"]["required_milli"]);
        self::assertSame("General education", $entry["scope_label"]);
        self::assertSame($before, $f->f->degrees->version($f->f->degree, $f->version));
        self::assertSame([], $f->f->sis->sql->rows("sis_academic_credits"));
    }

    public function testGradePointFloorCanBeReducedAndRevokedWithoutChangingGradesOrOtherMinimums(): void
    {
        $f = $this->f;
        $grades = $f->f->digest("sis_grades");
        $review = $this->review("min_course_grade_points_milli", 1700);
        self::assertSame(1700, $this->group($review)["min_course_grade_points_milli"]);
        self::assertSame(4, $this->group($review)["min_courses"]);
        $this->commit($review);
        self::assertSame(1700, $this->group($this->view())["min_course_grade_points_milli"]);
        $revoke = $this->service->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($review["adjustment"]["adjustment_id"]),
            "Fictional restoration of group floor",
        );
        self::assertSame(2000, $this->group($revoke)["min_course_grade_points_milli"]);
        $this->commit($revoke);
        self::assertSame(2000, $this->group($this->view())["min_course_grade_points_milli"]);
        self::assertSame($grades, $f->f->digest("sis_grades"));
    }

    public function testGradePointDisplayUsesTheSelectedDegreeScaleAfterTodaysScaleChanges(): void
    {
        $f = $this->f;
        $grades = static fn(int $maximum): array => [
            [
                "code" => "A",
                "min_score_milli" => null,
                "max_score_milli" => null,
                "points_milli" => $maximum,
                "passes" => true,
                "counts_gpa" => true,
            ],
        ];
        $f->f->sis->gradeScale($grades(5000));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $f->f->sis->gradeScale($grades(4300));
        self::assertSame(5000, $this->view()["grade_scale"]["maximum_milli"]);
        $review = $this->review("min_course_grade_points_milli", 1700);
        self::assertSame(5000, $review["grade_scale"]["maximum_milli"]);
        self::assertSame(4000, $this->group($review)["max_course_grade_points_milli"]);
    }

    public function testFractionalCountsNonReductionsAndUnsupportedTargetsWriteNothing(): void
    {
        $before = $this->f->f->digest("sis_history_events");
        $cases = [
            ["min_courses", 3500],
            ["min_courses", 4000],
            ["min_courses", -1000],
            ["min_course_grade_points_milli", 2000],
            ["max_course_grade_points_milli", 1000],
        ];
        foreach ($cases as [$key, $value]) {
            try {
                $this->review($key, $value);
                self::fail("Invalid group reduction was accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame($before, $this->f->f->digest("sis_history_events"));
            }
        }
    }

    public function testReplacementRevocationAndOldRetriesNeverUndoLaterDecisions(): void
    {
        $f = $this->f;
        $first = $this->review("min_courses", 3000);
        $receipt = $this->commit($first);
        $second = $this->review("min_courses", 2000);
        $this->commit($second);
        self::assertSame($receipt, $this->commit($first));
        self::assertSame(2, $this->group($this->view())["min_courses"]);
        $revoke = $this->service->reviewRevoke(
            $f->student,
            $f->assignment,
            new EntityId($second["adjustment"]["adjustment_id"]),
            "Fictional course-count restoration",
        );
        $this->commit($revoke);
        $before = $f->f->digest("sis_history_events");
        self::assertSame($receipt, $this->commit($first));
        self::assertSame(4, $this->group($this->view())["min_courses"]);
        self::assertSame($before, $f->f->digest("sis_history_events"));
    }

    public function testAnotherDegreeSelectionDoesNotInheritEarlierGroupReductions(): void
    {
        $f = $this->f;
        $review = $this->review("min_courses", 2000);
        $receipt = $this->commit($review);
        $definition = $f->f->definition;
        $definition["name"] = "Fictional later degree publication";
        $f->f->degrees->save($f->f->degree, 2, new AuditRequirementsV3($definition));
        $f->version = new EntityId($f->f->publishDegree()["operation_id"]);
        $f->assign();
        $view = $this->view();
        self::assertFalse($view["items"][0]["applicable"]);
        self::assertSame(4, $this->group($view)["min_courses"]);
        self::assertSame($receipt, $this->commit($review));
    }

    public function testLateAuditFailureRollsBackAllNewCountApprovalFacts(): void
    {
        $f = $this->f;
        $review = $this->review("min_courses", 3000);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure"));
        $tables = [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_requirement_adjustments",
            "sis_audit",
        ];
        $before = array_map($f->f->digest(...), $tables);
        try {
            $this->service($audit)->commit($f->student, $f->assignment, $review["review_token"]);
            self::fail("An audit failure left a partial approval.");
        } catch (RuntimeException) {
            self::assertSame($before, array_map($f->f->digest(...), $tables));
        }
        $this->commit($review);
        self::assertSame(3, $this->group($this->view())["min_courses"]);
    }

    public function testRevokedApprovalAuthorityDuringReplayBlocksReviewsAndAcceptedRetries(): void
    {
        $f = $this->f;
        $review = $this->review("min_courses", 3000);
        $this->commit($review);
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method("append");
        $history->method("page")->willReturnCallback(function ($stream, $cursor, $limit) use ($f) {
            $page = $f->f->history->page($stream, $cursor, $limit);
            $f->f->sis->access->staff = false;
            $f->f->sis->access->grants = ["sis:student:read"];
            return $page;
        });
        $service = $this->service(history: $history);
        $calls = [
            fn() => $service->reviewGrant(
                $f->student,
                $f->assignment,
                [
                    "kind" => "minimum",
                    "group_id" => $f->f->group->value,
                    "minimum" => "min_courses",
                    "required_milli" => 2000,
                ],
                "Fictional revoked authority",
            ),
            fn() => $service->reviewRevoke(
                $f->student,
                $f->assignment,
                new EntityId($review["adjustment"]["adjustment_id"]),
                "Fictional revoked authority",
            ),
            fn() => $service->commit($f->student, $f->assignment, $review["review_token"]),
        ];
        $before = $f->f->digest("sis_history_events");
        foreach ($calls as $call) {
            $f->f->sis->access->staff = true;
            try {
                $call();
                self::fail("Revoked approval permission was ignored during replay.");
            } catch (AccessDeniedException) {
                self::assertSame($before, $f->f->digest("sis_history_events"));
            }
        }
    }
}
