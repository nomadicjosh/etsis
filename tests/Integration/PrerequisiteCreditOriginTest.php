<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Catalog\ValueObject\PrerequisiteGroups;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use PHPUnit\Framework\TestCase;
use SisFixture;
use TransferAwardFixture;

final class PrerequisiteCreditOriginTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
    }

    private function editor(SisFixture $s, ?AuditTrail $audit = null): CoursePrerequisiteService
    {
        return new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogWorkflowRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit
        );
    }

    private function fixture(bool $institutional = true, bool $transfer = true): array
    {
        $context = new CatalogWorkflowFixture();
        $s = $context->sis;
        $student = $s->student()->id;
        $required = $s->section(faculty: $s->access->actor);
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 4000, true, false));
        if ($institutional) {
            $enrollment = $s->service->register($student, $required->id);
            $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
            $review = $s->academicCredits()->review(
                $student,
                $required->id,
                $enrollment->id,
                $grade,
                0,
                reason: 'Fictional completed prerequisite.'
            );
            $s->academicCredits()->commit($student, $required->id, $review['review_token']);
        }
        if ($transfer) {
            new TransferAwardFixture($s, $s->dropCipher, $student, course: $required->courseId);
        }
        $target = $s->section();
        return [$context, $s, $student, $required, $target];
    }

    private function policy(SisFixture $s, EntityId $target, EntityId $required, ?string $origin): array
    {
        $editor = $this->editor($s);
        return $editor->save(
            $target,
            [[$required->value]],
            $editor->current($target)['revision'],
            creditOrigin: $origin
        );
    }

    private function proof(SisFixture $s, EntityId $enrollment): array
    {
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->value)));
        return $events[0]->metaParam('prerequisite_evidence');
    }

    public function testEachConfiguredOriginSelectsActualQualifyingEvidenceAndRetainsItExactly(): void
    {
        foreach (['institutional', 'transfer', 'either', null] as $origin) {
            [, $s, $student, $required, $target] = $this->fixture();
            $this->policy($s, $target->courseId, $required->courseId, $origin);
            $review = $s->registrationBatches()->review(new RegistrationBatchSelection($target->id, $student));
            $summary = $review['students'][0]['prerequisites'];
            self::assertSame('met', $summary['status']);
            self::assertSame($origin, $summary['group_policy']['credit_origin'] ?? null);
            self::assertStringNotContainsString('credit_id', json_encode($summary, JSON_THROW_ON_ERROR));
            $enrollment = $s->service->register($student, $target->id);
            $proof = $this->proof($s, $enrollment->id);
            self::assertSame($origin, $proof['credit_origin'] ?? null);
            if (in_array($origin, ['institutional', 'transfer'], true)) {
                self::assertSame($origin, $proof['support'][0]['origin']);
            }
            self::assertSame(
                \Infrastructure\EventSourcing\ProtectedEventCodec::canonical($proof),
                \Infrastructure\EventSourcing\ProtectedEventCodec::canonical(
                    PrerequisiteDecision::fromRetained($proof)->retained()
                )
            );
            $before = $s->sql->rows('sis_history_events');
            $newOrigin = $origin === 'transfer' ? 'institutional' : 'transfer';
            $this->policy($s, $target->courseId, $required->courseId, $newOrigin);
            $retried = $s->service->register($student, $target->id);
            self::assertSame($enrollment->id->value, $retried->id->value);
            self::assertSame($proof, $this->proof($s, $enrollment->id));
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
    }

    public function testDisallowedOriginBlocksStudentStaffBatchAndScheduleWithoutChangingAcademicOrAccountFacts(): void
    {
        foreach (['institutional', 'transfer'] as $allowed) {
            $institutional = $allowed === 'transfer';
            [, $s, $student, $required, $target] = $this->fixture($institutional, !$institutional);
            $this->policy($s, $target->courseId, $required->courseId, $allowed);
            $selection = new RegistrationBatchSelection($target->id, $student);
            foreach ([false, true] as $staff) {
                $review = $s->registrationBatches()->review($selection, $staff);
                self::assertSame('missing', $review['students'][0]['prerequisites']['status']);
                self::assertSame($allowed, $review['students'][0]['prerequisites']['group_policy']['credit_origin']);
            }
            $facts = array_map($s->sql->rows(...), ['sis_enrollments', 'sis_academic_credits', 'sis_history_events']);
            try {
                $s->service->register($student, $target->id);
                self::fail('Disallowed-source prerequisite work granted registration.');
            } catch (\DomainException) {
                self::assertSame($facts, array_map($s->sql->rows(...), [
                    'sis_enrollments', 'sis_academic_credits', 'sis_history_events',
                ]));
            }
            self::assertFalse($s->prerequisitesMet($student, $target->courseId));
            $plan = new \Domain\Registration\ValueObject\RegistrationPlanSelection(
                $student,
                $target->id,
                $s->section()->id
            );
            $schedule = $s->registrationBatches()->reviewPlan($plan, true);
            self::assertSame('missing', $schedule['sections'][0]['student']['prerequisites']['status']);

        }
    }

    public function testChangingOnlyTheOriginInvalidatesAnUnconfirmedRegistrationReview(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $this->policy($s, $target->courseId, $required->courseId, 'either');
        $selection = new RegistrationBatchSelection($target->id, $student);
        $service = $s->registrationBatches();
        $review = $service->review($selection);
        $this->policy($s, $target->courseId, $required->courseId, 'transfer');
        $this->expectException(ConcurrentModificationException::class);
        $service->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token']
        );
    }

    public function testPublicationProjectionRetainsOriginAndDetectsTamperingWithoutRelabelingEarlierVersions(): void
    {
        [, $s, , $required, $target] = $this->fixture();
        $earlier = $s->publishCourse($target->courseId);
        $this->policy($s, $target->courseId, $required->courseId, 'transfer');
        $version = $s->publishCourse($target->courseId);
        $versions = new SqlCourseVersionProjection($s->sql);
        $versions->retain($s->coursePublications()->publishedDefinition($target->courseId, $earlier));
        $versions->retain($s->coursePublications()->publishedDefinition($target->courseId, $version));
        self::assertArrayNotHasKey('prerequisite_credit_origin', $versions->get($earlier)->definition->describe());
        self::assertSame('transfer', $versions->get($version)->definition->prerequisiteGroups->creditOrigin);
        $events = $s->sql->rows('sis_history_events');
        $s->sql->update(
            'sis_course_versions',
            ['prerequisite_credit_origin' => 'institutional'],
            ['id' => $version->value]
        );
        try {
            $versions->retain($s->coursePublications()->publishedDefinition($target->courseId, $version));
            self::fail('A changed origin projection was accepted as its protected publication.');
        } catch (CorruptHistoryException) {
            self::assertSame($events, $s->sql->rows('sis_history_events'));
        }
    }

    public function testCloneAndRelationshipEditsKeepOriginAndExplicitFullRemovalClearsIt(): void
    {
        [$context, $s, , $required, $target] = $this->fixture();
        $this->policy($s, $target->courseId, $required->courseId, 'institutional');
        $review = $context->clones()->review($target->courseId);
        self::assertSame('institutional', $review['prerequisite_credit_origin']);
        $clone = new Course(EntityId::generate(), 'FICTIONAL.ORIGIN', 'Fictional origin clone', 3000, false);
        $context->clones()->commit($target->courseId, $clone, [$required->courseId], $review['revision']);
        $repository = new SqlCatalogWorkflowRepository($s->sql);
        self::assertSame('institutional', $repository->prerequisiteGroups($clone->id)->creditOrigin);
        $other = $s->section();
        $s->registration->addPrerequisite($clone->id, $other->courseId);
        self::assertSame('institutional', $repository->prerequisiteGroups($clone->id)->creditOrigin);
        $s->registration->removePrerequisite($clone->id, $required->courseId);
        self::assertSame('institutional', $repository->prerequisiteGroups($clone->id)->creditOrigin);
        $s->registration->removePrerequisite($clone->id, $other->courseId);
        self::assertNull($repository->prerequisiteGroups($clone->id)->creditOrigin);
    }

    public function testRecordedEvidenceRejectsForeignOriginsAndMalformedOrOrphanPolicies(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $this->policy($s, $target->courseId, $required->courseId, 'transfer');
        $enrollment = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $enrollment->id);
        foreach (['institutional', 'other', null, []] as $origin) {
            $corrupt = $proof;
            $corrupt['credit_origin'] = $origin;
            try {
                PrerequisiteDecision::fromRetained($corrupt);
                self::fail('Conflicting or malformed prerequisite evidence was accepted.');
            } catch (CorruptHistoryException) {
                self::assertSame('transfer', $proof['support'][0]['origin']);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new PrerequisiteGroups([], creditOrigin: 'institutional');
    }

    public function testFailedAuditOrPostAuditPolicyReplacementRollsBackTheWholeDraft(): void
    {
        foreach (['audit', 'policy'] as $failure) {
            [, $s, , $required, $target] = $this->fixture();
            $this->policy($s, $target->courseId, $required->courseId, 'either');
            $before = array_map($s->sql->rows(...), ['sis_prerequisites', 'sis_course_prerequisite_groups']);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($s, $target, $failure): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
                $s->sql->update('sis_course_prerequisite_groups', [
                    'prerequisite_credit_origin' => 'institutional',
                ], ['id' => $target->courseId->value]);
            });
            $editor = $this->editor($s, $audit);
            try {
                $editor->save(
                    $target->courseId,
                    [[$required->courseId->value]],
                    $editor->current($target->courseId)['revision'],
                    creditOrigin: 'transfer'
                );
                self::fail('An unaudited or replaced policy committed.');
            } catch (\RuntimeException | ConcurrentModificationException) {
                self::assertSame($before, array_map($s->sql->rows(...), [
                    'sis_prerequisites', 'sis_course_prerequisite_groups',
                ]));
            }
        }
    }
    public function testRegistrarExceptionKeepsTheSourceRuleAndMissingCourseworkWithoutInventingCredit(): void
    {
        [$context, $s, $student, $required, $target] = $this->fixture(false, true);
        $this->policy($s, $target->courseId, $required->courseId, 'institutional');
        $version = $s->publishCourse($target->courseId);
        $binding = $context->bindings()->review($target->id, $version, 'Fictional retained course selection.');
        $context->bindings()->commit($target->id, $binding['review_token']);
        $pursuit = $s->workPursuits()->activePursuit($student);
        $service = $s->prerequisitePermissions();
        $review = $service->review(
            $student,
            $pursuit,
            $target->id,
            '2026-08-20T17:00:00-04:00',
            $service->view($student, $pursuit, $target->id)['revision'],
            'Fictional registrar exception.'
        );
        $service->commit(
            $student,
            $pursuit,
            $target->id,
            new EntityId($review['stream_id']),
            new EntityId($review['operation_id']),
            $review['approval'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        $summary = $s->registrationBatches()->review(new RegistrationBatchSelection($target->id, $student))
        ['students'][0]['prerequisites'];
        self::assertSame('permission', $summary['status']);
        self::assertSame(['missing'], $summary['course_statuses']);
        $enrollment = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $enrollment->id);
        self::assertSame('institutional', $proof['credit_origin']);
        self::assertSame([], $proof['support']);
        self::assertArrayHasKey('permission', $proof);
    }

    public function testFinalAuditSourceChangesRollBackSeatsCreditsChargesAndTheBatchReceiptTogether(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $this->policy($s, $target->courseId, $required->courseId, 'either');
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $target): void {
            $s->sql->update(
                'sis_course_prerequisite_groups',
                ['prerequisite_credit_origin' => 'transfer'],
                ['id' => $target->courseId->value]
            );
        });
        $service = $s->registrationBatches(audit: $audit);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $review = $service->review($selection);
        $tables = ['sis_history_events', 'sis_enrollments', 'sis_academic_credits', 'sis_student_accounts',
            'sis_registration_batches', 'sis_course_prerequisite_groups'];
        $before = array_map($s->sql->rows(...), $tables);
        try {
            $service->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('A source change survived final confirmation checks.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }

    public function testOneCourseCanSupplyDistinctOriginsWithoutBorrowingTheOtherOriginsGrade(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $editor = $this->editor($s);
        $groups = [[$required->courseId->value], [$required->courseId->value]];
        $save = function (array $floors, ?array $rule = null) use ($s, $editor, $groups, $target): void {
            $editor->save(
                $target->courseId,
                $groups,
                $editor->current($target->courseId)['revision'],
                gradePointsMilli: $floors,
                rule: $rule,
                creditOrigin: 'institutional',
                requirementCreditOrigins: [null, 'transfer'],
            );
        };
        $save([4000, 3000]); // Institutional P has four points; transferred TR has zero.
        $selection = new RegistrationBatchSelection($target->id, $student);
        $summary = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        self::assertSame('missing', $summary['status']);
        self::assertSame([true, false], array_column($summary['groups'], 'met'));
        self::assertSame(['institutional', 'transfer'], array_column($summary['groups'], 'credit_origin'));
        self::assertSame([1, 0], array_column($summary['groups'], 'completed_count'));
        $save([4000, null], ['minimum' => 2, 'children' => [0, ['minimum' => 1, 'children' => [1]]]]);
        $summary = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        self::assertSame('met', $summary['status']);
        self::assertSame([true, true], array_column($summary['groups'], 'met'));
        self::assertStringNotContainsString('credit_id', json_encode($summary, JSON_THROW_ON_ERROR));
        $enrollment = $s->service->register($student, $target->id);
        $facts = $this->proof($s, $enrollment->id);
        self::assertSame([null, 'transfer'], $facts['requirement_credit_origins']);
        self::assertCount(2, $facts['support']);
        self::assertSame(['institutional', 'transfer'], array_column($facts['support'], 'origin'));
        self::assertSame([4000, 0], array_column($facts['support'], 'grade_points_milli'));
        self::assertSame(
            ProtectedEventCodec::canonical($facts),
            ProtectedEventCodec::canonical(PrerequisiteDecision::fromRetained($facts)->retained()),
        );
        $before = $s->sql->rows('sis_history_events');
        $save([4000, 3000]);
        self::assertSame($enrollment->id->value, $s->service->register($student, $target->id)->id->value);
        self::assertSame($facts, $this->proof($s, $enrollment->id));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testExplicitEitherCanBroadenADefaultAndInheritanceStillBlocksOtherRequirements(): void
    {
        [, $s, $student, $required, $target] = $this->fixture(false, true);
        $editor = $this->editor($s);
        $editor->save(
            $target->courseId,
            [[$required->courseId->value], [$required->courseId->value]],
            $editor->current($target->courseId)['revision'],
            creditOrigin: 'institutional',
            requirementCreditOrigins: [null, 'either'],
        );
        $selection = new RegistrationBatchSelection($target->id, $student);
        foreach ([true, false] as $staff) {
            $summary = $s->registrationBatches()->review($selection, $staff)['students'][0]['prerequisites'];
            self::assertSame('missing', $summary['status']);
            self::assertSame([false, true], array_column($summary['groups'], 'met'));
        }
        $editor->save(
            $target->courseId,
            [[$required->courseId->value], [$required->courseId->value]],
            $editor->current($target->courseId)['revision'],
            minimumRequirements: 1,
            creditOrigin: 'institutional',
            requirementCreditOrigins: [null, 'either'],
        );
        self::assertSame(
            'met',
            $s->registrationBatches()->review($selection)['students'][0]['prerequisites']['status'],
        );
        $enrollment = $s->service->register($student, $target->id);
        $facts = $this->proof($s, $enrollment->id);
        self::assertSame('transfer', $facts['support'][0]['origin']);
        self::assertSame([null, 'either'], $facts['requirement_credit_origins']);
    }

    public function testOverridePublicationCloneSubsetAndRelationshipEditsPreserveRequirementPositions(): void
    {
        [$context, $s, , $required, $target] = $this->fixture();
        $other = $s->section();
        $editor = $this->editor($s);
        $earlier = $s->publishCourse($target->courseId);
        $groups = [[$required->courseId->value], [$other->courseId->value]];
        $editor->save(
            $target->courseId,
            $groups,
            $editor->current($target->courseId)['revision'],
            creditOrigin: 'institutional',
            requirementCreditOrigins: ['transfer', 'either'],
        );
        $version = $s->publishCourse($target->courseId);
        $versions = new SqlCourseVersionProjection($s->sql);
        $versions->retain($s->coursePublications()->publishedDefinition($target->courseId, $earlier));
        $versions->retain($s->coursePublications()->publishedDefinition($target->courseId, $version));
        self::assertArrayNotHasKey(
            'prerequisite_requirement_credit_origins',
            $versions->get($earlier)->definition->describe(),
        );
        self::assertSame(
            ['transfer', 'either'],
            $versions->get($version)->definition->prerequisiteGroups->requirementCreditOrigins,
        );
        $review = $context->clones()->review($target->courseId);
        self::assertSame(['transfer', 'either'], $review['prerequisite_requirement_credit_origins']);
        $clone = new Course(EntityId::generate(), 'FICTIONAL.SOURCES', 'Fictional source clone', 3000, false);
        $context->clones()->commit($target->courseId, $clone, [$other->courseId], $review['revision']);
        $repository = new SqlCatalogWorkflowRepository($s->sql);
        self::assertSame(['either'], $repository->prerequisiteGroups($clone->id)->requirementCreditOrigins);
        $s->registration->addPrerequisite($clone->id, $required->courseId);
        self::assertSame(['either', null], $repository->prerequisiteGroups($clone->id)->requirementCreditOrigins);
        $s->registration->removePrerequisite($clone->id, $other->courseId);
        self::assertSame([null], $repository->prerequisiteGroups($clone->id)->requirementCreditOrigins);
        $s->registration->removePrerequisite($clone->id, $required->courseId);
        self::assertNull($repository->prerequisiteGroups($clone->id)->requirementCreditOrigins);
        $s->sql->update(
            'sis_course_versions',
            ['requirement_origins_json' => '["institutional","either"]'],
            ['id' => $version->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $versions->retain($s->coursePublications()->publishedDefinition($target->courseId, $version));
    }

    public function testChangingAnOverrideAtFinalAuditRollsBackAllRegistrationEffects(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $editor = $this->editor($s);
        $editor->save(
            $target->courseId,
            [[$required->courseId->value]],
            $editor->current($target->courseId)['revision'],
            requirementCreditOrigins: ['either'],
        );
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $target): void {
            $s->sql->update(
                'sis_course_prerequisite_groups',
                ['requirement_origins_json' => '["transfer"]'],
                ['id' => $target->courseId->value]
            );
        });
        $service = $s->registrationBatches(audit: $audit);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $review = $service->review($selection);
        $tables = ['sis_history_events', 'sis_enrollments', 'sis_academic_credits', 'sis_student_accounts',
            'sis_registration_batches', 'sis_course_prerequisite_groups'];
        $before = array_map($s->sql->rows(...), $tables);
        try {
            $service->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token'],
            );
            self::fail('A changed requirement policy committed registration effects.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }

    public function testChangingOnlyAnOverrideInvalidatesAnUnconfirmedReview(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $editor = $this->editor($s);
        $save = function (string $origin) use ($editor, $target, $required): void {
            $editor->save(
                $target->courseId,
                [[$required->courseId->value]],
                $editor->current($target->courseId)['revision'],
                requirementCreditOrigins: [$origin]
            );
        };
        $save('either');
        $service = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($target->id, $student);
        $review = $service->review($selection);
        $save('transfer');
        $this->expectException(ConcurrentModificationException::class);
        $service->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
        );
    }

    public function testBothOriginsStillCountAsOneCourseWithinACountedRequirement(): void
    {
        [, $s, $student, $required, $target] = $this->fixture();
        $other = $s->section();
        $editor = $this->editor($s);
        $editor->save(
            $target->courseId,
            [[$required->courseId->value, $other->courseId->value]],
            $editor->current($target->courseId)['revision'],
            minimums: [2],
            requirementCreditOrigins: ['either'],
        );
        $summary = $s->registrationBatches()->review(new RegistrationBatchSelection($target->id, $student))
        ['students'][0]['prerequisites'];
        self::assertSame('missing', $summary['status']);
        self::assertSame(1, $summary['groups'][0]['completed_count']);
        self::assertSame(2, $summary['groups'][0]['required_count']);
    }

    public function testASelectedPublicationKeepsItsOverrideAfterDraftChangesAndAnExceptionInventsNoCredit(): void
    {
        [$context, $s, $student, $required, $target] = $this->fixture(false, true);
        $editor = $this->editor($s);
        $save = function (string $origin) use ($editor, $target, $required): void {
            $editor->save(
                $target->courseId,
                [[$required->courseId->value]],
                $editor->current($target->courseId)['revision'],
                creditOrigin: 'either',
                requirementCreditOrigins: [$origin],
            );
        };
        $save('institutional');
        $version = $s->publishCourse($target->courseId);
        $binding = $context->bindings()->review($target->id, $version, 'Fictional selected source policy.');
        $context->bindings()->commit($target->id, $binding['review_token']);
        $save('either');
        $selection = new RegistrationBatchSelection($target->id, $student);
        $summary = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        self::assertSame('missing', $summary['status']);
        self::assertSame('institutional', $summary['groups'][0]['credit_origin']);
        $pursuit = $s->workPursuits()->activePursuit($student);
        $service = $s->prerequisitePermissions();
        $review = $service->review(
            $student,
            $pursuit,
            $target->id,
            '2026-08-20T17:00:00-04:00',
            $service->view($student, $pursuit, $target->id)['revision'],
            'Fictional independent prerequisite exception.',
        );
        $service->commit(
            $student,
            $pursuit,
            $target->id,
            new EntityId($review['stream_id']),
            new EntityId($review['operation_id']),
            $review['approval'],
            $review['revision'],
            $review['reason'],
            $review['review_token'],
        );
        $enrollment = $s->service->register($student, $target->id);
        $facts = $this->proof($s, $enrollment->id);
        self::assertSame(['institutional'], $facts['requirement_credit_origins']);
        self::assertSame([], $facts['support']);
        self::assertArrayHasKey('permission', $facts);
    }
}
