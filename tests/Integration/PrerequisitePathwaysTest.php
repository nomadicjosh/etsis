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
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PrerequisitePathwaysTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
    }

    private function editor(SisFixture $s): CoursePrerequisiteService
    {
        return new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogWorkflowRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
        );
    }

    private function save(SisFixture $s, EntityId $course, array $groups, ?int $minimum): array
    {
        $editor = $this->editor($s);
        return $editor->save(
            $course,
            $groups,
            $editor->current($course)["revision"],
            array_map("count", $groups),
            minimumRequirements: $minimum,
        );
    }

    private function pass(SisFixture $s, EntityId $student, \Domain\Registration\Section $section): void
    {
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
                reason: "Fictional prerequisite completion.",
            );
        $s->academicCredits()->commit($student, $section->id, $review["review_token"]);
    }

    public function testAlternativesAndRequirementCountsMatchEveryCompletionCombination(): void
    {
        $ids = array_map(static fn(): string => EntityId::generate()->value, range(1, 4));
        [$a, $b, $c, $d] = $ids;
        for ($needed = 1; $needed <= 3; $needed++) {
            $rule = new PrerequisiteGroups(
                [[$a, $b], [$c], [$b, $d]],
                [2, 1, 2],
                minimumRequirements: $needed,
            );
            for ($mask = 0; $mask < 16; $mask++) {
                $completed = array_values(
                    array_filter(
                        $ids,
                        static fn(string $id, int $i): bool => ($mask & (1 << $i)) !== 0,
                        ARRAY_FILTER_USE_BOTH,
                    ),
                );
                $math = in_array($a, $completed, true) && in_array($b, $completed, true);
                $statistics = in_array($c, $completed, true);
                $other = in_array($b, $completed, true) && in_array($d, $completed, true);
                $met = (int) $math + (int) $statistics + (int) $other;
                self::assertSame(max(0, $needed - $met), $rule->missing($completed));
                self::assertSame($met >= $needed, $rule->missing([...$completed, ...$completed]) === 0);
            }
        }
        foreach ([0, -1, 4] as $invalid) {
            try {
                new PrerequisiteGroups([[$a], [$b], [$c]], minimumRequirements: $invalid);
                self::fail("An impossible overall prerequisite minimum was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([], minimumRequirements: 1);
    }

    public function testGradeFloorsStillApplyWithinEachAlternativeAndRecordedEvidenceIsExact(): void
    {
        $assignment = EntityId::generate();
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $rule = new PrerequisiteGroups([[$a], [$b]], [1, 1], [2675, 3000], minimumRequirements: 1);
        $references = [];
        foreach ([$a => 2674, $b => 3000] as $course => $points) {
            $references[] = [
                "course_id" => $course,
                "credit_id" => EntityId::generate()->value,
                "source_id" => EntityId::generate()->value,
                "origin" => "transfer",
                "academic_version" => 1,
                "registration_version" => null,
                "pursuit_version" => 1,
                "grade_points_milli" => $points,
            ];
        }
        usort($references, static fn(array $x, array $y): int => strcmp($x["course_id"], $y["course_id"]));
        $decision = new PrerequisiteDecision($assignment, $rule->courses(), $references, $rule);
        self::assertTrue($decision->isMet());
        self::assertSame([false, true], array_column($decision->groupStatuses(), "met"));
        self::assertSame(
            ["required_count" => 1, "total_count" => 2, "completed_count" => 1],
            $decision->groupPolicy(),
        );
        $facts = $decision->retained();
        self::assertSame(1, $facts["requirement_minimum"]);
        self::assertSame($facts, PrerequisiteDecision::fromRetained($facts)->retained());
        $facts["requirement_minimum"] = 2;
        $this->expectException(CorruptHistoryException::class);
        PrerequisiteDecision::fromRetained($facts);
    }

    public function testOnlyOneCompletedRouteAllowsRegistrationAndLaterChangesPreserveOriginalEvidence(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $math = $s->section(20, 0);
        $moreMath = $s->section(20, 0);
        $statistics = $s->section(20, 0);
        $target = $s->section(20, 0);
        $groups = [[$math->courseId->value, $moreMath->courseId->value], [$statistics->courseId->value]];
        $this->save($s, $target->courseId, $groups, 1);
        $this->pass($s, $student->id, $statistics);
        $batches = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $batches->review($selection);
        $checks = $review["students"][0]["prerequisites"];
        self::assertSame("met", $checks["status"]);
        self::assertSame([false, true], array_column($checks["groups"], "met"));
        self::assertSame(1, $checks["group_policy"]["required_count"]);
        $s->access->staff = false;
        $enrollment = $s->service->register($student->id, $target->id);
        $s->access->staff = true;
        $event = iterator_to_array(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new EnrollmentHistoryId($enrollment->id->value),
            ),
        )[0];
        $evidence = $event->metaParam("prerequisite_evidence");
        self::assertSame(1, $evidence["requirement_minimum"]);
        self::assertCount(1, $evidence["support"]);
        $this->save($s, $target->courseId, $groups, null);
        $before = $s->sql->rows("sis_history_events");
        self::assertSame(
            $enrollment->id->value,
            $s->service->register($student->id, $target->id, true)->id->value,
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertTrue(PrerequisiteDecision::fromRetained($evidence)->isMet());
    }

    public function testChangingOnlyTheOverallMinimumInvalidatesPendingRegistrationAndPublicationReviews(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $a = $s->section(20, 0);
        $b = $s->section(20, 0);
        $target = $s->section(20, 0);
        $groups = [[$a->courseId->value], [$b->courseId->value]];
        $this->pass($s, $student->id, $a);
        $this->pass($s, $student->id, $b);
        $this->save($s, $target->courseId, $groups, 1);
        $publication = $f->publications()->review($target->courseId, "Fictional pending publication.");
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $batch = $s->registrationBatches();
        $review = $batch->review($selection);
        $this->save($s, $target->courseId, $groups, 2);
        try {
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail("An overall-count change bypassed pending registration review.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows("sis_enrollments", ["section_id" => $target->id->value]));
        }
        $this->expectException(ConcurrentModificationException::class);
        $f->publications()->publish($target->courseId, $publication["review_token"]);
    }

    public function testPublishedAlternativesStayWithTheSectionAndCannotBeWeakenedThroughProjectionChanges(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $a = $s->section(20, 0);
        $b = $s->section(20, 0);
        $target = $s->section(20, 0);
        $groups = [[$a->courseId->value], [$b->courseId->value]];
        $this->save($s, $target->courseId, $groups, 2);
        $review = $f->publications()->review($target->courseId, "Fictional published routes.");
        $published = $f->publications()->publish($target->courseId, $review["review_token"]);
        $version = new EntityId($published["operation_id"]);
        $binding = $f->bindings()->review($target->id, $version, "Fictional section publication.");
        $f->bindings()->commit($target->id, $binding["review_token"]);
        self::assertSame(
            2,
            new SqlCourseVersionProjection($s->sql)->get($version)->definition->prerequisiteGroups
                ->minimumRequirements,
        );
        $this->save($s, $target->courseId, $groups, 1);
        self::assertSame(
            2,
            $s->registration->courseForSection($s->registration->section($target->id))->prerequisiteGroups
                ->minimumRequirements,
        );
        $this->pass($s, $student->id, $a);
        $before = $s->sql->rows("sis_history_events");
        $s->sql->update("sis_course_versions", ["requirement_minimum" => 1], ["id" => $version->value]);
        try {
            $s->service->register($student->id, $target->id, true);
            self::fail("A weakened published projection authorized registration.");
        } catch (CorruptHistoryException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }

    public function testChangingTheRuleDuringFinalAuditRollsBackSeatsCreditChargesAndHistory(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $a = $s->section(20, 0);
        $target = $s->section();
        $this->pass($s, $student->id, $a);
        $this->save($s, $target->courseId, [[$a->courseId->value], [$a->courseId->value]], 1);
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($s, $target): void {
            $s->sql->update(
                "sis_course_prerequisite_groups",
                ["requirement_minimum" => 2],
                ["id" => $target->courseId->value],
            );
        });
        $batch = $s->registrationBatches(audit: $audit);
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $batch->review($selection);
        $before = [];
        $tables = ['sis_history_events', 'sis_enrollments', 'sis_academic_credits', 'sis_student_accounts',
            'sis_registration_batches', 'sis_course_prerequisite_groups'];
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
            self::fail("A final-audit rule change completed registration.");
        } catch (ConcurrentModificationException) {
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $s->sql->rows($table));
            }
        }
    }

    public function testCloningAndDirectReferenceRemovalPreserveTheOverallMinimum(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section();
        $a = $s->section();
        $b = $s->section();
        $c = $s->section();
        $this->save(
            $s,
            $target->courseId,
            [[$a->courseId->value], [$b->courseId->value], [$c->courseId->value]],
            2,
        );
        $review = $f->clones()->review($target->courseId);
        $clone = new \Domain\Catalog\Course(
            EntityId::generate(),
            "FICTIONAL.ROUTES",
            "Fictional routes",
            3000,
            false,
        );
        try {
            $f->clones()->commit($target->courseId, $clone, [$a->courseId], $review["revision"]);
            self::fail("A clone silently reduced its overall prerequisite minimum.");
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows("sis_courses", ["id" => $clone->id->value]));
        }
        $f->clones()->commit($target->courseId, $clone, [$a->courseId, $b->courseId], $review["revision"]);
        $records = new SqlCatalogWorkflowRepository($s->sql);
        self::assertSame(2, $records->prerequisiteGroups($clone->id)->minimumRequirements);
        try {
            $s->transactions->run(fn() => $s->registration->removePrerequisite($clone->id, $a->courseId));
            self::fail("Removing a prerequisite silently reduced its overall minimum.");
        } catch (ValidationException) {
            self::assertCount(2, $records->prerequisites($clone->id));
        }
        $this->expectException(ValidationException::class);
        $s->transactions->run(fn() => $s->registration->addPrerequisite($clone->id, $c->courseId));
    }

    public function testAlternativePrerequisitesDoNotMakeIndependentCoRequisitesOptional(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), "P", 0, true, false));
        $student = $s->student();
        $completed = $s->section(20, 0);
        $other = $s->section(20, 0);
        $lab = $s->section(20, 0);
        $target = $s->section(20, 0);
        $this->pass($s, $student->id, $completed);
        $editor = $this->editor($s);
        $editor->save(
            $target->courseId,
            [[$completed->courseId->value], [$other->courseId->value]],
            $editor->current($target->courseId)["revision"],
            corequisites: [$lab->courseId->value],
            minimumRequirements: 1,
        );
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $s->registrationBatches()->review($selection);
        self::assertSame("met", $review["students"][0]["prerequisites"]["status"]);
        self::assertSame("missing", $review["students"][0]["corequisites"]["status"]);
        $this->expectException(ValidationException::class);
        $s->service->register($student->id, $target->id, true);
    }

    public function testSubsetsNeverLowerTheOverallMinimumAndExplicitlyClearingAllRequirementsRemainsPossible(): void
    {
        $ids = array_map(static fn(): string => EntityId::generate()->value, range(1, 3));
        $groups = new PrerequisiteGroups(
            array_map(static fn(string $id): array => [$id], $ids),
            minimumRequirements: 2,
        );
        self::assertSame(2, $groups->subset(array_slice($ids, 0, 2))->minimumRequirements);
        self::assertNull($groups->subset([])->minimumRequirements);
        $this->expectException(ValidationException::class);
        $groups->subset([$ids[0]]);
    }
}
