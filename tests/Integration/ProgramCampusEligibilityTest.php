<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramCampusFixture;

final class ProgramCampusEligibilityTest extends TestCase
{
    private function fixture(bool $additional = false, bool $publish = true, bool $cohort = true): ProgramCampusFixture
    {
        require_once dirname(__DIR__) . '/Support/ProgramCampusFixture.php';
        return new ProgramCampusFixture($additional, $publish, $cohort);
    }

    public static function locations(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('locations')]
    public function testPrimaryAndAdditionalCampusesRetainOriginalEvidence(bool $additional): void
    {
        $f = $this->fixture($additional);
        $s = $f->sis;
        $registered = $s->courseRegistrationService()->register($f->student, $f->target->section->id, true);
        $root = $s->enrollmentLedger()->verifyExisting($registered, $s->registration->section($registered->sectionId));
        $proof = $root->campusEvidence();
        self::assertSame($f->programVersion->value, $proof->data['program_version_id']);
        self::assertSame($f->sectionVersion->value, $proof->data['section_version_id']);
        self::assertSame($f->campus->value, $proof->data['campus_id']);
        $s->programCampusGate()->verify($proof, new HistoryReadBudget());
        $f->assignCohort($f->publishProgram($f->otherCampus));
        $f->policy(false);
        $before = $f->facts();
        self::assertSame(
            $registered->id->value,
            $s->courseRegistrationService()->register($f->student, $registered->sectionId, true)->id->value
        );
        self::assertSame($proof->data, $s->enrollmentLedger()->verifyExisting(
            $registered,
            $s->registration->section($registered->sectionId)
        )->campusEvidence()->data);
        self::assertSame($before, $f->facts());
    }

    public function testMismatchingAssignedCohortBlocksBothReviewAndDirectRegistrationWithoutEffects(): void
    {
        $f = $this->fixture();
        $f->assignCohort($f->publishProgram($f->otherCampus));
        $s = $f->sis;
        $before = $f->facts();
        $review = $s->registrationBatches()->review(new RegistrationBatchSelection(
            $f->target->section->id,
            $f->student
        ), true);
        $checks = array_values(array_filter(
            $review['students'][0]['readiness'],
            static fn(array $c): bool => $c['code'] === 'program_campus'
        ));
        self::assertSame('blocked', $checks[0]['status']);
        self::assertNotEmpty($review['students'][0]['issues']);
        try {
            $s->courseRegistrationService()->register($f->student, $f->target->section->id, true);
            self::fail('An ineligible program campus was accepted.');
        } catch (ValidationException $e) {
            self::assertSame('program_campus', $e->errors[0]['field']);
        }
        self::assertSame($before, $f->facts());
    }

    public function testAnUnselectedDraftCannotActivateTheRule(): void
    {
        $f = $this->fixture(publish: false);
        $before = $f->facts();
        try {
            $f->sis->courseRegistrationService()->register($f->student, $f->target->section->id, true);
            self::fail('An unpublished campus rule was accepted.');
        } catch (ValidationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testARequiredRuleCannotBePublishedWithoutASectionCampus(): void
    {
        $f = $this->fixture(publish: false);
        $f->setCampus(null);
        $f->target->bind();
        $before = $f->facts();
        try {
            $f->target->publish();
            self::fail('A campus requirement with no section campus was published.');
        } catch (ValidationException $e) {
            self::assertSame('require_program_campus', $e->errors[0]['field']);
        }
        self::assertSame($before, $f->facts());
    }

    public function testAStudentWithoutAVerifiedAssignedCohortNeedsReview(): void
    {
        $f = $this->fixture(cohort: false);
        $s = $f->sis;
        $review = $s->registrationBatches()->review(new RegistrationBatchSelection(
            $f->target->section->id,
            $f->student
        ));
        $checks = array_values(array_filter(
            $review['students'][0]['readiness'],
            static fn(array $c): bool => $c['code'] === 'program_campus'
        ));
        self::assertSame('blocked', $checks[0]['status']);
        self::assertStringContainsString('verified published program cohort', $checks[0]['message']);
        self::assertNotEmpty($review['students'][0]['issues']);
    }

    public function testALaterProgramPublicationDoesNotMoveTheStudentsAssignedCohort(): void
    {
        $f = $this->fixture();
        $f->publishProgram($f->otherCampus);
        $s = $f->sis;
        $e = $s->courseRegistrationService()->register($f->student, $f->target->section->id, true);
        self::assertSame($f->programVersion->value, $s->enrollmentLedger()->verifyExisting(
            $e,
            $s->registration->section($e->sectionId)
        )->campusEvidence()->data['program_version_id']);
    }

    public function testMovingToAnotherCohortWithTheSameCampusInvalidatesAPendingBatchReview(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $selection = new RegistrationBatchSelection($f->target->section->id, $f->student);
        $review = $s->registrationBatches()->review($selection);
        $f->assignCohort($f->publishProgram($f->campus));
        $before = $f->facts();
        try {
            $s->registrationBatches()->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('A review silently adopted another program cohort.');
        } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public static function finalChanges(): array
    {
        return [['policy'], ['cohort'], ['audit'], ['enable']];
    }

    #[DataProvider('finalChanges')]
    public function testFinalAuditAndSourceFailuresRollBackRegistrationCreditBillingAndHistory(string $change): void
    {
        $f = $this->fixture(publish: $change !== 'enable');
        $s = $f->sis;
        if ($change === 'enable') {
            $f->policy(false);
            $f->target->bind();
            $f->sectionVersion = $f->target->publish();
            $f->target->select($f->sectionVersion);
        }
        $later = $change === 'cohort' ? $f->publishProgram($f->otherCampus) : null;
        $before = $f->facts();
        $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
        $audit->method('record')->willReturnCallback(function () use ($change, $s, $f, $later): void {
            if ($change === 'audit') {
                throw new \RuntimeException('Fictional required audit failure.');
            }
            if (in_array($change, ['policy', 'enable'], true)) {
                $s->sql->update(
                    'sis_section_program_policies',
                    ['require_program_campus' => (int) ($change === 'enable')],
                    ['id' => $f->target->section->id->value]
                );
            } else {
                $f->assignCohort($later);
            }
        });
        try {
            $s->courseRegistrationService($audit)->register($f->student, $f->target->section->id, true);
            self::fail('A changed campus assessment committed.');
        } catch (\Throwable $error) {
            self::assertContains($error::class, [ValidationException::class, \RuntimeException::class,
                \Domain\Shared\Exception\ConcurrentModificationException::class, \DomainException::class]);
        }
        self::assertSame($before, $f->facts());
    }

    public function testLateWithdrawalReinstatementRetainsItsFreshCohortEvidenceAndOriginalMoney(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->gradeScale([['code' => 'W', 'min_score_milli' => null, 'max_score_milli' => null,
            'points_milli' => 0, 'passes' => false, 'counts_gpa' => false]]);
        $section = $f->target->section->id;
        $enrollment = $s->courseRegistrationService()->register($f->student, $section, true);
        $s->publishDropPolicy($s->term, -1, new \Domain\Registration\ValueObject\DropWindows([
            ['through' => null, 'grade_code' => 'W']]));
        $s->clock->time = new \DateTimeImmutable('2026-08-20T10:00:00-07:00');
        $drop = $s->drops->review($f->student, $section);
        $s->drops->commit(
            $f->student,
            $section,
            $enrollment->id,
            $drop['version'],
            new EntityId($drop['operation_id']),
            $drop['revision'],
            $drop['effective_on']
        );
        $newProgram = $f->publishProgram($f->campus);
        $f->assignCohort($newProgram);
        $money = $s->financialEvents();
        $service = $s->reinstatements();
        $review = $service->review($f->student, $section, $enrollment->id, 'Fictional corrected withdrawal.');
        $receipt = $service->confirm(
            $f->student,
            $section,
            $enrollment->id,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        $restored = $s->registration->enrollment($f->student, $section);
        self::assertSame($newProgram->value, $s->enrollmentLedger()->verifyExisting(
            $restored,
            $s->registration->section($section)
        )->campusEvidence()->data['program_version_id']);
        self::assertSame($money, $s->financialEvents());
        self::assertSame($receipt, $service->confirm(
            $f->student,
            $section,
            $enrollment->id,
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['reason'],
            $review['review_token']
        ));
    }

    public function testAcceptedBatchRetryKeepsItsOriginalCampusDecisionAfterLaterCohortChanges(): void
    {
        $f = $this->fixture();
        $service = $f->sis->registrationBatches();
        $selection = new RegistrationBatchSelection($f->target->section->id, $f->student);
        $review = $service->review($selection);
        $operation = new EntityId($review['operation_id']);
        $receipt = $service->commit($operation, $selection, $review['revision'], $review['review_token']);
        self::assertCount(1, $receipt['registrations']);
        $f->assignCohort($f->publishProgram($f->otherCampus));
        $before = $f->facts();
        self::assertSame($receipt, $service->commit(
            $operation,
            $selection,
            $review['revision'],
            $review['review_token']
        ));
        self::assertSame($before, $f->facts());
    }

    public static function falseReferences(): array
    {
        return [['campus_id'], ['program_version_id'], ['section_version_id']];
    }

    #[DataProvider('falseReferences')]
    public function testEvidenceRequiresGenuineMatchingSourcePublications(string $field): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $selection = $s->workPursuits()->activeSelection($f->student);
        $proof = $s->programCampusGate()->assess(
            $selection,
            $s->registration->section($f->target->section->id),
            new HistoryReadBudget()
        )['evidence'];
        $proof[$field] = EntityId::generate()->value;
        $before = $f->facts();
        try {
            $s->programCampusGate()->verify(
                new \Domain\Registration\ValueObject\ProgramCampusEvidence($proof),
                new HistoryReadBudget()
            );
            self::fail('An unrelated publication or campus qualified.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testCampusReadinessHonorsTheSharedReplayLimitWithoutWritingFacts(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $before = $f->facts();
        try {
            $s->programCampusGate()->assess(
                $s->workPursuits()->activeSelection($f->student),
                $s->registration->section($f->target->section->id),
                new HistoryReadBudget(1, 1)
            );
            self::fail('Campus verification escaped its shared replay limit.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame($before, $f->facts());
        }
    }

    #[DataProvider('locations')]
    public function testEarlierCampusInformationRequiresAPositiveRetainedMatch(bool $primaryRecorded): void
    {
        $f = $this->fixture();
        $f->assignCohort($f->historicalProgram($primaryRecorded));
        $s = $f->sis;
        $before = $f->facts();
        $assessment = $s->programCampusGate()->assess(
            $s->workPursuits()->activeSelection($f->student),
            $s->registration->section($f->target->section->id),
            new HistoryReadBudget()
        );
        self::assertSame($primaryRecorded ? 'met' : 'blocked', $assessment['checks'][0]['status']);
        if (!$primaryRecorded) {
            self::assertStringContainsString(
                'incomplete retained campus information',
                $assessment['checks'][0]['message'],
            );
            self::assertNull($assessment['evidence']);
        }
        self::assertSame($before, $f->facts());
    }

    public function testACorruptedRetainedOfferingProjectionCannotAuthorizeRegistration(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $s->sql->update('sis_section_versions', ['require_program_campus' => 0], ['id' => $f->sectionVersion->value]);
        $before = $f->facts();
        try {
            $s->courseRegistrationService()->register($f->student, $f->target->section->id, true);
            self::fail('A damaged retained projection authorized an enrollment.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException | ValidationException | \DomainException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testWaitlistHeadCannotRegisterAfterItsAssignedCampusBecomesIneligible(): void
    {
        $f = $this->fixture(publish: false);
        $s = $f->sis;
        $section = $f->target->section->id;
        $s->sql->update('sis_sections', ['capacity' => 1], ['id' => $section->value]);
        $f->target->bind();
        $f->sectionVersion = $f->target->publish();
        $f->target->select($f->sectionVersion);
        $filler = $s->courseRegistrationService()->register($f->student, $section, true);
        $queued = $s->student($s->user());
        $f->student = $queued->id;
        $assignment = $s->sql->rows('sis_student_programs', ['student_id' => $queued->id->value], 1)[0];
        $f->pursuit = new EntityId($assignment['id']);
        $f->assignCohort($f->programVersion);
        $waitlists = $s->waitlists();
        $policy = $waitlists->reviewPolicy(
            $section,
            new \Domain\Registration\Waitlist\WaitlistPolicy(true, 20)->describe(),
            $waitlists->policy($section)['version'],
            'Fictional queue policy.'
        );
        $waitlists->confirmPolicy(
            $section,
            new EntityId($policy['operation_id']),
            $policy['policy'],
            $policy['version'],
            $policy['reason'],
            $policy['review_token']
        );
        $join = $waitlists->review($f->student, $section, 'requested', true, null);
        $waitlists->confirm(
            $f->student,
            $section,
            new EntityId($join['operation_id']),
            $join['action'],
            true,
            $join['revision'],
            $join['review_token'],
            $join['reason']
        );
        $drop = $s->drops->review($filler->studentId, $section);
        $s->drops->commit(
            $filler->studentId,
            $section,
            $filler->id,
            $drop['version'],
            new EntityId($drop['operation_id']),
            $drop['revision'],
            $drop['effective_on']
        );
        $f->assignCohort($f->publishProgram($f->otherCampus));
        $before = $f->facts();
        try {
            $s->courseRegistrationService()->register($f->student, $section, true);
            self::fail('Queue priority waived the program campus rule.');
        } catch (ValidationException) {
            self::assertSame($before, $f->facts());
        }
        self::assertSame(1, $waitlists->view($f->student, $section, true)['rank']);
    }
}
