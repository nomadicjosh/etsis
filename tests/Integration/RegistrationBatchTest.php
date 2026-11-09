<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/SisFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\ValueObject\AccountId;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\RegistrationBatchService;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository;
use PHPUnit\Framework\TestCase;

final class RegistrationBatchTest extends TestCase
{
    private function service(
        SisFixture $f,
        ?AuditTrail $audit = null,
        ?RegistrationRepository $repository = null,
        ?\Domain\Registration\Repository\RegistrationBatchRepository $batches = null
    ): RegistrationBatchService {
        return new RegistrationBatchService(
            $batches ?? new SqlRegistrationBatchRepository($f->sql),
            $repository ?? $f->registration,
            $f->admissions,
            $f->service,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock
        );
    }

    public function testReviewedBatchKeepsExistingEnrollmentsAndChargesEachNewStudentOnlyOnce(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section(20, 1250);
        $existing = $f->service->register($a->id, $section->id);
        $selection = new RegistrationBatchSelection($section->id, ...[$b->id, $a->id]);
        $service = $this->service($f);
        $review = $service->review($selection);
        self::assertCount(2, $review['students']);
        self::assertSame(19, $review['section']['seats_remaining']);
        self::assertSame(1250, $review['section']['fee_minor']);
        $operation = new EntityId($review['operation_id']);
        $receipt = $service->commit($operation, $selection, $review['revision']);
        self::assertSame(1, $receipt['created']);
        self::assertSame(1, $receipt['existing']);
        self::assertSame($existing->id->value, $f->registration->enrollment($a->id, $section->id)->id->value);
        self::assertSame('N', $f->registration->enrollment($a->id, $section->id)->status->value);
        self::assertSame('A', $f->registration->enrollment($b->id, $section->id)->status->value);
        self::assertCount(2, $f->sql->rows('sis_academic_credits'));
        foreach ([$a, $b] as $student) {
            $account = $f->sql->one('sis_student_accounts', ['student_id' => $student->id->value]);
            self::assertSame(1250, $f->accounts->get(new AccountId($account['id']))->balance()->minor);
        }
        $events = $f->sql->rows('sis_history_events');
        $audit = $f->sql->rows('sis_audit');
        self::assertSame($receipt, $service->commit(
            $operation,
            new RegistrationBatchSelection($section->id, ...[$a->id, $b->id]),
            $review['revision']
        ));
        self::assertSame($receipt, $service->receipt($operation));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($audit, $f->sql->rows('sis_audit'));
        $stored = $f->sql->one('sis_registration_batches', ['id' => $operation->value]);
        self::assertStringNotContainsString('Example', $stored['result_json']);
        self::assertStringNotContainsString($review['students'][0]['institutional_id'], $stored['result_json']);
    }

    public function testCapacityFailureRollsBackEarlierStudentsCreditsChargesAuditsAndReceipt(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section(1, 1250);
        $service = $this->service($f);
        $selection = new RegistrationBatchSelection($section->id, ...[$a->id, $b->id]);
        $review = $service->review($selection);
        $audit = $f->sql->rows('sis_audit');
        try {
            $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('An over-capacity batch partially committed.');
        } catch (ValidationException $error) {
            self::assertStringContainsString('No changes in this batch were saved', $error->errors[0]['message']);
            foreach (['sis_enrollments', 'sis_academic_credits', 'sis_student_accounts', 'sis_history_events', 'sis_registration_batches'] as $table) {
                self::assertSame([], $f->sql->rows($table));
            }
            self::assertSame($audit, $f->sql->rows('sis_audit'));
        }
    }

    public function testNewHoldAfterReviewIsRecheckedAndPreventsAnyBatchCommit(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, ...[$a->id, $b->id]);
        $service = $this->service($f);
        $review = $service->review($selection);
        $student = $f->admissions->student($selection->students[1]);
        $f->sql->insert('sis_restrictions', ['id' => EntityId::generate()->value, 'person_id' => $student->personId->value,
            'code' => 'ADVISING', 'severity' => 99, 'starts_on' => '2026-08-01', 'ends_on' => null, 'blocks_staff' => 1]);
        try {
            $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('A preceding staff-blocking restriction was ignored.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_enrollments'));
            self::assertSame([], $f->sql->rows('sis_history_events'));
            self::assertSame([], $f->sql->rows('sis_registration_batches'));
        }
    }

    public function testInactiveAndPrerequisiteFailuresCannotBeBypassedByBatchRegistration(): void
    {
        foreach (['activity', 'prerequisite', 'course-limit'] as $policy) {
            $f = new SisFixture();
            $student = $f->student();
            $section = $f->section();
            if ($policy === 'activity') {
                $f->sql->update('sis_students', ['active' => 0], ['id' => $student->id->value]);
            } elseif ($policy === 'prerequisite') {
                $required = $f->section();
                $f->registration->addPrerequisite($section->courseId, $required->courseId);
            } else {
                $other = $f->section(20, 0);
                $f->service->register($student->id, $other->id);
                $f->registration->configureWindow($f->term, new \Domain\Registration\RegistrationWindow(
                    new \Domain\Shared\ValueObject\DateRange(
                        new DateTimeImmutable('2026-08-01'),
                        new DateTimeImmutable('2026-08-31')
                    ),
                    1
                ));
            }
            $before = $f->sql->rows('sis_enrollments');
            $events = $f->sql->rows('sis_history_events');
            $service = $this->service($f);
            $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
            $review = $service->review($selection);
            try {
                $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
                self::fail('Batch ignored ' . $policy);
            } catch (ValidationException) {
                self::assertSame($before, $f->sql->rows('sis_enrollments'));
                self::assertSame($events, $f->sql->rows('sis_history_events'));
                self::assertSame([], $f->sql->rows('sis_registration_batches'));
            }
        }
    }

    public function testReviewedNamesIdsFeesAndSeatChangesRequireANewReview(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $other = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
        $service = $this->service($f);
        foreach (['name', 'identifier', 'fee', 'course', 'seats'] as $change) {
            $review = $service->review($selection);
            match ($change) {
                'name' => $f->sql->update('sis_persons', ['given_name' => 'Updated'], ['id' => $student->personId->value]),
                'identifier' => $f->sql->update('sis_person_identifiers', ['institutional_id' => '00001234'], ['person_id' => $student->personId->value]),
                'fee' => $f->sql->update('sis_sections', ['fee_minor' => 1200], ['id' => $section->id->value]),
                'course' => $f->sql->update('sis_courses', ['title' => 'Revised title'], ['id' => $section->courseId->value]),
                'seats' => $f->service->register($other->id, $section->id, true),
            };
            $events = $f->sql->rows('sis_history_events');
            try {
                $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
                self::fail('A stale review survived a ' . $change . ' change.');
            } catch (ConcurrentModificationException) {
                self::assertNull($f->registration->enrollment($student->id, $section->id));
                self::assertSame($events, $f->sql->rows('sis_history_events'));
            }
        }
    }

    public function testReceiptsRequireTheSameActorAndCurrentRegistrationPermission(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $service = $this->service($f);
        $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
        $review = $service->review($selection);
        $op = new EntityId($review['operation_id']);
        $owner = $f->access->actor;
        $service->commit($op, $selection, $review['revision']);
        $f->access->actor = $f->user();
        foreach (['receipt', 'commit'] as $action) {
            try {
                $action === 'receipt' ? $service->receipt($op) : $service->commit($op, $selection, $review['revision']);
                self::fail('Another registrar read or replayed a private receipt.');
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_registration_batches'));
            }
        }
        $f->access->actor = $owner;
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read'];
        foreach (['receipt', 'review', 'commit'] as $action) {
            try {
                match ($action) {
                    'receipt' => $service->receipt($op), 'review' => $service->review($selection),
                    'commit' => $service->commit($op, $selection, $review['revision']),
                };
                self::fail('Student-read alone granted batch registration.');
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_enrollments'));
            }
        }
    }

    public function testReceiptReplayAfterDropDoesNotResurrectOrRechargeAnEnrollment(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
        $service = $this->service($f);
        $review = $service->review($selection);
        $op = new EntityId($review['operation_id']);
        $receipt = $service->commit($op, $selection, $review['revision']);
        $f->service->drop($student->id, $section->id);
        $events = $f->sql->rows('sis_history_events');
        self::assertSame($receipt, $service->commit($op, $selection, $review['revision']));
        self::assertNull($f->registration->enrollment($student->id, $section->id));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        $this->expectException(ConcurrentModificationException::class);
        $service->commit($op, $selection, str_repeat('0', 64));
    }

    public function testAuditFailureRollsBackTheReceiptAndFinancialEvents(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
        $service = $this->service($f);
        $review = $service->review($selection);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        try {
            $this->service($f, $audit)->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('An unaudited batch committed.');
        } catch (RuntimeException) {
            foreach (['sis_enrollments', 'sis_academic_credits', 'sis_student_accounts', 'sis_history_events', 'sis_registration_batches'] as $table) {
                self::assertSame([], $f->sql->rows($table));
            }
        }
    }

    public function testEveryBatchEligibilityLockPrecedesConsistentReads(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, ...[$b->id, $a->id]);
        $calls = [];
        $repository = $this->createStub(RegistrationRepository::class);
        foreach (['lockStudent', 'lockPerson', 'lockSection', 'lockCourse', 'section', 'courseForSection', 'enrollment', 'sectionCount'] as $method) {
            $repository->method($method)->willReturnCallback(static function (...$args) use ($f, $method, &$calls) {
                $calls[] = $method . ':' . ($method === 'courseForSection' ? $args[0]->id->value : $args[0]->value);
                return $f->registration->$method(...$args);
            });
        }
        $review = $this->service($f, repository: $repository)->review($selection);
        self::assertCount(2, $review['students']);
        $students = array_map(static fn (EntityId $id) => 'lockStudent:' . $id->value, $selection->students);
        $people = [$a->personId->value, $b->personId->value];
        sort($people);
        self::assertSame(['section:' . $section->id->value, ...$students,
            ...array_map(static fn (string $id) => 'lockPerson:' . $id, $people),
            'lockSection:' . $section->id->value, 'lockCourse:' . $section->courseId->value], array_slice($calls, 0, 7));
        self::assertSame('section:' . $section->id->value, $calls[7]);
    }

    public function testConfiguredTermFeesRemainEventSourcedAndApplyOnlyOnceAcrossBatches(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $rules = new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
            $f->sql,
            EventStoreFixture::framework($f->connection->queryBuilder()),
            $f->transactions
        );
        $rules->save(\Domain\Finance\BillingRule::define(
            new \Domain\Finance\ValueObject\BillingRuleId(),
            $f->term,
            null,
            'Fictional term fee',
            'fee',
            new \Domain\Finance\ValueObject\Money(500, 'USD'),
            $f->access->actor
        ));
        $service = $this->service($f);
        foreach ([[$a->id, $b->id], [$a->id]] as $students) {
            $section = $f->section(20, 1250);
            $selection = new RegistrationBatchSelection($section->id, ...$students);
            $review = $service->review($selection);
            $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
        }
        foreach ([[$a, 3000], [$b, 1750]] as [$student, $expected]) {
            $account = $f->sql->one('sis_student_accounts', ['student_id' => $student->id->value]);
            self::assertSame($expected, $f->accounts->get(new AccountId($account['id']))->balance()->minor);
        }
        self::assertCount(2, $f->sql->rows('sis_registration_batches'));
    }

    public function testFinancialFailureOnALaterStudentRollsBackTheEntireBatch(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, ...[$a->id, $b->id]);
        $last = $selection->students[1];
        $account = \Domain\Finance\StudentAccount::open(new AccountId(), $last, $f->term, 'EUR');
        $f->accounts->save($account);
        $before = $f->sql->rows('sis_history_events');
        $service = $this->service($f);
        $review = $service->review($selection);
        try {
            $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('A later currency mismatch left earlier registrations committed.');
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows('sis_enrollments'));
            self::assertSame([], $f->sql->rows('sis_academic_credits'));
            self::assertSame([], $f->sql->rows('sis_registration_batches'));
            self::assertCount(1, $f->sql->rows('sis_student_accounts'));
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }

    public function testRegistrationLookupDoesNotRequireOrGrantFullStudentCatalogOrFinanceAccess(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(20, 0);
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['admin:dashboard', 'sis:registration:manage'];
        $permissions = $this->createStub(\Domain\Access\Repository\AccessRepository::class);
        $permissions->method('allows')->willReturnCallback(static fn (string $actor, string $permission): bool =>
            in_array($permission, $f->access->grants, true));
        $workspace = new \Domain\Workspace\Service\WorkspaceService(
            new \Infrastructure\Persistence\Repository\SqlWorkspaceRepository($f->sql, $f->gradeCatalog()),
            $f->access,
            $permissions,
            new \Domain\Admissions\Service\AdmissionsService(
                $f->admissions,
                $f->access,
                $f->transactions,
                $f->audit,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
                $f->catalogEnrollment()
            )
        );
        self::assertCount(1, $workspace->records('registration')['items']);
        $row = $workspace->records('registrationstudents')['items'][0];
        self::assertSame($student->id->value, $row['id']);
        self::assertArrayNotHasKey('primary_program_id', $row);
        self::assertArrayNotHasKey('entry_term_id', $row);
        foreach (['students', 'courses', 'accounts', 'employees', 'transcripts'] as $dataset) {
            try {
                $workspace->records($dataset);
                self::fail('Registration permission exposed ' . $dataset);
            } catch (AccessDeniedException) {
                self::assertNotContains(
                    \Domain\Workspace\Service\WorkspaceService::DATASETS[$dataset],
                    $workspace->capabilities()
                );
            }
        }
        $service = $this->service($f);
        $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
        $review = $service->review($selection);
        self::assertSame(1, $service->commit(new EntityId($review['operation_id']), $selection, $review['revision'])['created']);
    }

    public function testReceiptMigrationRollbackDoesNotDeleteRegistrationsOrFinancialHistory(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $service = $this->service($f);
        $selection = new RegistrationBatchSelection($section->id, ...[$student->id]);
        $review = $service->review($selection);
        $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
        $enrollments = $f->sql->rows('sis_enrollments');
        $events = $f->sql->rows('sis_history_events');
        $migration = new CreateSisRegistrationBatchesTable('batch');
        $migration->setObjectMap(new ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($f->connection, 'test_migrations')]));
        $migration->down();
        $migration->up();
        self::assertSame([], $f->sql->rows('sis_registration_batches'));
        self::assertSame($enrollments, $f->sql->rows('sis_enrollments'));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testTwentyFiveStudentsCanCompleteOneBoundedBatch(): void
    {
        $f = new SisFixture();
        $students = [];
        for ($i = 0; $i < 25; ++$i) {
            $students[] = $f->student()->id;
        }
        $section = $f->section(25, 0);
        $selection = new RegistrationBatchSelection($section->id, ...$students);
        $service = $this->service($f);
        $review = $service->review($selection);
        $receipt = $service->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
        self::assertSame(25, $receipt['created']);
        self::assertSame(0, $receipt['existing']);
        self::assertCount(25, $f->sql->rows('sis_enrollments'));
        self::assertCount(25, $f->sql->rows('sis_academic_credits'));
        self::assertCount(1, $f->sql->rows('sis_registration_batches'));
    }

    public function testConcurrentReceiptCollisionRollsBackNewRegistrationsBeforeReturningConflict(): void
    {
        $f = new SisFixture();
        $a = $f->student();
        $b = $f->student();
        $section = $f->section();
        $service = $this->service($f);
        $first = new RegistrationBatchSelection($section->id, $a->id);
        $review = $service->review($first);
        $operation = new EntityId($review['operation_id']);
        $original = $service->commit($operation, $first, $review['revision']);
        $events = $f->sql->rows('sis_history_events');
        $second = new RegistrationBatchSelection($section->id, $b->id);
        $fresh = $service->review($second);
        $actual = new SqlRegistrationBatchRepository($f->sql);
        $racing = $this->createStub(\Domain\Registration\Repository\RegistrationBatchRepository::class);
        // Simulate an operation completed by another request after our existence checks.
        $racing->method('receipt')->willReturn(null);
        $racing->method('save')->willReturnCallback($actual->save(...));
        try {
            $this->service($f, batches: $racing)->commit($operation, $second, $fresh['revision']);
            self::fail('A receipt collision left a partially committed second request.');
        } catch (ConcurrentModificationException) {
            self::assertNull($f->registration->enrollment($b->id, $section->id));
            self::assertSame($events, $f->sql->rows('sis_history_events'));
            self::assertSame($original, $service->receipt($operation));
            self::assertCount(1, $f->sql->rows('sis_academic_credits'));
        }
    }

    public function testAContextChangeAtTheLastLockIsObservedBeforeAnyRegistration(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, $student->id);
        $review = $this->service($f)->review($selection);
        $guarded = $this->createStub(RegistrationRepository::class);
        foreach (['lockStudent', 'lockPerson', 'lockSection', 'section', 'courseForSection', 'enrollment', 'sectionCount'] as $method) {
            $guarded->method($method)->willReturnCallback($f->registration->$method(...));
        }
        $guarded->method('lockCourse')->willReturnCallback(static function (EntityId $id) use ($f, $student): void {
            $f->sql->update(
                'sis_person_identifiers',
                ['institutional_id' => 'P-CHANGED'],
                ['person_id' => $student->personId->value]
            );
            $f->registration->lockCourse($id);
        });
        try {
            $this->service($f, repository: $guarded)->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('A context read before the locks survived a preceding writer.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows('sis_enrollments'));
            self::assertSame([], $f->sql->rows('sis_history_events'));
            self::assertSame([], $f->sql->rows('sis_registration_batches'));
        }
    }

    public function testSelectionRejectsEmptyDuplicateAndOversizedGroups(): void
    {
        $section = EntityId::generate();
        $student = EntityId::generate();
        foreach ([[], [$student, $student], array_fill(0, 26, $student)] as $students) {
            try {
                new RegistrationBatchSelection($section, ...$students);
                self::fail('An invalid batch selection was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $selection = new RegistrationBatchSelection($section, ...array_map(static fn () => EntityId::generate(), range(1, 25)));
        self::assertCount(25, $selection->students);
    }
}
