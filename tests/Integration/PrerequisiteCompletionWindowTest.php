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
}
