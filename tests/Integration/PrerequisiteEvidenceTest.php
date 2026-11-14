<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . '/Support/SisFixture.php';
require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
require_once dirname(__DIR__) . '/Support/HistoryVerificationFixture.php';

use Domain\Gradebook\GradeDefinition;
use Domain\Registration\EnrollmentHistory;
use Domain\Registration\Event\EnrollmentWasEstablished;
use Domain\Registration\Service\EnrollmentHistoryService;
use Domain\Registration\Service\RegistrationBatchService;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;
use TransferAwardFixture;

final class PrerequisiteEvidenceTest extends TestCase
{
    private function grade(SisFixture $s, EntityId $student, EntityId $section, EntityId $enrollment, string $code, int $version): void
    {
        $grade = new EntityId($s->gradeCatalog()->byCode($code, $student)['id']);
        $review = $s->academicCredits()->review(
            $student,
            $section,
            $enrollment,
            $grade,
            $version,
            reason: 'Fictional academic evidence correction.'
        );
        $s->academicCredits()->commit($student, $section, $review['review_token']);
    }

    private function institution(): array
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'F', 0, false, true));
        $required = $s->section(faculty: $s->access->actor);
        $enrollment = $s->service->register($student, $required->id);
        $this->grade($s, $student, $required->id, $enrollment->id, 'P', 0);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $next = $s->section();
        $s->registration->addPrerequisite($next->courseId, $required->courseId);
        return [$s, $student, $required, $enrollment, $credit, $next];
    }

    private function events(SisFixture $s, EntityId $enrollment): array
    {
        return iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->value)));
    }

    private function batch(SisFixture $s, ?\Domain\Registration\Service\PrerequisiteService $prerequisites = null): RegistrationBatchService
    {
        return new RegistrationBatchService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($s->sql),
            $s->registration,
            $s->admissions,
            $s->service,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($s->sql),
            $s->workPursuits(),
            $prerequisites ?? $s->prerequisites(),
        );
    }

    public function testAcceptanceRetainsTheExactInstitutionalReferencesAndDoesNotReinterpretThemAfterGradeCorrection(): void
    {
        [$s, $student, $required, $previous, $credit, $next] = $this->institution();
        $enrollment = $s->service->register($student, $next->id);
        $event = $this->events($s, $enrollment->id)[0];
        $proof = $event->metaParam('prerequisite_evidence');
        self::assertSame($s->workPursuits()->activePursuit($student)->value, $proof['assignment_id']);
        self::assertSame([$required->courseId->value], $proof['required_course_ids']);
        self::assertSame([['academic_version' => 2, 'course_id' => $required->courseId->value,
            'credit_id' => $credit->value, 'origin' => 'institutional', 'pursuit_version' => 1,
            'registration_version' => 1, 'source_id' => $previous->id->value]], $proof['support']);
        $this->grade($s, $student, $required->id, $previous->id, 'F', 1);
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($enrollment->id->value, $s->service->register($student, $next->id)->id->value);
        self::assertSame($proof, $this->events($s, $enrollment->id)[0]->metaParam('prerequisite_evidence'));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertFalse($s->prerequisitesMet($student, $next->courseId));
        $history = new EnrollmentHistoryService(
            new SqlEnrollmentHistoryRepository($s->sql),
            $s->registration,
            $s->enrollmentLedger(),
            $s->access,
            $s->transactions,
            $s->audit
        );
        $rows = array_column($history->student($student)['items'], null, 'enrollment_id');
        self::assertSame(
            ['status' => 'verified', 'required_course_count' => 1, 'supporting_record_count' => 1],
            $rows[$enrollment->id->value]['prerequisites']
        );
        foreach (['support', 'academic_version', 'credit_id', 'actor_id', 'reason'] as $restricted) {
            self::assertStringNotContainsString('"' . $restricted . '"', json_encode($rows, JSON_THROW_ON_ERROR));
        }
    }

    public function testTransferProofRetainsItsAwardStreamAndNoInventedRegistrationVersion(): void
    {
        $s = new SisFixture();
        $transfer = new TransferAwardFixture($s, $s->dropCipher);
        $next = $s->section();
        $s->registration->addPrerequisite($next->courseId, $transfer->course);
        $enrollment = $s->service->register($transfer->student, $next->id);
        $reference = $this->events($s, $enrollment->id)[0]->metaParam('prerequisite_evidence')['support'][0];
        self::assertSame('transfer', $reference['origin']);
        self::assertSame($transfer->award->value, $reference['source_id']);
        self::assertSame($transfer->credit->value, $reference['credit_id']);
        self::assertSame(1, $reference['academic_version']);
        self::assertNull($reference['registration_version']);
    }

    public function testBatchReviewShowsReadinessAndGradeChangesInvalidateAnEarlierConfirmationEvenIfStillPassing(): void
    {
        [$s, $student, $required, $previous, , $next] = $this->institution();
        $batch = $this->batch($s);
        $selection = new RegistrationBatchSelection($next->id, $student);
        $review = $batch->review($selection);
        self::assertSame('met', $review['students'][0]['prerequisites']['status']);
        self::assertSame(0, $review['students'][0]['prerequisites']['missing_count']);
        self::assertStringNotContainsString('"support"', json_encode($review, JSON_THROW_ON_ERROR));
        // A reasoned re-posting of the same passing grade changes its supporting academic version.
        $this->grade($s, $student, $required->id, $previous->id, 'P', 1);
        $before = $s->sql->rows('sis_history_events');
        try {
            $batch->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('A batch used academic evidence changed after its review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertNull($s->registration->enrollment($student, $next->id));
        }
        $fresh = $batch->review($selection);
        $result = $batch->commit(new EntityId($fresh['operation_id']), $selection, $fresh['revision']);
        self::assertSame(1, $result['created']);
        self::assertSame(3, $this->events($s, new EntityId($result['registrations'][0]['enrollment_id']))[0]
            ->metaParam('prerequisite_evidence')['support'][0]['academic_version']);
    }

    public function testBatchReadinessDoesNotInventPassingEvidenceForUngradedWork(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $prior = $s->section();
        $s->service->register($student, $prior->id);
        $next = $s->section();
        $s->registration->addPrerequisite($next->courseId, $prior->courseId);
        $before = $s->sql->rows('sis_history_events');
        $review = $this->batch($s)->review(new RegistrationBatchSelection($next->id, $student));
        self::assertSame('missing', $review['students'][0]['prerequisites']['status']);
        self::assertSame(1, $review['students'][0]['prerequisites']['missing_count']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testAcceptedBatchRetryDoesNotRecheckItsEarlierPrerequisitesAfterLaterCorrections(): void
    {
        [$s, $student, $required, $previous, , $next] = $this->institution();
        $batch = $this->batch($s);
        $selection = new RegistrationBatchSelection($next->id, $student);
        $review = $batch->review($selection);
        $operation = new EntityId($review['operation_id']);
        $result = $batch->commit($operation, $selection, $review['revision']);
        $this->grade($s, $student, $required->id, $previous->id, 'F', 1);
        $before = $s->sql->rows('sis_history_events');
        self::assertSame($result, $batch->commit($operation, $selection, $review['revision']));
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }

    public function testOlderEventsStayExplicitlyUnrecordedAndMalformedNewEvidenceCannotReplay(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $enrollment = $s->service->register($student, $s->section()->id);
        $event = $this->events($s, $enrollment->id)[0];
        $metadata = $event->metadata();
        unset($metadata['prerequisite_evidence']);
        // An isolated earlier-contract event, without rewriting any stored event or fabricating earlier evidence.
        $old = EnrollmentWasEstablished::occur(new EnrollmentHistoryId($enrollment->id->value), $event->payload(), $metadata);
        $root = EnrollmentHistory::root(new EnrollmentHistoryId($enrollment->id->value));
        $root->acceptHistorical($old);
        self::assertSame('not_recorded', $root->prerequisiteSummary()['status']);
        $bad = $event->withAddedMetadata('prerequisite_evidence', null);
        $this->expectException(CorruptHistoryException::class);
        EnrollmentHistory::root(new EnrollmentHistoryId($enrollment->id->value))->acceptHistorical($bad);
    }

    public function testOperatorVerificationCanTraversePursuitStreamsAndNewEvidenceWithoutSideEffects(): void
    {
        $f = new \HistoryVerificationFixture();
        $student = $f->sis->student()->id;
        $f->sis->service->register($student, $f->sis->section()->id);
        $before = $f->digest();
        $result = $f->verifier->verify(streamLimit: 50, eventLimit: 2000);
        self::assertTrue($result->complete);
        self::assertGreaterThan(0, $result->events);
        self::assertSame($before, $f->digest());
    }
    public function testReuseDecisionVersionIsRetainedAndRevocationInvalidatesAPendingBatch(): void
    {
        [$s, $student, , , $credit, $next] = $this->institution();
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', ['id' => $program->value, 'code' => 'NEXT', 'title' => 'Fictional new program']);
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
        $assignment = new EntityId($changed['assignment_id']);
        $reuse = $s->workPursuits()->review($student, $credit, $assignment, 'approve_reuse', 'Fictional reuse approval.');
        $s->workPursuits()->commit($student, $credit, $reuse['review_token']);
        $batch = $this->batch($s);
        $selection = new RegistrationBatchSelection($next->id, $student);
        $review = $batch->review($selection);
        $revoke = $s->workPursuits()->review($student, $credit, $assignment, 'revoke_reuse', 'Fictional approval revocation.');
        $s->workPursuits()->commit($student, $credit, $revoke['review_token']);
        try {
            $batch->commit(new EntityId($review['operation_id']), $selection, $review['revision']);
            self::fail('A batch retained permission to use revoked coursework.');
        } catch (ConcurrentModificationException) {
            self::assertNull($s->registration->enrollment($student, $next->id));
        }
        $reuse = $s->workPursuits()->review($student, $credit, $assignment, 'approve_reuse', 'Fictional new reuse approval.');
        $s->workPursuits()->commit($student, $credit, $reuse['review_token']);
        $enrollment = $s->service->register($student, $next->id);
        $proof = $this->events($s, $enrollment->id)[0]->metaParam('prerequisite_evidence');
        self::assertSame($assignment->value, $proof['assignment_id']);
        self::assertSame(4, $proof['support'][0]['pursuit_version']);
        self::assertSame($credit->value, $proof['support'][0]['credit_id']);
    }

    public function testBatchReviewRechecksAuthorityAfterReadingSupportingAcademicHistory(): void
    {
        [$s, $student, , , , $next] = $this->institution();
        $repository = $this->createMock(\Domain\DegreeAudit\Repository\AcademicInputRepository::class);
        $real = new \Infrastructure\Persistence\Repository\SqlAcademicInputRepository($s->sql);
        $repository->expects(self::once())->method('inventory')->willReturnCallback(
            static function (EntityId $id) use ($s, $real): \Domain\DegreeAudit\ValueObject\AcademicInputInventory {
                $inputs = $real->inventory($id);
                $s->access->staff = false;
                return $inputs;
            }
        );
        $prerequisites = new \Domain\Registration\Service\PrerequisiteService(
            new \Domain\DegreeAudit\Service\AcademicInputVerifier(
                $repository,
                $s->academicCredits(),
                $s->transferCredits(),
                $s->enrollmentLedger()
            ),
            $s->workPursuits()
        );
        $before = $s->sql->rows('sis_history_events');
        try {
            $this->batch($s, $prerequisites)->review(new RegistrationBatchSelection($next->id, $student));
            self::fail('Revoked authority returned student eligibility information.');
        } catch (\Domain\Shared\Exception\AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
    }
}
