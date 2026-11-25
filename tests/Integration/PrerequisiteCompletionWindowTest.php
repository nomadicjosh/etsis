<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Domain\Transfer\ValueObject\AwardChange;
use SisFixture;

final class PrerequisiteCompletionWindowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
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

    private function fixture(?string $completedOn): array
    {
        $context = new \CatalogWorkflowFixture();
        $s = $context->sis;
        $student = $s->student()->id;
        $required = $s->section(faculty: $s->access->actor);
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 4000, true, false));
        $enrollment = $s->service->register($student, $required->id);
        $ledger = $s->academicCredits();
        $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
        $review = $ledger->review($student, $required->id, $enrollment->id, $grade, 0);
        $ledger->commit($student, $required->id, $review['review_token']);
        if ($completedOn !== null) {
            $dates = $s->academicDates();
            $credit = $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value]);
            $review = $dates->review(
                $student,
                $required->id,
                $enrollment->id,
                $completedOn,
                (int) $credit['version'],
                'Fictional actual completion evidence.'
            );
            $dates->commit($student, $required->id, $review['review_token']);
        }
        return [$context, $s, $student, $required, $s->section(), $enrollment];
    }

    private function save(SisFixture $s, EntityId $target, EntityId $required, ?array $windows): array
    {
        $editor = $this->editor($s);
        return $editor->save(
            $target,
            [[$required->value]],
            $editor->current($target)['revision'],
            completionWindows: $windows
        );
    }

    private function proof(SisFixture $s, EntityId $enrollment): array
    {
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->value)));
        return $events[0]->metaParam('prerequisite_evidence');
    }

    public static function completions(): array
    {
        return ['lower inclusive' => ['2025-01-01', true], 'upper inclusive' => ['2025-12-31', true],
            'before' => ['2024-12-31', false], 'after' => ['2026-01-01', false], 'unknown' => [null, false]];
    }

    #[DataProvider('completions')]
    public function testActualInclusiveCompletionControlsRegistration(?string $on, bool $met): void
    {
        [, $s, $student, $required, $target, $source] = $this->fixture($on);
        $window = ['from' => '2025-01-01', 'through' => '2025-12-31'];
        $this->save($s, $target->courseId, $required->courseId, [$window]);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $events = \EventStoreFixture::historyDigest($s->sql);
        foreach ([true, false] as $staff) {
            $review = $s->registrationBatches()->review($selection, $staff);
            $summary = $review['students'][0]['prerequisites'];
            self::assertSame($met ? 'met' : 'missing', $summary['status']);
            self::assertSame($window, $summary['groups'][0]['completion_window']);
            self::assertStringNotContainsString('credit_id', json_encode($summary));
            self::assertStringNotContainsString('source_id', json_encode($summary));
        }
        self::assertSame($events, \EventStoreFixture::historyDigest($s->sql));
        if (!$met) {
            $this->expectException(\DomainException::class);
            $s->service->register($student, $target->id);
            return;
        }
        $enrollment = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $enrollment->id);
        self::assertSame($on, $proof['support'][0]['completed_on']);
        self::assertSame([$window], $proof['completion_windows']);
        self::assertSame(
            ProtectedEventCodec::canonical($proof),
            ProtectedEventCodec::canonical(PrerequisiteDecision::fromRetained($proof)->retained())
        );
        $this->save($s, $target->courseId, $required->courseId, [['from' => '2026-01-01', 'through' => null]]);
        $credit = $s->sql->one('sis_academic_credits', ['source_id' => $source->id->value]);
        $dates = $s->academicDates();
        $later = $dates->review(
            $student,
            $required->id,
            $source->id,
            '2026-01-01',
            (int) $credit['version'],
            'Fictional later corrected completion.'
        );
        $dates->commit($student, $required->id, $later['review_token']);
        $before = \EventStoreFixture::historyDigest($s->sql);
        self::assertSame($enrollment->id->value, $s->service->register($student, $target->id)->id->value);
        self::assertSame($proof, $this->proof($s, $enrollment->id));
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testCompletionPolicyPublicationProjectionCloneAndSubsetsPreserveEarlierUnknowns(): void
    {
        [$context, $s, , $required, $target] = $this->fixture('2025-06-01');
        $earlier = $s->publishCourse($target->courseId);
        $other = $s->section();
        $window = ['from' => null, 'through' => '2025-12-31'];
        $editor = $this->editor($s);
        $saved = $editor->save(
            $target->courseId,
            [[$other->courseId->value], [$required->courseId->value]],
            $editor->current($target->courseId)['revision'],
            completionWindows: [null, $window]
        );
        self::assertSame([null, $window], $saved['completion_windows']);
        $version = $s->publishCourse($target->courseId);
        $projection = new SqlCourseVersionProjection($s->sql);
        $projection->retain($s->coursePublications()->publishedDefinition($target->courseId, $earlier));
        $projection->retain($s->coursePublications()->publishedDefinition($target->courseId, $version));
        self::assertArrayNotHasKey(
            'prerequisite_completion_windows',
            $projection->get($earlier)->definition->describe()
        );
        self::assertSame(
            [null, $window],
            $projection->get($version)->definition->prerequisiteGroups->completionWindows
        );
        $review = $context->clones()->review($target->courseId);
        self::assertSame([null, $window], $review['prerequisite_completion_windows']);
        $clone = new \Domain\Catalog\Course(EntityId::generate(), 'COPY.WINDOW', 'Fictional date policy', 3000, false);
        $context->clones()->commit($target->courseId, $clone, [$required->courseId], $review['revision']);
        $id = $clone->id;
        self::assertSame([$window], $context->operations->prerequisiteGroups($id)->completionWindows);
        $s->registration->addPrerequisite($id, $other->courseId);
        self::assertSame([$window, null], $context->operations->prerequisiteGroups($id)->completionWindows);
        $s->registration->removePrerequisite($id, $required->courseId);
        self::assertSame([null], $context->operations->prerequisiteGroups($id)->completionWindows);
    }

    public function testCompletionOnlyEditInvalidatesAnUnconfirmedRegistrationAndRollsBackFinalDrift(): void
    {
        [, $s, $student, $required, $target] = $this->fixture('2025-06-01');
        $this->save($s, $target->courseId, $required->courseId, [['from' => '2025-01-01', 'through' => null]]);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $service = $s->registrationBatches();
        $review = $service->review($selection);
        $this->save($s, $target->courseId, $required->courseId, [['from' => '2025-02-01', 'through' => null]]);
        try {
            $service->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('A completion-only policy edit survived a pending registration review.');
        } catch (ConcurrentModificationException) {
            self::assertSame(0, $s->registration->sectionCount($target->id));
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $target): void {
            $s->sql->update('sis_course_prerequisite_groups', ['completion_windows_json' => json_encode([
                ['from' => '2025-03-01', 'through' => null],
            ], JSON_THROW_ON_ERROR)], ['id' => $target->courseId->value]);
        });
        $service = $s->registrationBatches(audit: $audit);
        $review = $service->review($selection);
        $tables = ['sis_history_events', 'sis_history_operations', 'sis_enrollments', 'sis_academic_credits',
            'sis_student_accounts', 'sis_registration_batches', 'sis_course_prerequisite_groups'];
        $before = array_map($s->sql->rows(...), $tables);
        try {
            $service->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('Final completion-policy drift committed academic or financial facts.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }

    public function testUnknownCompletionStillQualifiesAnUnrestrictedRequirementWithoutNewEvidenceFields(): void
    {
        [, $s, $student, $required, $target] = $this->fixture(null);
        $this->save($s, $target->courseId, $required->courseId, null);
        $enrollment = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $enrollment->id);
        self::assertArrayNotHasKey('completion_windows', $proof);
        self::assertArrayNotHasKey('completed_on', $proof['support'][0]);
        self::assertTrue(PrerequisiteDecision::fromRetained($proof)->isMet());
    }
    public function testDifferentTransferDatesAndActualGradesStaySeparateWithinRepeatedCourseRequirements(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        [, $s, $student, $required, $target] = $this->fixture('2024-06-01');
        $transfer = new \TransferAwardFixture(
            $s,
            $s->dropCipher,
            $student,
            course: $required->courseId,
            completedOn: new \DateTimeImmutable('2024-06-01')
        );
        $catalog = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository($s->sql, $s->gradeCatalog());
        $awards = new \Domain\Transfer\Service\TransferService(
            new \Infrastructure\Persistence\Repository\SqlTransferRepository($s->sql, $s->gradeCatalog()),
            $s->registration,
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->audit,
            $catalog,
            $s->transferCredits()
        );
        $recent = $awards->award(
            $student,
            $transfer->equivalency->id,
            $transfer->equivalency->externalInstitutionId,
            $s->term,
            new \DateTimeImmutable('2025-06-01'),
            3,
            \Domain\Transfer\ValueObject\TransferRevision::of(
                'equivalencies',
                $catalog->get('equivalencies', $transfer->equivalency->id)
            ),
            $transfer->courseVersion
        );
        $editor = $this->editor($s);
        $groups = [[$required->courseId->value], [$required->courseId->value]];
        $windows = [['from' => '2024-01-01', 'through' => '2024-12-31'],
            ['from' => '2025-01-01', 'through' => '2025-12-31']];
        $editor->save(
            $target->courseId,
            $groups,
            $editor->current($target->courseId)['revision'],
            creditOrigin: 'transfer',
            completionWindows: $windows
        );
        $registered = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $registered->id);
        self::assertSame(['2024-06-01', '2025-06-01'], array_column($proof['support'], 'completed_on'));
        self::assertTrue(PrerequisiteDecision::fromRetained($proof)->isMet());
        $other = $s->section();
        $editor->save(
            $other->courseId,
            $groups,
            $editor->current($other->courseId)['revision'],
            gradePointsMilli: [0, 3000],
            creditOrigin: 'either',
            completionWindows: $windows
        );
        $selection = new RegistrationBatchSelection($other->id, $student);
        $missing = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        self::assertSame('missing', $missing['status']);
        $award = new EntityId($s->sql->one('sis_academic_credits', ['id' => $recent->value])['source_id']);
        $change = new AwardChange('amend', 3000, 'B', 'Fictional recent grade correction.');
        $service = $transfer->service();
        $review = $service->preview($student, $award, $change);
        $service->confirm(
            $student,
            $award,
            new EntityId($review['operation_id']),
            $change,
            $review['revision'],
            $review['review_token']
        );
        $satisfied = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        self::assertSame('met', $satisfied['status']);
        self::assertSame($proof, $this->proof($s, $registered->id));
    }

    private function recencyOffering(
        \CatalogWorkflowFixture $context,
        \Domain\Registration\Section $target,
        string $start = '2026-08-31',
        bool $select = true
    ): EntityId {
        $s = $context->sis;
        if ($s->registration->courseForSection($target)->publicationVersionId === null) {
            $version = $s->publishCourse($target->courseId);
            $binding = $context->bindings()->review($target->id, $version, 'Fictional dated prerequisite binding.');
            $context->bindings()->commit($target->id, $binding['review_token']);
        }
        $publisher = $context->sectionPublications();
        $review = $publisher->review($target->id, $start, '2026-12-20', 'Fictional instructional dates.');
        $version = new EntityId($publisher->publish($target->id, $review['review_token'])['operation_id']);
        if ($select) {
            $review = $publisher->reviewSelection($target->id, $version, 'Fictional initial offering.');
            $publisher->select($target->id, $review['review_token']);
        }
        return $version;
    }

    private function amendments(
        \CatalogWorkflowFixture $context,
    ): \Domain\Registration\Service\SectionOfferingAmendmentService {
        $s = $context->sis;
        return new \Domain\Registration\Service\SectionOfferingAmendmentService(
            $context->sectionPublications(),
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($s->sql),
            new SqlCatalogRevisionRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository($s->sql),
            $s->registration,
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $s->sql,
                $s->transactions,
                $s->clock,
                new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()
            ),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
    }

    public static function recencyCompletions(): array
    {
        return ['lower inclusive' => ['2021-08-31', true], 'one day too old' => ['2021-08-30', false],
            'recent' => ['2025-06-01', true], 'unknown' => [null, false]];
    }

    #[DataProvider('recencyCompletions')]
    public function testAgeUsesPublishedInstructionalStartAndAcceptedProofSurvivesLaterDates(
        ?string $on,
        bool $met,
    ): void {
        [$context, $s, $student, $required, $target] = $this->fixture($on);
        $this->save($s, $target->courseId, $required->courseId, [
            ['from' => null, 'through' => null, 'max_age_months' => 60],
        ]);
        $offering = $this->recencyOffering($context, $target);
        $selection = new RegistrationBatchSelection($target->id, $student);
        foreach ([true, false] as $staff) {
            $summary = $s->registrationBatches()->review($selection, $staff)['students'][0]['prerequisites'];
            self::assertSame($met ? 'met' : 'missing', $summary['status']);
            self::assertSame(
                ['starts_on' => '2026-08-31', 'earliest_completion_on' => '2021-08-31'],
                $summary['groups'][0]['completion_anchor']
            );
            self::assertStringNotContainsString('section_version_id', json_encode($summary));
        }
        if (!$met) {
            $this->expectException(\DomainException::class);
            $s->service->register($student, $target->id);
            return;
        }
        $enrollment = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $enrollment->id);
        self::assertSame($offering->value, $proof['completion_anchor']['section_version_id']);
        self::assertSame('2026-08-31', $proof['completion_anchor']['starts_on']);
        self::assertSame($on, $proof['support'][0]['completed_on']);
        self::assertTrue(PrerequisiteDecision::fromRetained($proof)->isMet());
        $next = $this->recencyOffering($context, $target, '2026-09-01', false);
        $amendments = $this->amendments($context);
        $review = $amendments->review($target->id, $next, 'Fictional later offering dates.');
        $amendments->commit($target->id, $review['review_token']);
        $s->clock->time = $s->clock->time->modify('+6 years');
        $before = \EventStoreFixture::historyDigest($s->sql);
        self::assertSame($enrollment->id->value, $s->service->register($student, $target->id)->id->value);
        self::assertSame($proof, $this->proof($s, $enrollment->id));
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testKnownTermDatesDoNotSubstituteForAnUnknownPublishedSectionStart(): void
    {
        [, $s, $student, $required, $target] = $this->fixture('2025-06-01');
        $this->save($s, $target->courseId, $required->courseId, [
            ['from' => null, 'through' => null, 'max_age_months' => 60],
        ]);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $summary = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        self::assertSame('missing', $summary['status']);
        self::assertNull($summary['groups'][0]['completion_anchor']);
        $this->expectException(\DomainException::class);
        $s->service->register($student, $target->id);
    }

    public function testRecencyAloneIsRetainedAcrossPublicationAndSelectedCloneRequirements(): void
    {
        [$context, $s, , $required, $target] = $this->fixture('2025-06-01');
        $saved = $this->save($s, $target->courseId, $required->courseId, [
            ['from' => null, 'through' => null, 'max_age_months' => 60],
        ]);
        $version = $s->publishCourse($target->courseId);
        $definition = $s->coursePublications()->publishedDefinition($target->courseId, $version);
        self::assertSame(
            $saved['completion_windows'],
            $definition->definition->prerequisiteGroups->completionWindows,
        );
        $clones = $context->clones();
        $review = $clones->review($target->courseId);
        $clone = new \Domain\Catalog\Course(
            EntityId::generate(),
            'COPY.RECENT',
            'Fictional recency clone',
            3000,
            false,
        );
        $clones->commit($target->courseId, $clone, [$required->courseId], $review['revision']);
        self::assertSame(
            $saved['completion_windows'],
            $context->operations->prerequisiteGroups($clone->id)->completionWindows,
        );
    }

    public function testChangedInstructionalStartInvalidatesPendingReviewAndFinalAcceptanceAtomically(): void
    {
        [$context, $s, $student, $required, $target] = $this->fixture('2025-06-01');
        $this->save($s, $target->courseId, $required->courseId, [
            ['from' => null, 'through' => null, 'max_age_months' => 60],
        ]);
        $this->recencyOffering($context, $target);
        $next = $this->recencyOffering($context, $target, '2026-09-01', false);
        $amendments = $this->amendments($context);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $batches = $s->registrationBatches();
        $pending = $batches->review($selection);
        $review = $amendments->review($target->id, $next, 'Fictional instructional change.');
        $amendments->commit($target->id, $review['review_token']);
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_enrollments',
            'sis_academic_credits', 'sis_student_accounts', 'sis_registration_batches', 'sis_audit'];
        $before = array_map($s->sql->rows(...), $tables);
        try {
            $batches->commit(
                new EntityId($pending['operation_id']),
                $selection,
                $pending['revision'],
                $pending['review_token'],
            );
            self::fail('A changed section start preserved a pending registration confirmation.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
        $later = $this->recencyOffering($context, $target, '2026-09-02', false);
        $before = array_map($s->sql->rows(...), $tables);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(
            static function ($actor, $action) use ($target, $amendments, $later): void {
                if ($action === 'registration.created') {
                    $review = $amendments->review($target->id, $later, 'Fictional completion-boundary change.');
                    $amendments->commit($target->id, $review['review_token']);
                }
            },
        );
        try {
            $s->courseRegistrationService($audit)->register($student, $target->id);
            self::fail('A final offering-date change committed academic or financial facts.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }

    public function testAtomicScheduleUsesEachSectionsOwnStartAndRetriesKeepBothOriginalProofs(): void
    {
        [$context, $s, $student, $required, $first] = $this->fixture('2025-06-01');
        $second = $s->section();
        foreach ([$first, $second] as $section) {
            $this->save($s, $section->courseId, $required->courseId, [
                ['from' => null, 'through' => null, 'max_age_months' => 60],
            ]);
        }
        $this->recencyOffering($context, $first, '2026-08-31');
        $this->recencyOffering($context, $second, '2026-08-15');
        $plan = new \Domain\Registration\ValueObject\RegistrationPlanSelection($student, $first->id, $second->id);
        $batches = $s->registrationBatches();
        $review = $batches->reviewPlan($plan, true);
        self::assertTrue($review['can_confirm']);
        $operation = new EntityId($review['operation_id']);
        $receipt = $batches->commitPlan($operation, $plan, $review['revision'], $review['review_token'], true);
        self::assertSame(2, $receipt['created']);
        $proofs = [];
        foreach ([$first, $second] as $section) {
            $enrollment = $s->registration->enrollment($student, $section->id);
            $proofs[] = $this->proof($s, $enrollment->id);
            self::assertTrue(PrerequisiteDecision::fromRetained($proofs[array_key_last($proofs)])->isMet());
        }
        self::assertSame(
            ['2026-08-31', '2026-08-15'],
            array_map(static fn(array $proof): string => $proof['completion_anchor']['starts_on'], $proofs)
        );
        $before = \EventStoreFixture::historyDigest($s->sql);
        $s->clock->time = $s->clock->time->modify('+1 day');
        self::assertSame(
            $receipt,
            $batches->commitPlan($operation, $plan, $review['revision'], $review['review_token'], true),
        );
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
    }

    public static function incorrectOfferingEvidence(): array
    {
        return [['different publication'], ['missing anchor']];
    }

    #[DataProvider('incorrectOfferingEvidence')]
    public function testRecordedAgeContextCannotBeDetachedFromItsAcceptedOffering(string $damage): void
    {
        [$context, $s, $student, $required, $target] = $this->fixture('2025-06-01');
        $editor = $this->editor($s);
        $editor->save(
            $target->courseId,
            [[$required->courseId->value], [$required->courseId->value]],
            $editor->current($target->courseId)['revision'],
            minimumRequirements: 1,
            completionWindows: [null, ['from' => null, 'through' => null, 'max_age_months' => 60]]
        );
        $this->recencyOffering($context, $target);
        $enrollment = $s->service->register($student, $target->id);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->id->value)));
        $event = $events[0];
        $facts = $event->metaParam('prerequisite_evidence');
        if ($damage === 'missing anchor') {
            $facts['completion_anchor'] = null;
        } else {
            $facts['completion_anchor']['section_version_id'] = EntityId::generate()->value;
        }
        // The unrestricted alternative remains met; the separate offering reference must still authenticate.
        self::assertTrue(PrerequisiteDecision::fromRetained($facts)->isMet());
        $bad = $event->withAddedMetadata('prerequisite_evidence', $facts);
        $root = \Domain\Registration\EnrollmentHistory::root(new EnrollmentHistoryId($enrollment->id->value));
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $root->acceptHistorical($bad);
    }

    public function testReceivingPursuitTransferMustMeetSourceAndActualRecencyTogether(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        [$context, $s, $student, $required, $target] = $this->fixture('2020-06-01');
        $transfer = new \TransferAwardFixture(
            $s,
            $s->dropCipher,
            $student,
            course: $required->courseId,
            completedOn: new \DateTimeImmutable('2025-06-01'),
        );
        $editor = $this->editor($s);
        $groups = [[$required->courseId->value], [$required->courseId->value]];
        $window = ['from' => null, 'through' => null, 'max_age_months' => 18];
        $editor->save(
            $target->courseId,
            $groups,
            $editor->current($target->courseId)['revision'],
            minimumRequirements: 1,
            requirementCreditOrigins: ['institutional', 'transfer'],
            completionWindows: [$window, $window],
        );
        $this->recencyOffering($context, $target);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $summary = $s->registrationBatches()->review($selection)['students'][0]['prerequisites'];
        // A different source's actual completion must not qualify the older institutional attempt.
        self::assertSame('met', $summary['status']);
        self::assertSame([false, true], array_column($summary['groups'], 'met'));
        $enrollment = $s->service->register($student, $target->id);
        $proof = $this->proof($s, $enrollment->id);
        self::assertCount(1, $proof['support']);
        self::assertSame('transfer', $proof['support'][0]['origin']);
        self::assertSame('2025-06-01', $proof['support'][0]['completed_on']);
        self::assertSame($transfer->credit->value, $proof['support'][0]['credit_id']);
        self::assertTrue(PrerequisiteDecision::fromRetained($proof)->isMet());
    }
}
