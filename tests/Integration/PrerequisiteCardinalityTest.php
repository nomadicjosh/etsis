<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Catalog\ValueObject\CourseDefinition;
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

final class PrerequisiteCardinalityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
    }

    private function editor(SisFixture $s): CoursePrerequisiteService
    {
        return new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogWorkflowRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit
        );
    }

    private function save(
        SisFixture $s,
        EntityId $course,
        array $groups,
        ?array $minimums = null,
        ?array $floors = null
    ): array {
        $editor = $this->editor($s);
        return $editor->save($course, $groups, $editor->current($course)['revision'], $minimums, $floors);
    }

    private function pass(
        SisFixture $s,
        EntityId $student,
        \Domain\Registration\Section $section,
        string $code = 'P'
    ): \Domain\Registration\Enrollment {
        $enrollment = $s->service->register($student, $section->id);
        $grade = new EntityId($s->gradeCatalog()->byCode($code, $student)['id']);
        $review = $s->academicCredits()->review(
            $student,
            $section->id,
            $enrollment->id,
            $grade,
            0,
            reason: 'Fictional completed prerequisite'
        );
        $s->academicCredits()->commit($student, $section->id, $review['review_token']);
        return $enrollment;
    }

    public function testSharedCoursesAndCountsMatchLiteralTruthAcrossEveryCompletionCombination(): void
    {
        $ids = array_map(static fn(): string => EntityId::generate()->value, range(1, 4));
        [$a, $b, $c, $d] = $ids;
        $rule = new PrerequisiteGroups([[$a, $b, $c], [$b, $d], [$a, $d]], [2, 1, 1]);
        self::assertCount(4, $rule->courses());
        for ($mask = 0; $mask < 16; $mask++) {
            $completed = array_values(array_filter($ids, static fn(string $id, int $i): bool =>
                ($mask & (1 << $i)) !== 0, ARRAY_FILTER_USE_BOTH));
            $literal = ((int) in_array($a, $completed, true) + (int) in_array($b, $completed, true) +
                (int) in_array($c, $completed, true) >= 2) &&
            (in_array($b, $completed, true) || in_array($d, $completed, true)) &&
            (in_array($a, $completed, true) || in_array($d, $completed, true));
            self::assertSame($literal, $rule->missing($completed) === 0);
            self::assertSame($literal, $rule->missing([...$completed, ...$completed]) === 0);
        }
        foreach ([[], [0], [-1], [4], ['2'], [true], [null], [1, 1]] as $invalid) {
            try {
                new PrerequisiteGroups([[$a, $b, $c]], $invalid);
                self::fail('Invalid prerequisite course counts were accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $dense = new PrerequisiteGroups(array_fill(0, 100, [$a, $b, $c, $d, EntityId::generate()->value]));
        self::assertCount(5, $dense->courses());
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([...$dense->groups, [$a]]);
    }

    public function testRegistrationCountsDistinctPassingCoursesAndReusesThemAcrossPrerequisiteRequirements(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $student = $s->student();
        $a = $s->section(20, 0);
        $b = $s->section(20, 0);
        $c = $s->section(20, 0);
        $target = $s->section(20, 0);
        $groups = [[$a->courseId->value, $b->courseId->value, $c->courseId->value], [$b->courseId->value]];
        $this->save($s, $target->courseId, $groups, [2, 1]);
        $this->pass($s, $student->id, $b);
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $s->registrationBatches()->review($selection);
        $checks = $review['students'][0]['prerequisites'];
        self::assertSame('missing', $checks['status']);
        self::assertSame(1, $checks['missing_count']);
        self::assertSame([false, true], array_column($checks['groups'], 'met'));
        self::assertSame([2, 1], array_column($checks['groups'], 'required_count'));
        self::assertSame([1, 1], array_column($checks['groups'], 'completed_count'));
        $this->pass($s, $student->id, $a);
        $ready = $s->registrationBatches()->review($selection);
        self::assertSame('met', $ready['students'][0]['prerequisites']['status']);
        $actor = $s->access->actor;
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $s->access->staff = false;
        $enrollment = $s->service->register($student->id, $target->id, byStaff: false);
        $s->access->actor = $actor;
        $s->access->staff = true;
        $event = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->id->value)))[0];
        $evidence = $event->metaParam('prerequisite_evidence');
        self::assertSame([2, 1], $evidence['requirement_minimums']);
        self::assertCount(3, $evidence['required_course_ids']);
        self::assertCount(2, $evidence['support']);
        self::assertTrue(PrerequisiteDecision::fromRetained($evidence)->isMet());
        $this->save($s, $target->courseId, $groups, [3, 1]);
        self::assertSame($enrollment->id->value, $s->service->register($student->id, $target->id)->id->value);
        self::assertSame($evidence, iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->id->value)))[0]
            ->metaParam('prerequisite_evidence'));
    }

    public function testCountChangesStaleAnOtherwiseUnchangedCoursePublicationAndRetainSelectedSectionMeaning(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section();
        $a = $s->section();
        $b = $s->section();
        $groups = [[$a->courseId->value, $b->courseId->value]];
        $this->save($s, $target->courseId, $groups, [1]);
        $review = $f->publications()->review($target->courseId, 'Fictional initial choices');
        $this->save($s, $target->courseId, $groups, [2]);
        try {
            $f->publications()->publish($target->courseId, $review['review_token']);
            self::fail('An old review silently accepted a changed count.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows('sis_course_versions'));
        }
        $review = $f->publications()->review($target->courseId, 'Fictional reviewed count');
        $published = $f->publications()->publish($target->courseId, $review['review_token']);
        $version = new EntityId($published['operation_id']);
        $binding = $f->bindings()->review($target->id, $version, 'Fictional section definition');
        $f->bindings()->commit($target->id, $binding['review_token']);
        $retained = new SqlCourseVersionProjection($s->sql)->get($version);
        self::assertSame([2], $retained->definition->prerequisiteGroups->minimums);
        self::assertSame($review['definition'], $retained->definition->describe());
        $this->save($s, $target->courseId, $groups, [1]);
        self::assertSame([2], $s->registration->courseForSection($s->registration->section($target->id))
            ->prerequisiteGroups->minimums);
        self::assertSame($published, $f->publications()->publish($target->courseId, $review['review_token']));
        $s->sql->update(
            'sis_course_prerequisite_groups',
            ['minimums_json' => 'null'],
            ['id' => $target->courseId->value]
        );
        self::assertSame([2], $s->registration->courseForSection($s->registration->section($target->id))
            ->prerequisiteGroups->minimums);
        $this->expectException(CorruptHistoryException::class);
        $this->editor($s)->current($target->courseId);
    }

    public function testCloneAndIndividualReferenceChangesCannotSilentlyReduceACourseCount(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section();
        $a = $s->section();
        $b = $s->section();
        $c = $s->section();
        $groups = [[$a->courseId->value, $b->courseId->value, $c->courseId->value]];
        $this->save($s, $target->courseId, $groups, [2]);
        $review = $f->clones()->review($target->courseId);
        $clone = new Course(EntityId::generate(), 'FICTIONAL.COUNT', 'Fictional counted clone', 3000, false);
        try {
            $f->clones()->commit($target->courseId, $clone, [$a->courseId], $review['revision']);
            self::fail('A clone silently reduced a two-course requirement to one.');
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows('sis_courses', ['id' => $clone->id->value]));
        }
        $f->clones()->commit($target->courseId, $clone, [$a->courseId, $b->courseId], $review['revision']);
        $repository = new SqlCatalogWorkflowRepository($s->sql);
        self::assertSame([2], $repository->prerequisiteGroups($clone->id)->minimums);
        try {
            $s->transactions->run(fn() => $s->registration->removePrerequisite($clone->id, $a->courseId));
            self::fail('Removing a course silently lowered the count.');
        } catch (ValidationException) {
            self::assertCount(2, $repository->prerequisites($clone->id));
        }
        $s->transactions->run(fn() => $s->registration->addPrerequisite($target->courseId, $a->courseId));
        $changed = $repository->prerequisiteGroups($target->courseId);
        self::assertSame([2, 1], $changed->minimums);
        self::assertCount(3, $changed->courses());
        self::assertSame([[$a->courseId->value]], array_slice($changed->groups, -1));
        self::assertSame([], $changed->subset([])->groups);
    }

    public function testAChangedCountInvalidatesRegistrationReviewEvenWhenTheStudentStillQualifies(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $student = $s->student();
        $a = $s->section(20, 0);
        $b = $s->section(20, 0);
        $target = $s->section(20, 0);
        $this->pass($s, $student->id, $a);
        $this->pass($s, $student->id, $b);
        $groups = [[$a->courseId->value, $b->courseId->value]];
        $this->save($s, $target->courseId, $groups, [1]);
        $batch = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $batch->review($selection);
        $this->save($s, $target->courseId, $groups, [2]);
        $before = $s->sql->rows('sis_history_events');
        try {
            $batch->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('The confirmation accepted a different prerequisite count without review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertSame([], $s->sql->rows('sis_enrollments', ['section_id' => $target->id->value]));
        }
        $fresh = $batch->review($selection);
        self::assertSame('met', $fresh['students'][0]['prerequisites']['status']);
        $batch->commit(new EntityId($fresh['operation_id']), $selection, $fresh['revision'], $fresh['review_token']);
        self::assertCount(1, $s->sql->rows('sis_enrollments', ['section_id' => $target->id->value]));
    }

    public function testAChangedPublishedCountCannotWeakenNewRegistration(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $a = $s->section(20, 0);
        $b = $s->section(20, 0);
        $target = $s->section(20, 0);
        $this->pass($s, $student->id, $a);
        $this->save($s, $target->courseId, [[$a->courseId->value, $b->courseId->value]], [2]);
        $review = $f->publications()->review($target->courseId, 'Fictional protected prerequisite count');
        $published = $f->publications()->publish($target->courseId, $review['review_token']);
        $version = new EntityId($published['operation_id']);
        $binding = $f->bindings()->review($target->id, $version, 'Fictional protected section selection');
        $f->bindings()->commit($target->id, $binding['review_token']);
        $before = $s->sql->rows('sis_history_events');
        foreach ([null, '[1]'] as $minimums) {
            $s->sql->update('sis_course_versions', ['prerequisite_minimums' => $minimums], ['id' => $version->value]);
            try {
                $s->service->register($student->id, $target->id);
                self::fail('A weakened course projection authorized registration.');
            } catch (CorruptHistoryException) {
                self::assertSame($before, $s->sql->rows('sis_history_events'));
                self::assertSame([], $s->sql->rows('sis_enrollments', ['section_id' => $target->id->value]));
            }
        }
    }

    public function testMigrationCompilesForSupportedDriversWithoutRewritingFacts(): void
    {
        $s = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method('getColumns')->willReturn(['id']);
        $schema->method('alter')->willReturnCallback(static function (
            string $name,
            callable $callback
        ) use (&$definitions): void {
            $table = new \Qubus\Expressive\Schema\AlterTable($name);
            $callback($table);
            $definitions[] = $table;
        });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $migration = new \AddSisPrerequisiteMinimums('prerequisite-count-compiler');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'fixture')]));
        $before = $s->sql->rows('sis_history_events');
        $migration->up();
        self::assertCount(2, $definitions);
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $compiler = new $class($dialect);
            $sql = json_encode(array_map($compiler->alter(...), $definitions), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('minimums_json', $sql);
            self::assertStringContainsString('prerequisite_minimums', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |UPDATE |INSERT |DELETE /i', $sql);
        }
        $definitions = [];
        $grades = new \AddSisPrerequisiteGradePoints('prerequisite-grades-compiler');
        $grades->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'fixture')]));
        $grades->up();
        self::assertCount(2, $definitions);
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $compiler = new $class($dialect);
            $sql = json_encode(array_map($compiler->alter(...), $definitions), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('grade_points_json', $sql);
            self::assertStringContainsString('prerequisite_grade_points', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |UPDATE |INSERT |DELETE /i', $sql);
        }
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testGroupGradeFloorsAreOptionalExactAndIndependentOfTheRequiredCourseCount(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $rule = new PrerequisiteGroups([[$a, $b], [$a]], [1, 1], [2675, null]);
        self::assertSame(1, $rule->missing([$a], [$a => 2674]));
        self::assertSame(0, $rule->missing([$a], [$a => 2675]));
        self::assertSame(1, $rule->missing([$a]));
        self::assertSame([2675, null], $rule->subset([$a])->minimumGradePointsMilli);
        self::assertSame([2675], $rule->subset([$b])->minimumGradePointsMilli);
        self::assertSame(0, new PrerequisiteGroups([[$a]], null, [0])->missing([$a], [$a => 0]));
        self::assertSame(1, new PrerequisiteGroups([[$a]], null, [0])->missing([$a]));
        foreach ([[], [null, null], [-1], [10001], ['2000'], [2.5], [true]] as $invalid) {
            try {
                new PrerequisiteGroups([[$a]], null, $invalid);
                self::fail('A malformed grade-point floor was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRegistrationUsesTheStrongestVerifiedPassingAttemptAndRetainsItsGradePoints(): void
    {
        $s = new SisFixture();
        foreach ([['P', 2675, true, false], ['L', 2000, true, true], ['F', 4000, false, true]] as $g) {
            $s->legacyGrade(new GradeDefinition(EntityId::generate(), ...$g));
        }
        $student = $s->student();
        $first = $s->section(20, 0);
        $low = $s->section(20, 0);
        $failed = $s->section(20, 0);
        $target = $s->section(20, 0);
        $this->pass($s, $student->id, $first);
        // Repeated attempts use the same course identity and the same pursuit.
        $s->sql->update('sis_sections', ['course_id' => $first->courseId->value], ['id' => $low->id->value]);
        $this->pass($s, $student->id, $s->registration->section($low->id), 'L');
        $this->pass($s, $student->id, $failed, 'F');
        $groups = [[$first->courseId->value, $failed->courseId->value], [$first->courseId->value]];
        $this->save($s, $target->courseId, $groups, [1, 1], [2675, 3000]);
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $review = $s->registrationBatches()->review($selection);
        $checks = $review['students'][0]['prerequisites'];
        self::assertSame([true, false], array_column($checks['groups'], 'met'));
        self::assertSame([1, 0], array_column($checks['groups'], 'completed_count'));
        self::assertSame([2675, 3000], array_column($checks['groups'], 'minimum_grade_points_milli'));
        $this->save($s, $target->courseId, $groups, [1, 1], [2675, null]);
        $enrollment = $s->service->register($student->id, $target->id);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->id->value)));
        $evidence = $events[0]->metaParam('prerequisite_evidence');
        self::assertSame([2675, null], $evidence['requirement_grade_points_milli']);
        self::assertCount(1, $evidence['support']);
        self::assertSame(2675, $evidence['support'][0]['grade_points_milli']);
        self::assertTrue(PrerequisiteDecision::fromRetained($evidence)->isMet());
        foreach (
            [['grade_points_milli' => null], ['grade_points_milli' => '2675'],
            ['grade_points_milli' => 2674]] as $bad
        ) {
            $changed = $evidence;
            $changed['support'][0] = array_replace($changed['support'][0], $bad);
            try {
                PrerequisiteDecision::fromRetained($changed);
                self::fail('Incomplete or insufficient retained grade evidence was accepted.');
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
        $this->save($s, $target->courseId, $groups, [2, 1], [4000, 4000]);
        self::assertSame($enrollment->id->value, $s->service->register($student->id, $target->id)->id->value);
        self::assertSame($evidence, $events[0]->metaParam('prerequisite_evidence'));
    }

    public function testFloorChangesStaleReviewsAndCannotRewriteAPublishedSectionOrItsProtectedGradeRequirement(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 2000, true, false));
        $first = $s->section(20, 0);
        $target = $s->section(20, 0);
        $this->pass($s, $student->id, $first);
        $groups = [[$first->courseId->value]];
        $this->save($s, $target->courseId, $groups, [1], [2000]);
        $old = $f->publications()->review($target->courseId, 'Fictional passing requirement');
        $batch = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($target->id, $student->id);
        $pending = $batch->review($selection);
        $this->save($s, $target->courseId, $groups, [1], [3000]);
        foreach (
            [fn() => $f->publications()->publish($target->courseId, $old['review_token']),
            fn() => $batch->commit(
                new EntityId($pending['operation_id']),
                $selection,
                $pending['revision'],
                $pending['review_token']
            )] as $confirm
        ) {
            try {
                $confirm();
                self::fail('A changed grade requirement reused an earlier confirmation.');
            } catch (ConcurrentModificationException) {
                self::assertSame([], $s->sql->rows('sis_course_versions'));
                self::assertSame([], $s->sql->rows('sis_enrollments', ['section_id' => $target->id->value]));
            }
        }
        $review = $f->publications()->review($target->courseId, 'Fictional reviewed grade minimum');
        $published = $f->publications()->publish($target->courseId, $review['review_token']);
        $version = new EntityId($published['operation_id']);
        $binding = $f->bindings()->review($target->id, $version, 'Fictional selected grading requirement');
        $f->bindings()->commit($target->id, $binding['review_token']);
        $definition = new SqlCourseVersionProjection($s->sql)->get($version)->definition;
        self::assertSame([3000], $definition->describe()['prerequisite_grade_points_milli']);
        self::assertSame($definition->describe(), CourseDefinition::fromArray($definition->describe())->describe());
        $this->save($s, $target->courseId, $groups, [1], [0]);
        self::assertSame([3000], $s->registration->courseForSection($s->registration->section($target->id))
            ->prerequisiteGroups->minimumGradePointsMilli);
        $before = $s->sql->rows('sis_history_events');
        foreach ([null, '[null]', '[0]'] as $floor) {
            $s->sql->update('sis_course_versions', ['prerequisite_grade_points' => $floor], ['id' => $version->value]);
            try {
                $s->service->register($student->id, $target->id);
                self::fail('A weakened grade projection authorized a new registration.');
            } catch (CorruptHistoryException) {
                self::assertSame($before, $s->sql->rows('sis_history_events'));
            }
        }
    }

    public function testGradeCorrectionsInvalidatePendingReviewsAndLeaveAcceptedEvidenceUnchanged(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 2000, true, false));
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'L', 1999, true, false));
        $student = $s->student();
        $first = $s->section(20, 0);
        $target = $s->section(20, 0);
        $other = $s->section(20, 0);
        $prior = $this->pass($s, $student->id, $first);
        foreach ([$target, $other] as $section) {
            $this->save($s, $section->courseId, [[$first->courseId->value]], [1], [2000]);
        }
        $accepted = $s->service->register($student->id, $target->id);
        $selection = new RegistrationBatchSelection($other->id, $student->id);
        $batch = $s->registrationBatches();
        $pending = $batch->review($selection);
        $grade = new EntityId($s->gradeCatalog()->byCode('L', $student->id)['id']);
        $review = $s->academicCredits()->review(
            $student->id,
            $first->id,
            $prior->id,
            $grade,
            1,
            reason: 'Fictional correction below the prerequisite grade requirement'
        );
        $s->academicCredits()->commit($student->id, $first->id, $review['review_token']);
        $before = $s->sql->rows('sis_history_events');
        try {
            $batch->commit(
                new EntityId($pending['operation_id']),
                $selection,
                $pending['revision'],
                $pending['review_token']
            );
            self::fail('A changed recorded grade reused an earlier eligibility review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        self::assertSame('missing', $batch->review($selection)['students'][0]['prerequisites']['status']);
        self::assertSame($accepted->id->value, $s->service->register($student->id, $target->id)->id->value);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($accepted->id->value)));
        $evidence = $events[0]->metaParam('prerequisite_evidence');
        self::assertSame(2000, $evidence['support'][0]['grade_points_milli']);
        self::assertTrue(PrerequisiteDecision::fromRetained($evidence)->isMet());
        $stripped = $evidence;
        unset($stripped['support'][0]['grade_points_milli']);
        try {
            PrerequisiteDecision::fromRetained($stripped);
            self::fail('Retained grade evidence could be omitted.');
        } catch (CorruptHistoryException) {
            self::assertTrue(true);
        }
        foreach ([null, [], ['2000']] as $invalid) {
            $changed = array_replace($evidence, ['requirement_grade_points_milli' => $invalid]);
            try {
                PrerequisiteDecision::fromRetained($changed);
                self::fail('Malformed retained grade minima were accepted.');
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testZeroPointTransferGradesQualifyOnlyWhenThePrerequisiteMinimumAllowsThem(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        $s = new SisFixture();
        $transfer = new \TransferAwardFixture($s, $s->dropCipher);
        $target = $s->section(20, 0);
        $this->save($s, $target->courseId, [[$transfer->course->value]], [1], [2000]);
        $selection = new RegistrationBatchSelection($target->id, $transfer->student);
        self::assertSame('missing', $s->registrationBatches()->review($selection)
            ['students'][0]['prerequisites']['status']);
        $this->save($s, $target->courseId, [[$transfer->course->value]], [1], [0]);
        $accepted = $s->service->register($transfer->student, $target->id);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($accepted->id->value)));
        $evidence = $events[0]->metaParam('prerequisite_evidence');
        self::assertSame(0, $evidence['support'][0]['grade_points_milli']);
        self::assertSame('transfer', $evidence['support'][0]['origin']);
        self::assertTrue(PrerequisiteDecision::fromRetained($evidence)->isMet());
    }

    public function testCloningAndIndividualReferenceEditsPreserveGradeFloors(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section();
        $a = $s->section();
        $b = $s->section();
        $groups = [[$a->courseId->value, $b->courseId->value], [$b->courseId->value]];
        $this->save($s, $target->courseId, $groups, [1, 1], [2675, 0]);
        $review = $f->clones()->review($target->courseId);
        self::assertSame([2675, 0], $review['prerequisite_grade_points_milli']);
        $clone = new Course(
            EntityId::generate(),
            'FICTIONAL.GRADE',
            'Fictional graded prerequisite clone',
            3000,
            false
        );
        $f->clones()->commit($target->courseId, $clone, [$a->courseId], $review['revision']);
        $repository = new SqlCatalogWorkflowRepository($s->sql);
        self::assertSame([2675], $repository->prerequisiteGroups($clone->id)->minimumGradePointsMilli);
        $s->transactions->run(fn() => $s->registration->addPrerequisite($clone->id, $b->courseId));
        self::assertSame([2675, null], $repository->prerequisiteGroups($clone->id)->minimumGradePointsMilli);
        $s->transactions->run(fn() => $s->registration->removePrerequisite($clone->id, $a->courseId));
        self::assertSame([null], $repository->prerequisiteGroups($clone->id)->minimumGradePointsMilli);
    }

    public function testEarlierCourseDefinitionsKeepTheirExactShapeAndIncompleteEvidenceIsRejected(): void
    {
        $id = EntityId::generate()->value;
        $old = new CourseDefinition(
            'EARLIER',
            'Earlier academic definition',
            3000,
            [$id],
            prerequisiteGroups: new PrerequisiteGroups([[$id]])
        )->describe();
        self::assertArrayNotHasKey('prerequisite_group_minimums', $old);
        self::assertSame($old, CourseDefinition::fromArray($old)->describe());
        $new = new CourseDefinition(
            'CURRENT',
            'Current academic definition',
            3000,
            [$id],
            prerequisiteGroups: new PrerequisiteGroups([[$id]], [1])
        )->describe();
        self::assertSame([1], $new['prerequisite_group_minimums']);
        self::assertSame($new, CourseDefinition::fromArray($new)->describe());
        $s = new SisFixture();
        $assignment = $s->workPursuits()->activePursuit($s->student()->id);
        $oldEvidence = ['assignment_id' => $assignment->value, 'required_course_ids' => [], 'support' => [],
            'requirement_groups' => []];
        self::assertSame($oldEvidence, PrerequisiteDecision::fromRetained($oldEvidence)->retained());
        foreach ([['requirement_minimums' => [1]], ['requirement_minimums' => null]] as $bad) {
            try {
                PrerequisiteDecision::fromRetained($oldEvidence + $bad);
                self::fail('Unmatched or null counts were accepted as historical evidence.');
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }
}
