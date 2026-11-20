<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Registration\ValueObject\SectionChangeSelection;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCoRequisiteRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CoRequisiteWorkflowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }
    private function requireCourse(SisFixture $s, EntityId $course, EntityId $required): void
    {
        $editor = new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogWorkflowRepository($s->sql),
            $s->access,
            $s->transactions,
            $s->audit
        );
        $editor->save($course, [], $editor->current($course)['revision'], corequisites: [$required->value]);
    }
    private function pair(): array
    {
        $s = new SisFixture();
        $student = $s->student();
        $lecture = $s->section(20, 1000);
        $lab = $s->section(20, 500);
        $this->requireCourse($s, $lecture->courseId, $lab->courseId);
        return [$s, $student->id, $lecture, $lab];
    }
    private function plan(SisFixture $s, EntityId $student, EntityId ...$sections): array
    {
        $selection = new RegistrationPlanSelection($student, ...$sections);
        $review = $s->registrationBatches()->reviewPlan($selection, true);
        self::assertTrue($review['can_confirm']);
        return $s->registrationBatches()->commitPlan(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        );
    }
    private function events(SisFixture $s, EntityId $enrollment): array
    {
        return iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->value)));
    }
    public function testMissingCoRequisiteBlocksStaffAndOwnerWithoutSavingAnything(): void
    {
        [$s, $student, $lecture] = $this->pair();
        foreach ([true, false] as $staff) {
            if (!$staff) {
                $person = $s->admissions->student($student)->personId;
                $s->access->actor = $s->admissions->person($person)->ownerUserId;
                $s->access->staff = false;
            }
            try {
                $s->service->register($student, $lecture->id, $staff);
                self::fail('An unsupported co-requisite was registered.');
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows('sis_enrollments'));
                self::assertSame([], $s->sql->rows('sis_academic_credits'));
            }
        }
    }
    public function testMutualCoRequisitesRegisterInOneAtomicScheduleWithRealLinkedIdentities(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $this->requireCourse($s, $lab->courseId, $lecture->courseId);
        $selection = new RegistrationPlanSelection($student, $lecture->id, $lab->id);
        $preview = $s->registrationBatches()->reviewPlan($selection, true);
        self::assertTrue($preview['can_confirm']);
        foreach ($preview['sections'] as $row) {
            self::assertSame(['planned'], $row['student']['corequisites']['courses']);
        }
        self::assertStringNotContainsString('academic_version', json_encode($preview, JSON_THROW_ON_ERROR));
        $result = $s->registrationBatches()->commitPlan(
            new EntityId($preview['operation_id']),
            $selection,
            $preview['revision'],
            $preview['review_token'],
            true
        );
        self::assertSame(2, $result['created']);
        foreach ($result['registrations'] as $row) {
            $proof = $this->events($s, new EntityId($row['enrollment_id']))[0]->metaParam('corequisite_evidence');
            $support = $proof['support'][0];
            self::assertContains($support['source_id'], array_column($result['registrations'], 'enrollment_id'));
            self::assertNotSame($row['enrollment_id'], $support['source_id']);
            self::assertContains($support['kind'], ['coordinated', 'concurrent']);
        }
        self::assertSame(1500, array_sum(array_column($s->sql->rows('sis_account_balances'), 'balance_minor')));
        self::assertSame($result, $s->registrationBatches()->commitPlan(
            new EntityId($preview['operation_id']),
            $selection,
            $preview['revision'],
            $preview['review_token'],
            true
        ));
    }
    public function testCurrentLabSupportsLectureAndStandaloneDropIsBlockedButJointDropSucceeds(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $labEnrollment = $s->service->register($student, $lab->id, true);
        $lectureEnrollment = $s->service->register($student, $lecture->id, true);
        self::assertSame('concurrent', $this->events($s, $lectureEnrollment->id)[0]
            ->metaParam('corequisite_evidence')['support'][0]['kind']);
        // Removing the draft requirement must not erase the accepted dependency.
        (new SqlCatalogWorkflowRepository($s->sql))->savePrerequisiteGroups(
            $lecture->courseId,
            new \Domain\Catalog\ValueObject\PrerequisiteGroups([])
        );
        $review = $s->drops->review($student, $lab->id);
        self::assertTrue($review['corequisites']['blocked']);
        self::assertSame([$lecture->id->value], $review['corequisites']['required_sections']);
        try {
            $s->drops->drop($student, $lab->id);
            self::fail('A required lab was removed on its own.');
        } catch (ValidationException) {
            self::assertSame($labEnrollment->id->value, $s->registration->enrollment($student, $lab->id)->id->value);
        }
        $selection = new RegistrationPlanSelection($student, $lecture->id, $lab->id);
        $plan = $s->drops->reviewPlan($selection, true);
        $receipt = $s->drops->commitPlan(
            $selection,
            new EntityId($plan['operation_id']),
            $plan['revision'],
            $plan['review_token'],
            true
        );
        self::assertSame(2, $receipt['removed']);
        self::assertSame([], $s->sql->rows('sis_enrollments'));
        self::assertCount(2, $this->events($s, $labEnrollment->id));
    }
    public function testInstitutionCanWarnAndRetainsUnsupportedDropDecision(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $this->plan($s, $student, $lecture->id, $lab->id);
        (new SqlCoRequisiteRepository($s->sql))->savePolicy(true, 'warn', 0);
        $review = $s->drops->review($student, $lab->id);
        self::assertFalse($review['corequisites']['blocked']);
        self::assertTrue($review['corequisites']['warning']);
        $id = new EntityId($review['enrollment_id']);
        $s->drops->drop($student, $lab->id);
        $events = $this->events($s, $id);
        $facts = $events[1]->metaParam('corequisite_drop_evidence');
        self::assertSame('warn', $facts['policy']['drop_mode']);
        self::assertCount(1, $facts['checks']);
        self::assertSame([], $facts['checks'][0]['assessment']['support']);
    }
    public function testCompletedLabDoesNotCreateAnotherRegistrationOrChargeAndStrictPolicyRejectsOlderTerm(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 2000, true, true));
        $enrollment = $s->service->register($student, $lab->id, true);
        $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
        $review = $s->academicCredits()->review(
            $student,
            $lab->id,
            $enrollment->id,
            $grade,
            0,
            reason: 'Fictional completed laboratory'
        );
        $s->academicCredits()->commit($student, $lab->id, $review['review_token']);
        // A different receiving term uses the same course definitions.
        $termRow = $s->sql->one('sis_terms', ['id' => $s->term->value]);
        $termRow['id'] = EntityId::generate()->value;
        $termRow['code'] = 'NEXT';
        $s->sql->insert('sis_terms', $termRow);
        $window = $s->sql->one('sis_reg_windows', ['term_id' => $s->term->value]);
        $window['id'] = EntityId::generate()->value;
        $window['term_id'] = $termRow['id'];
        $s->sql->insert('sis_reg_windows', $window);
        $s->sql->update('sis_sections', ['term_id' => $termRow['id']], ['id' => $lecture->id->value]);
        (new SqlCoRequisiteRepository($s->sql))->savePolicy(false, 'block', 0);
        try {
            $s->service->register($student, $lecture->id, true);
            self::fail('Older completion satisfied a same-term-only policy.');
        } catch (ValidationException) {
            self::assertCount(1, $s->sql->rows('sis_enrollments'));
        }
        (new SqlCoRequisiteRepository($s->sql))->savePolicy(true, 'block', 1);
        $accepted = $s->service->register($student, $lecture->id, true);
        self::assertSame('completed', $this->events($s, $accepted->id)[0]
            ->metaParam('corequisite_evidence')['support'][0]['kind']);
        self::assertCount(2, $s->sql->rows('sis_enrollments'));
        self::assertSame(1500, array_sum(array_column($s->sql->rows('sis_account_balances'), 'balance_minor')));
    }
    public function testPolicyChangeAfterReviewInvalidatesTheWholePlan(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $selection = new RegistrationPlanSelection($student, $lecture->id, $lab->id);
        $preview = $s->registrationBatches()->reviewPlan($selection, true);
        (new SqlCoRequisiteRepository($s->sql))->savePolicy(false, 'block', 0);
        try {
            $s->registrationBatches()->commitPlan(
                new EntityId($preview['operation_id']),
                $selection,
                $preview['revision'],
                $preview['review_token'],
                true
            );
            self::fail('A stale co-requisite review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows('sis_enrollments'));
        }
    }
    public function testMissingSupportingProjectionCannotHideALiveDependency(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $this->plan($s, $student, $lecture->id, $lab->id);
        $dependent = $s->registration->enrollment($student, $lecture->id);
        $s->sql->execute('DELETE FROM sis_enrollments WHERE id = ?', [$dependent->id->value]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $s->drops->review($student, $lab->id);
    }
    public function testReplacingASupportingLabSectionKeepsTheLectureSupported(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $this->plan($s, $student, $lecture->id, $lab->id);
        $row = $s->sql->one('sis_sections', ['id' => $lab->id->value]);
        $row['id'] = EntityId::generate()->value;
        $row['code'] .= '.B';
        $s->sql->insert('sis_sections', $row);
        $selection = new SectionChangeSelection($student, $lab->id, new EntityId($row['id']));
        $service = $s->sectionChanges();
        $review = $service->review($selection, true);
        self::assertTrue($review['can_confirm']);
        $result = $service->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame($row['id'], $result['to_section_id']);
        self::assertNotNull($s->registration->enrollment($student, $lecture->id));
        self::assertNull($s->registration->enrollment($student, $lab->id));
    }
    public function testFinalPolicyChangeRollsBackEveryCoordinatedRegistration(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $selection = new RegistrationPlanSelection($student, $lecture->id, $lab->id);
        $audit = new class ($s) implements \Domain\Audit\Repository\AuditTrail {
            public function __construct(private SisFixture $sis)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if ($action === 'registration.plan_completed') {
                    (new SqlCoRequisiteRepository($this->sis->sql))->savePolicy(false, 'block', 0);
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = $s->registrationBatches($audit);
        $preview = $service->reviewPlan($selection, true);
        $before = $s->sql->rows('sis_history_events');
        try {
            $service->commitPlan(
                new EntityId($preview['operation_id']),
                $selection,
                $preview['revision'],
                $preview['review_token'],
                true
            );
            self::fail('A final policy change left partial registrations.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $s->sql->rows('sis_enrollments'));
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertSame(0, (new SqlCoRequisiteRepository($s->sql))->policy()['revision']);
        }
    }
    public function testTenMutualCoRequisitesUseOneBoundedScheduleAssessment(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $s->sql->update('sis_reg_windows', ['course_limit' => 12], ['term_id' => $s->term->value]);
        $sections = [];
        for ($i = 0; $i < 10; $i++) {
            $sections[] = $s->section(20, 0);
        }
        foreach ($sections as $i => $section) {
            $this->requireCourse($s, $section->courseId, $sections[($i + 1) % 10]->courseId);
        }
        self::assertSame(10, $this->plan($s, $student, ...array_map(
            static fn($section): EntityId => $section->id,
            $sections,
        ))['created']);
    }
    public function testMigrationCompilesForEveryFrameworkDatabaseWithoutRewritingFacts(): void
    {
        $s = new SisFixture();
        $tables = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method('getColumns')->willReturn(['id']);
        $schema->method('alter')->willReturnCallback(static function (
            string $name,
            callable $callback,
        ) use (&$tables): void {
            $table = new \Qubus\Expressive\Schema\AlterTable($name);
            $callback($table);
            $tables[] = $table;
        });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $migration = new \AddSisCorequisites('corequisite-compiler');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'fixture')]));
        $before = $s->sql->rows('sis_history_events');
        $migration->up();
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"',
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $compiler = new $class($dialect);
            $sql = json_encode(array_map($compiler->alter(...), $tables), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('corequisites', $sql);
            self::assertStringContainsString('coreq_revision', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |UPDATE |INSERT |DELETE /i', $sql);
        }
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }
    public function testEarlierPursuitCompletionRequiresExplicitReuseForTheNewPursuit(): void
    {
        [$s, $student, $lecture, $lab] = $this->pair();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 2000, true, true));
        $enrollment = $s->service->register($student, $lab->id, true);
        $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
        $review = $s->academicCredits()->review(
            $student,
            $lab->id,
            $enrollment->id,
            $grade,
            0,
            reason: 'Fictional completed lab in the earlier pursuit'
        );
        $s->academicCredits()->commit($student, $lab->id, $review['review_token']);
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', [
            'id' => $program->value, 'code' => 'NEXT', 'title' => 'Fictional new program',
        ]);
        $r = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$r->id->value, $r->personId->value,
            $r->programId->value, $r->entryTermId->value, $r->active], JSON_THROW_ON_ERROR));
        $change = $s->programAssignmentService()->reviewChange(
            $student,
            $program,
            $s->term,
            $revision,
            'Fictional program change.'
        );
        $changed = $s->programAssignmentService()->changeProgram($student, $change['review_token']);
        try {
            $s->service->register($student, $lecture->id, true);
            self::fail('A prior pursuit automatically satisfied the new program requirement.');
        } catch (ValidationException) {
            self::assertNull($s->registration->enrollment($student, $lecture->id));
        }
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $reuse = $s->workPursuits()->review(
            $student,
            $credit,
            new EntityId($changed['assignment_id']),
            'approve_reuse',
            'Fictional registrar-approved reuse.'
        );
        $s->workPursuits()->commit($student, $credit, $reuse['review_token']);
        $accepted = $s->service->register($student, $lecture->id, true);
        self::assertSame($changed['assignment_id'], $this->events($s, $accepted->id)[0]
            ->metaParam('corequisite_evidence')['assignment_id']);
    }
    public function testCourseCloneKeepsCoRequisitesSeparateFromCompletedCoursePrerequisites(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        $fixture = new \CatalogWorkflowFixture();
        $s = $fixture->sis;
        $lecture = $s->section(20, 0);
        $lab = $s->section(20, 0);
        $this->requireCourse($s, $lecture->courseId, $lab->courseId);
        $review = $fixture->clones()->review($lecture->courseId);
        $draft = new \Domain\Catalog\Course(EntityId::generate(), 'CLONE', 'Fictional cloned lecture', 3000, false);
        $fixture->clones()->commit($lecture->courseId, $draft, [$lab->courseId], $review['revision']);
        $requirements = $fixture->operations->prerequisiteGroups($draft->id);
        self::assertSame([], $requirements->courses());
        self::assertSame([$lab->courseId->value], $requirements->corequisites);
        self::assertSame([], $s->registration->requiredCourses($draft->id));
    }
    public function testHiatusClosuresUseTheWholeSelectedSetForCoRequisiteDependencies(): void
    {
        require_once dirname(__DIR__) . '/Support/HiatusFixture.php';
        $fixture = new \HiatusFixture();
        $s = $fixture->sis;
        $student = $s->student()->id;
        $lecture = $s->section(20, 0);
        $lab = $s->section(20, 0);
        $this->requireCourse($s, $lecture->courseId, $lab->courseId);
        $this->plan($s, $student, $lecture->id, $lab->id);
        $dates = new \Domain\Shared\ValueObject\DateRange(
            new \DateTimeImmutable($s->clock->now()->format('Y-m-d')),
        );
        $service = $fixture->workflow();
        $review = $service->review($student, \Domain\Student\Enum\HiatusCode::Withdrawal, $dates);
        $hiatus = new \Domain\Student\StudentHiatus(
            new EntityId($review['operation_id']),
            $student,
            \Domain\Student\Enum\HiatusCode::Withdrawal,
            $dates
        );
        try {
            $service->commit(
                $hiatus,
                'Fictional partial closure',
                new \Domain\Student\ValueObject\HiatusImpact(false, $review['revision'], [], $lab->id),
                $review['review_token']
            );
            self::fail('A partial hiatus closure left its co-requisite unsupported.');
        } catch (ValidationException) {
            self::assertCount(2, $s->sql->rows('sis_enrollments'));
        }
        $result = $service->commit(
            $hiatus,
            'Fictional combined closure',
            new \Domain\Student\ValueObject\HiatusImpact(true, $review['revision'], [], $lecture->id, $lab->id),
            $review['review_token']
        );
        self::assertCount(2, $result['impact']['course_changes']);
        self::assertFalse($s->admissions->student($student)->active);
    }
}
