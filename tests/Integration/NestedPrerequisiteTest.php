<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Catalog\ValueObject\PrerequisiteGroups;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use PHPUnit\Framework\TestCase;

final class NestedPrerequisiteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
    }

    private function editor(CatalogWorkflowFixture $f): CoursePrerequisiteService
    {
        $s = $f->sis;
        return new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            $f->operations,
            $s->access,
            $s->transactions,
            $s->audit,
        );
    }

    private function rule(): array
    {
        return ["minimum" => 2, "children" => [0, ["minimum" => 1, "children" => [1, 2]]]];
    }

    private function save(CatalogWorkflowFixture $f, EntityId $course, array $groups, array $rule): array
    {
        $editor = $this->editor($f);
        return $editor->save($course, $groups, $editor->current($course)["revision"], rule: $rule);
    }

    private function pass(
        CatalogWorkflowFixture $f,
        EntityId $student,
        \Domain\Registration\Section $section,
    ): void {
        $s = $f->sis;
        $enrollment = $s->service->register($student, $section->id, true);
        $grade = new EntityId($s->gradeCatalog()->byCode("P", $student)["id"]);
        $review = $s
            ->academicCredits()
            ->review(
                $student,
                $section->id,
                $enrollment->id,
                $grade,
                0,
                reason: "Fictional nested prerequisite completion.",
            );
        $s->academicCredits()->commit($student, $section->id, $review["review_token"]);
    }

    public function testNestedCountsAndGradeFloorsMatchEveryCompletionCombination(): void
    {
        $ids = array_map(static fn(): string => EntityId::generate()->value, range(1, 5));
        [$a, $b, $c, $d, $e] = $ids;
        // A and either two qualifying maths or at least one of D/E. A shared course still counts in each block.
        $groups = new PrerequisiteGroups(
            [[$a], [$b, $c], [$a, $d, $e]],
            [1, 2, 2],
            [null, 2675, 3000],
            corequisites: [EntityId::generate()->value],
            rule: $this->rule(),
        );
        foreach ([2674, 2675, 3000] as $points) {
            for ($mask = 0; $mask < 32; $mask++) {
                $completed = array_values(
                    array_filter(
                        $ids,
                        static fn(string $id, int $i): bool => ($mask & (1 << $i)) !== 0,
                        ARRAY_FILTER_USE_BOTH,
                    ),
                );
                $math = $points >= 2675 && in_array($b, $completed, true) && in_array($c, $completed, true);
                $other = $points >= 3000 && count(array_intersect([$a, $d, $e], $completed)) >= 2;
                $expected = in_array($a, $completed, true) && ($math || $other);
                self::assertSame(
                    $expected,
                    $groups->missing($completed, array_fill_keys($ids, $points)) === 0,
                );
                self::assertSame(
                    $expected,
                    $groups->missing([...$completed, ...$completed], array_fill_keys($ids, $points)) === 0,
                );
            }
        }
    }

    public function testMalformedAmbiguousUnboundedAndRepeatedRequirementsAreRejected(): void
    {
        $groups = array_map(static fn(): array => [EntityId::generate()->value], range(1, 3));
        $deep = ["minimum" => 1, "children" => [0, 1, 2]];
        for ($i = 0; $i < 5; $i++) {
            $deep = ["minimum" => 1, "children" => [$deep]];
        }
        $invalid = [
            [],
            ["minimum" => 0, "children" => [0, 1, 2]],
            ["minimum" => 4, "children" => [0, 1, 2]],
            ["minimum" => "1", "children" => [0, 1, 2]],
            ["minimum" => 1, "children" => [0, 0, 1, 2]],
            ["minimum" => 1, "children" => [0, 1]],
            ["minimum" => 1, "children" => [0, 1, 3]],
            ["minimum" => 1, "children" => [0, 1, true]],
            ["minimum" => 1, "children" => []],
            ["minimum" => 1, "children" => [0, 1, 2], "extra" => true],
            $deep,
        ];
        foreach ($invalid as $bad) {
            try {
                new PrerequisiteGroups($groups, rule: $bad);
                self::fail("An incomplete or ambiguous nested prerequisite rule was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups($groups, minimumRequirements: 1, rule: $this->rule());
    }

    public function testCountedNestedBranchNeedsItsOwnMinimumRatherThanTheFlatTotal(): void
    {
        $ids = array_map(static fn(): string => EntityId::generate()->value, range(1, 4));
        $groups = new PrerequisiteGroups(
            array_map(static fn(string $id): array => [$id], $ids),
            rule: ["minimum" => 2, "children" => [0, ["minimum" => 2, "children" => [1, 2, 3]]]],
        );
        for ($mask = 0; $mask < 16; $mask++) {
            $completed = array_values(
                array_filter(
                    $ids,
                    static fn(string $id, int $i): bool => ($mask & (1 << $i)) !== 0,
                    ARRAY_FILTER_USE_BOTH,
                ),
            );
            $expected = in_array($ids[0], $completed, true) &&
            count(array_intersect(array_slice($ids, 1), $completed)) >= 2;
            self::assertSame($expected, $groups->missing($completed) === 0);
        }
    }

    public function testLargestAllowedRuleIsBoundedAndRejectsExtraBranchNodes(): void
    {
        $ids = array_map(static fn(): string => EntityId::generate()->value, range(1, 100));
        $children = array_map(
            static fn(int $i): int|array => $i < 98 ? ["minimum" => 1, "children" => [$i]] : $i,
            range(0, 99),
        );
        $groups = new PrerequisiteGroups(
            array_map(static fn(string $id): array => [$id], $ids),
            rule: ["minimum" => 100, "children" => $children],
        );
        self::assertSame(100, $groups->missing([]));
        self::assertSame(0, $groups->missing($ids));
        $children[98] = ["minimum" => 1, "children" => [98]];
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups($groups->groups, rule: ["minimum" => 100, "children" => $children]);
    }

    public function testStudentAndStaffUseTheSameRuleAndRetainOriginalAcceptance(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $sections = [$s->section(20, 0), $s->section(20, 0), $s->section(20, 0)];
        $target = $s->section(20, 0);
        $groups = array_map(static fn($section): array => [$section->courseId->value], $sections);
        $this->save($f, $target->courseId, $groups, $this->rule());
        $this->pass($f, $student->id, $sections[1]);
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $batch = $s->registrationBatches();
        self::assertSame("missing", $batch->review($selection)["students"][0]["prerequisites"]["status"]);
        $this->pass($f, $student->id, $sections[0]);
        $review = $batch->review($selection);
        $check = $review["students"][0]["prerequisites"];
        self::assertSame("met", $check["status"]);
        self::assertTrue($check["group_policy"]["rule"]["met"]);
        self::assertFalse($check["group_policy"]["rule"]["children"][1]["children"][1]["met"]);
        self::assertStringNotContainsString(
            "credit_id",
            json_encode($check["group_policy"], JSON_THROW_ON_ERROR),
        );
        $s->access->staff = false;
        $enrollment = $s->service->register($student->id, $target->id);
        $s->access->staff = true;
        $event = iterator_to_array(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new EnrollmentHistoryId($enrollment->id->value),
            ),
        )[0];
        $evidence = $event->metaParam("prerequisite_evidence");
        self::assertEquals($this->rule(), $evidence["requirement_rule"]);
        self::assertEquals($evidence, PrerequisiteDecision::fromRetained($evidence)->retained());
        $staffStudent = $s->student();
        $this->pass($f, $staffStudent->id, $sections[0]);
        $this->pass($f, $staffStudent->id, $sections[2]);
        $staffSelection = new RegistrationBatchSelection($target->id, $staffStudent->id);
        $staffReview = $batch->review($staffSelection);
        self::assertTrue($staffReview['students'][0]['prerequisites']['group_policy']['rule']['met']);
        $batch->commit(
            new EntityId($staffReview['operation_id']),
            $staffSelection,
            $staffReview['revision'],
            $staffReview['review_token']
        );
        self::assertNotNull($s->registration->enrollment($staffStudent->id, $target->id));
        $this->save($f, $target->courseId, $groups, ["minimum" => 3, "children" => [0, 1, 2]]);
        $before = $s->sql->rows("sis_history_events");
        self::assertSame(
            $enrollment->id->value,
            $s->service->register($student->id, $target->id, true)->id->value,
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $evidence["requirement_rule"]["children"][1]["minimum"] = 2;
        $this->expectException(CorruptHistoryException::class);
        PrerequisiteDecision::fromRetained($evidence);
    }

    public function testChangingOnlyNestedLogicInvalidatesPendingReviews(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section(20, 0);
        $groups = array_map(static fn(): array => [$s->section(20, 0)->courseId->value], range(1, 3));
        $this->save($f, $target->courseId, $groups, $this->rule());
        $proof = $f->publications()->review($target->courseId, "Fictional pending nested publication.");
        $saved = $this->editor($f)->current($target->courseId);
        $this->save($f, $target->courseId, $groups, ["minimum" => 1, "children" => [0, 1, 2]]);
        try {
            $this->editor($f)->save($target->courseId, $groups, $saved["revision"], rule: $this->rule());
            self::fail("A stale nested editor replaced later changes.");
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $this->editor($f)->current($target->courseId)["rule"]["minimum"]);
        }
        $this->expectException(ConcurrentModificationException::class);
        $f->publications()->publish($target->courseId, $proof["review_token"]);
    }

    public function testBoundSectionsKeepPublishedLogicAndRejectTamperedProjections(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $target = $s->section(20, 0);
        $prior = $s->section(20, 0);
        $groups = [
            [$prior->courseId->value],
            [$s->section(20, 0)->courseId->value],
            [$s->section(20, 0)->courseId->value],
        ];
        $this->pass($f, $student->id, $prior);
        $this->save($f, $target->courseId, $groups, $this->rule());
        $proof = $f->publications()->review($target->courseId, "Fictional retained nested publication.");
        self::assertSame($this->rule(), $proof["definition"]["prerequisite_rule"]);
        $receipt = $f->publications()->publish($target->courseId, $proof["review_token"]);
        $version = new EntityId($receipt["operation_id"]);
        $bind = $f->bindings()->review($target->id, $version, "Fictional nested section binding.");
        $f->bindings()->commit($target->id, $bind["review_token"]);
        $this->save($f, $target->courseId, $groups, ["minimum" => 1, "children" => [0, 1, 2]]);
        self::assertSame(
            $this->rule(),
            $s->registration->courseForSection($target)->prerequisiteGroups->rule->definition,
        );
        self::assertSame($receipt, $f->publications()->publish($target->courseId, $proof["review_token"]));
        $s->sql->update(
            "sis_course_versions",
            ["rule_json" => json_encode(["minimum" => 1, "children" => [0, 1, 2]], JSON_THROW_ON_ERROR)],
            ["id" => $version->value],
        );
        $course = new SqlCourseVersionProjection($s->sql)->get($version);
        self::assertSame(1, $course->definition->prerequisiteGroups->rule->definition["minimum"]);
        $before = $s->sql->rows("sis_history_events");
        try {
            $s->service->register($student->id, $target->id, true);
            self::fail("A weakened cached nested publication bypassed protected verification.");
        } catch (CorruptHistoryException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }

    public function testNestedClonesPreserveRulesAndRejectAmbiguousPartialOmissions(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section(20, 0);
        $ids = array_map(static fn(): EntityId => $s->section(20, 0)->courseId, range(1, 3));
        $this->save(
            $f,
            $target->courseId,
            array_map(static fn(EntityId $id): array => [$id->value], $ids),
            $this->rule(),
        );
        $proof = $f->clones()->review($target->courseId);
        $clone = new \Domain\Catalog\Course(
            EntityId::generate(),
            "FICTIONAL.NESTED",
            "Fictional nested clone",
            3000,
            false,
        );
        try {
            $f->clones()->commit($target->courseId, $clone, array_slice($ids, 0, 2), $proof["revision"]);
            self::fail("A partial clone silently rewrote nested logic.");
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows("sis_courses", ["id" => $clone->id->value]));
        }
        $f->clones()->commit($target->courseId, $clone, $ids, $proof["revision"]);
        self::assertSame($this->rule(), $f->operations->prerequisiteGroups($clone->id)->rule->definition);
        self::assertNull($f->operations->prerequisiteGroups($clone->id)->subset([])->rule);
    }

    public function testFinalNestedRuleChangeRollsBackRegistrationBillingAndHistoryTogether(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $prior = $s->section(20, 0);
        $target = $s->section();
        $this->pass($f, $student->id, $prior);
        $this->save($f, $target->courseId, array_fill(0, 3, [$prior->courseId->value]), $this->rule());
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($s, $target): void {
            $s->sql->update(
                "sis_course_prerequisite_groups",
                ["rule_json" => json_encode(["minimum" => 3, "children" => [0, 1, 2]], JSON_THROW_ON_ERROR)],
                ["id" => $target->courseId->value],
            );
        });
        $batch = $s->registrationBatches(audit: $audit);
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $batch->review($selection);
        $before = [];
        $tables = [
            "sis_history_events", "sis_enrollments", "sis_academic_credits", "sis_student_accounts",
            "sis_registration_batches", "sis_course_prerequisite_groups",
        ];
        foreach ($tables as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("A nested rule changed during final registration confirmation.");
        } catch (ConcurrentModificationException) {
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $s->sql->rows($table));
            }
        }
    }
}
