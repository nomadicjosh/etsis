<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";
use Domain\Gradebook\ValueObject\GradingScaleDefinition;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use PHPUnit\Framework\TestCase;
final class GradingScaleTest extends TestCase
{
    private function definition(int $points = 4000): GradingScaleDefinition
    {
        return new GradingScaleDefinition([
            "grades" => [
                [
                    "code" => "A+",
                    "min_score_milli" => 97000,
                    "max_score_milli" => 100000,
                    "points_milli" => $points,
                    "passes" => true,
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
            ],
        ]);
    }
    private function set(ReusableGroupFixture $f, ?EntityId $program, GradingScaleDefinition|null $d): array
    {
        $s = $f->gradingScales();
        $r = $s->review($program, $d, "Fictional grading policy approval");
        return $s->commit($program, $r["review_token"]);
    }
    public function testARequiredReasonHasAFieldErrorAndCreatesNoPolicyHistory(): void
    {
        $f = new ReusableGroupFixture();
        $before = $f->sis->sql->rows("sis_history_events");
        foreach (["", " \n", "invalid\0reason"] as $reason) {
            try {
                $f->gradingScales()->review(null, $this->definition(), $reason);
                self::fail("An absent or invalid reason was accepted.");
            } catch (\Domain\Shared\Exception\ValidationException $e) {
                self::assertSame("reason", $e->errors[0]["field"]);
            }
        }
        self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
    }
    public function testDefaultGlobalAndProgramHierarchyWithIndependentCompleteDefinitions(): void
    {
        $f = new ReusableGroupFixture();
        $s = $f->gradingScales();
        self::assertSame("default", $s->resolve($f->sis->program)["source"]);
        self::assertSame(4000, $s->resolve()["maximum_milli"]);
        $global = $this->set($f, null, $this->definition(4300));
        self::assertSame($global["operation_id"], $s->resolve($f->sis->program)["version_id"]);
        $other = EntityId::generate();
        $f->sis->sql->insert("sis_programs", [
            "id" => $other->value,
            "code" => "GRAD",
            "title" => "Graduate program",
        ]);
        $override = $this->set($f, $other, $this->definition(5000));
        self::assertSame("program", $s->resolve($other)["source"]);
        self::assertSame(5000, $s->resolve($other)["maximum_milli"]);
        self::assertSame($override["operation_id"], $s->resolve($other)["version_id"]);
        self::assertSame(4300, $s->resolve($f->sis->program)["maximum_milli"]);
        $this->set($f, null, $this->definition(4000));
        self::assertSame(5000, $s->resolve($other)["maximum_milli"]);
        $this->set($f, $other, null);
        self::assertSame(4000, $s->resolve($other)["maximum_milli"]);
        self::assertSame("global", $s->resolve($other)["source"]);
    }
    public function testScoreRangesAndGradePointsAreSeparateAndUnmatchedScoresStayUnknown(): void
    {
        $d = $this->definition();
        self::assertSame(4000, $d->maximum());
        self::assertSame("A+", $d->gradeForScore(97000)["code"]);
        self::assertSame(4000, $d->gradeForScore(100000)["points_milli"]);
        self::assertNull($d->gradeForScore(96999));
        $raw = $d->definition;
        $raw["grades"][1] = [
            "code" => "A",
            "min_score_milli" => 93000,
            "max_score_milli" => 97000,
            "points_milli" => 3700,
            "passes" => true,
            "counts_gpa" => true,
        ];
        $this->expectException(InvalidArgumentException::class);
        new GradingScaleDefinition($raw);
    }
    public function testInvalidRangesAndDuplicateGradeCodesAreRejected(): void
    {
        $base = $this->definition()->definition;
        $cases = [];
        $foreachItems = [
            ["min_score_milli", null],
            ["min_score_milli", 101000],
            ["max_score_milli", 100001],
            ["points_milli", "4.0"],
        ];
        foreach ($foreachItems as [$field, $value]) {
            $d = $base;
            $d["grades"][0][$field] = $value;
            $cases[] = $d;
        }
        $d = $base;
        $d["grades"][] = $d["grades"][0];
        $cases[] = $d;
        foreach ($cases as $d) {
            try {
                new GradingScaleDefinition($d);
                self::fail("Invalid scale accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testReviewConcurrencyExactRetriesAndActorScopeChecks(): void
    {
        $f = new ReusableGroupFixture();
        $s = $f->gradingScales();
        $a = $s->review(null, $this->definition(), "Fictional first review");
        $b = $s->review(null, $this->definition(5000), "Fictional competing review");
        $receipt = $s->commit(null, $a["review_token"]);
        $this->set($f, null, $this->definition(4300));
        self::assertSame($receipt, $s->commit(null, $a["review_token"]));
        try {
            $s->commit(null, $b["review_token"]);
            self::fail("Stale review accepted.");
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        try {
            $s->commit($f->sis->program, $a["review_token"]);
            self::fail("Cross-scope token accepted.");
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $f->sis->access->actor = $f->sis->user();
        $this->expectException(AccessDeniedException::class);
        $s->commit(null, $a["review_token"]);
    }
    public function testDedicatedAuthoringPermissionAndProjectionVerification(): void
    {
        $f = new ReusableGroupFixture();
        $this->set($f, $f->sis->program, $this->definition());
        $f->sis->access->staff = false;
        $f->sis->access->grants = ["sis:student:read"];
        try {
            $f->gradingScales()->view($f->sis->program);
            self::fail("Read-only student permission edited scales.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->sis->access->staff = true;
        $version = $f->gradingScales()->resolve($f->sis->program)["version_id"];
        $f->sis->sql->update(
            "sis_grades",
            ["points_milli" => 5000],
            ["scale_version_id" => $version, "code" => "A+"],
        );
        $this->expectException(CorruptHistoryException::class);
        $f->gradingScales()->resolve($f->sis->program);
    }
    public function testDegreePublicationRetainsResolvedScaleAndRejectsChangedReview(): void
    {
        $f = new ReusableGroupFixture();
        $program = $f->sis->program;
        $this->set($f, null, $this->definition(4000));
        $f->degrees->save($f->degree, 0, new AuditRequirementsV3($f->definition));
        $r = $f->degrees->review($f->degree, "Fictional retained scale");
        $receipt = $f->degrees->publish($f->degree, $r["review_token"]);
        $selected = $f->degrees->version($f->degree, new EntityId($receipt["operation_id"]));
        self::assertSame(4, $selected["schema_version"]);
        self::assertSame(4000, $selected["grade_scale"]["maximum_milli"]);
        $stale = $f->degrees->review($f->degree, "Fictional pending degree");
        $this->set($f, $program, $this->definition(5000));
        self::assertSame($selected, $f->degrees->version($f->degree, new EntityId($receipt["operation_id"])));
        self::assertSame($receipt, $f->degrees->publish($f->degree, $r["review_token"]));
        $this->expectException(ConcurrentModificationException::class);
        $f->degrees->publish($f->degree, $stale["review_token"]);
    }
    public function testIndependentGroupCanUseProgramScaleAsAuthoringContext(): void
    {
        $f = new ReusableGroupFixture();
        $this->set($f, $f->sis->program, $this->definition(5000));
        $d = $f->groupDefinition;
        unset($d["min_gpa_milli"]);
        $d += [
            "requirement_type" => "credits",
            "min_courses" => 0,
            "min_course_grade_points_milli" => 4500,
            "max_course_grade_points_milli" => 5000,
            "grade_scale_program_id" => $f->sis->program->value,
        ];
        $f->groups->save($f->group, 0, new CourseGroupDefinitionV2($d));
        $v = $f->publishGroup();
        self::assertSame(5000, $f->groups->view($f->group)["grade_point_scale"]["maximum_milli"]);
        $f->definition["groups"][0]["version_id"] = $v->value;
        $f->degrees->save($f->degree, 0, new AuditRequirementsV3($f->definition));
        $f->degrees->review($f->degree, "Fictional graduate requirements");
        $this->set($f, $f->sis->program, null);
        $this->expectException(InvalidArgumentException::class);
        $f->degrees->review($f->degree, "Fictional incompatible global scale");
    }
    public function testLateAuditFailureRollsBackScaleHistoryAndProjection(): void
    {
        $f = new ReusableGroupFixture();
        $before = $f->sis->sql->rows("sis_history_events");
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willThrowException(new RuntimeException("Fictional audit failure"));
        $s = $f->gradingScales($audit);
        $r = $s->review(null, $this->definition(), "Fictional rollback check");
        try {
            $s->commit(null, $r["review_token"]);
            self::fail("Late failure was swallowed.");
        } catch (RuntimeException $error) {
            self::assertSame("Fictional audit failure", $error->getMessage());
        }
        self::assertSame($before, $f->sis->sql->rows("sis_history_events"));
        self::assertSame([], $f->sis->sql->rows("sis_grading_scales"));
        self::assertSame([], $f->sis->sql->rows("sis_grades"));
        self::assertSame("default", $f->gradingScales()->resolve()["source"]);
    }
}
