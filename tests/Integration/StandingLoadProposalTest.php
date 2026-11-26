<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentStanding;
use Domain\Student\ValueObject\StandingProposalEvidence;
use PHPUnit\Framework\TestCase;
use Tests\Support\StandingLoadFixture;

final class StandingLoadProposalTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/StandingLoadFixture.php';
    }

    public function testSelectedDeterminationMeetsInclusiveBoundsAndIsRetainedWithoutRecalculatingLiveLoad(): void
    {
        $t = new StandingLoadFixture();
        $f = $t->base;
        $before = $f->facts();
        $proposal = $t->proposal();
        self::assertSame(3000, $proposal['load']['credits_milli']);
        self::assertSame('FULL', $proposal['load']['classification']['code']);
        self::assertSame($t->record->value, $proposal['proposal']['load_id']);
        self::assertSame($before, $f->facts());
        $t->publishLoad(9000);
        $f->work();
        $t->record();
        self::assertSame(3000, $t->proposal()['load']['credits_milli']);
        $review = $t->review($t->proposal());
        $receipt = $t->commit($review);
        $last = $f->sis->standingLedger()->latest($f->student, $f->pursuit);
        $proof = StudentStanding::proposalEvidence($last->event);
        self::assertSame(3000, $proof->data['load_assessment']['snapshot']['sources'][0]['credits_milli']);
        self::assertSame($t->record->value, $proof->data['load_assessment']['record_id']);
        self::assertStringNotContainsString($f->student->value, json_encode($proof));
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        self::assertSame($receipt, $t->commit($review));
    }

    public function testMissingOrNonqualifyingDeterminationsNeverCreateStanding(): void
    {
        $t = new StandingLoadFixture();
        $f = $t->base;
        foreach ([null, EntityId::generate()] as $id) {
            $before = $f->facts();
            try {
                $f->sis->standingService()->propose($f->student, $f->pursuit, load: $id);
                self::fail('Unavailable load evidence was accepted.');
            } catch (ValidationException) {
                self::assertSame($before, $f->facts());
            }
        }
        $failures = [['minimum_credits_milli', 3001], ['maximum_credits_milli', 2999], ['classification_code', 'PART']];
        foreach ($failures as [$key, $value]) {
            $rules = ['minimum_credits_milli' => 0, 'maximum_credits_milli' => null, 'classification_code' => null];
            $rules[$key] = $value;
            $f->rules['load_requirement'] = $rules;
            $f->publish();
            try {
                $t->proposal();
                self::fail('A load outside the published requirement was accepted.');
            } catch (ValidationException) {
                self::assertSame([], $f->sis->sql->rows('sis_student_standing'));
            }
        }
    }

    public function testRetainedEvidenceRejectsForeignRoutingFutureDatesMismatchedTermsAndMissingRequirements(): void
    {
        $t = new StandingLoadFixture();
        $t->commit($t->review($t->proposal()));
        $f = $t->base;
        $proof = StudentStanding::proposalEvidence($f->sis->standingLedger()->latest($f->student, $f->pursuit)->event);
        foreach (['student_id', 'pursuit_id', 'future', 'requirement', 'missing', 'term'] as $change) {
            $data = $proof->data;
            if (in_array($change, ['student_id', 'pursuit_id'], true)) {
                $data['load_assessment']['snapshot'][$change] = EntityId::generate()->value;
            } elseif ($change === 'future') {
                $data['as_of'] = '2026-01-01';
            } elseif ($change === 'requirement') {
                unset($data['rules']['load_requirement']);
            } elseif ($change === 'missing') {
                unset($data['load_assessment']);
            } else {
                $data['assessment'] = ['basis' => 'term', 'term' => [
                    'id' => EntityId::generate()->value, 'code' => 'OTHER',
                ]];
                $data['rules']['gpa_basis'] = 'term';
            }
            try {
                new StandingProposalEvidence($data);
                self::fail('Inconsistent retained load context was accepted.');
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIndependentLoadDutyProtectsProposalAndRedactsHistoryWithoutHidingStanding(): void
    {
        $t = new StandingLoadFixture();
        $t->commit($t->review($t->proposal()));
        $f = $t->base;
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:student:read', 'sis:standing:manage', 'sis:audit:read'];
        $history = $f->sis->standingService()->timeline($f->student, $f->pursuit);
        self::assertSame(['restricted' => true], $history['items'][0]['proposal']['load']);
        self::assertStringNotContainsString('credits_milli', json_encode($history['items'][0]['proposal']['load']));
        $this->expectException(AccessDeniedException::class);
        $t->proposal();
    }

    public function testFinalLoadProjectionOrDutyLossAndRequiredAuditFailureRollbackTheWholeDecision(): void
    {
        foreach (['source', 'authority', 'audit'] as $failure) {
            $t = new StandingLoadFixture();
            $f = $t->base;
            $review = $t->review($t->proposal());
            $before = $f->facts();
            $rows = $f->sis->sql->rows('sis_load_determinations');
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($failure, $t, $f): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional required audit failure.');
                }
                if ($failure === 'source') {
                    $f->sis->sql->update(
                        'sis_load_determinations',
                        ['classification_title' => 'Fictional drift'],
                        ['id' => $t->record->value]
                    );
                } else {
                    $f->sis->access->staff = false;
                    $f->sis->access->grants = ['sis:student:read', 'sis:standing:manage', 'sis:audit:read'];
                }
            });
            try {
                $t->commit($review, $audit);
                self::fail('A failed final load boundary committed standing.');
            } catch (\RuntimeException) {
                self::assertSame($before, $f->facts());
                self::assertSame($rows, $f->sis->sql->rows('sis_load_determinations'));
            }
        }
    }

    public function testGpaOnlyPolicyRejectsAnUnrequestedLoadContextRatherThanSilentlyRecordingIt(): void
    {
        $t = new StandingLoadFixture();
        unset($t->base->rules['load_requirement']);
        $t->base->publish();
        $this->expectException(ValidationException::class);
        $t->proposal();
    }

    public function testARealOtherPursuitsDeterminationCannotBeSubstituted(): void
    {
        $t = new StandingLoadFixture();
        $f = $t->base;
        $s = $f->sis;
        $student = $s->student();
        $pursuit = new EntityId($s->sql->rows('sis_student_programs', ['student_id' => $student->id->value])[0]['id']);
        $loads = $t->loads();
        $review = $loads->review($student->id, $pursuit, $s->term, 'Fictional other pursuit load.');
        $record = new EntityId($review['operation_id']);
        $loads->confirm(
            $student->id,
            $pursuit,
            $s->term,
            $record,
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        $before = $f->facts();
        try {
            $s->standingService()->propose($f->student, $f->pursuit, load: $record);
            self::fail('A foreign accepted determination was used.');
        } catch (ValidationException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testApplicableProgramOverrideHasItsOwnLoadRequirement(): void
    {
        $t = new StandingLoadFixture();
        $f = $t->base;
        $pursuit = $f->sis->sql->one('sis_student_programs', ['id' => $f->pursuit->value]);
        $program = $f->sis->sql->one('sis_programs', ['id' => $pursuit['program_id']]);
        $override = $f->rules;
        unset($f->rules['load_requirement']);
        $f->rules['overrides'] = [['kind' => 'program', 'target' => array_intersect_key(
            $program,
            array_flip(['id', 'code', 'title'])
        ), 'rules' => $override]];
        $f->publish();
        self::assertSame('program', $t->proposal()['scope']['kind']);
        self::assertSame('FULL', $t->proposal()['load']['classification']['code']);
        $this->expectException(ValidationException::class);
        $f->sis->standingService()->propose($f->student, $f->pursuit);
    }

    public function testIndependentManualStandingDoesNotFabricateOrRequireProposalLoadEvidence(): void
    {
        $t = new StandingLoadFixture();
        $f = $t->base;
        $row = $f->row($t->proposal());
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:student:read', 'sis:standing:manage'];
        $service = $f->sis->standingService();
        $review = $service->review(
            $f->student,
            $f->pursuit,
            [$row],
            $service->view($f->student, $f->pursuit)['revision'],
            'Fictional independent manual decision.'
        );
        $receipt = $service->commit(
            $f->student,
            $f->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
        self::assertSame('updated', $receipt['status']);
        $last = $f->sis->standingLedger()->latest($f->student, $f->pursuit);
        self::assertNull(StudentStanding::proposalEvidence($last->event));
    }
}
