<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EarnedCeuFixture;

final class WithdrawnEarnedCeuTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/EarnedCeuFixture.php';
    }

    public function testIndependentWithdrawnCeusPreserveAcademicClosureAndOriginalReceipts(): void
    {
        $f = new EarnedCeuFixture(withdrawn: true);
        $s = $f->sis;
        $section = $f->offering->section->id;
        $before = $s->sql->one('sis_academic_credits', ['id' => $f->credit->value]);
        $preserved = [$s->sql->rows('sis_enrollments'), $s->financialEvents(), $s->sql->rows('sis_grades')];
        self::assertSame('W', $before['status']);
        self::assertSame(0, (int) $before['completed_milli']);
        self::assertNull($f->service->view($f->student, $section, $f->enrollment)['completed_on']);
        $original = $f->review();
        $receipt = $f->confirm($original);
        $f->confirm($f->review(750));
        $f->confirm($f->review(null));
        $f->confirm($f->review(0));
        $s->clock->time = $s->clock->time->modify('+21 minutes');
        self::assertSame($receipt, $f->confirm($original));
        self::assertEquals(
            array_replace($before, ['version' => (int) $before['version'] + 4]),
            $s->sql->one('sis_academic_credits', ['id' => $f->credit->value]),
        );
        self::assertSame($preserved, [
            $s->sql->rows('sis_enrollments'), $s->financialEvents(), $s->sql->rows('sis_grades'),
        ]);
        $current = $f->service->view($f->student, $section, $f->enrollment);
        self::assertTrue($current['withdrawn']);
        self::assertNull($current['completed_on']);
        self::assertSame('2026-08-01', $current['earned_ceus']['completed_on']);
        self::assertSame(0, $current['earned_ceus']['earned_milli']);
        $history = $f->service->history($f->student, $section, $f->enrollment);
        self::assertSame([1250, 750, null, 0], array_column(array_column($history['items'], 'after'), 'earned_milli'));
        self::assertNotEmpty($f->service->records($f->student)['items']);
    }

    public function testClosedRecordDutyIsRequiredForNewDecisionsHistoryAndAcceptedRetries(): void
    {
        $f = new EarnedCeuFixture(withdrawn: true);
        $review = $f->review();
        $f->confirm($review);
        $f->sis->access->staff = false;
        $f->sis->access->grants = ['sis:student:read', 'sis:academic:ceus:amend'];
        $before = $this->snapshot($f);
        $view = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        self::assertFalse($view['can_record']);
        self::assertFalse($view['can_manage']);
        foreach (['review', 'history', 'retry'] as $action) {
            try {
                match ($action) {
                    'review' => $f->review(750),
                    'history' => $f->service->history($f->student, $f->offering->section->id, $f->enrollment),
                    'retry' => $f->confirm($review),
                };
                self::fail('Closed-record authority was bypassed.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
        $f->sis->access->grants[] = 'sis:gradebook:closed';
        self::assertTrue($f->service->view($f->student, $f->offering->section->id, $f->enrollment)['can_record']);
    }

    public function testIndependentCompletionMustBeExplicitValidAndNoLaterThanTheEffectiveDecision(): void
    {
        $f = new EarnedCeuFixture(withdrawn: true);
        $view = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        $before = $this->snapshot($f);
        foreach ([null, '2026-02-30', '2026-08-11'] as $date) {
            try {
                $f->service->review(
                    $f->student,
                    $f->offering->section->id,
                    $f->enrollment,
                    $view['expected_version'],
                    $view['expected_position'],
                    1250,
                    '2026-08-10',
                    'Fictional independent CEU evidence.',
                    true,
                    $date,
                );
                self::fail('Invalid CEU completion evidence was accepted.');
            } catch (ValidationException | \InvalidArgumentException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testWithdrawalAloneDoesNotSupplyPassingCompletionEvidence(): void
    {
        $f = new EarnedCeuFixture(complete: false, withdrawn: true);
        self::assertFalse($f->service->view($f->student, $f->offering->section->id, $f->enrollment)['can_record']);
        $this->expectException(ValidationException::class);
        $f->review();
    }

    public function testFinalClosedAuthorityLossOrChangedEnrollmentRollsBackTheEntireDecision(): void
    {
        foreach (['authority', 'enrollment'] as $failure) {
            $f = new EarnedCeuFixture(withdrawn: true);
            $review = $f->review();
            $before = $this->snapshot($f);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(static function () use (
                $f,
                $failure,
            ): void {
                if ($failure === 'authority') {
                    $f->sis->access->staff = false;
                    $f->sis->access->grants = ['sis:student:read', 'sis:academic:ceus:amend'];
                } else {
                    $f->sis->sql->update('sis_enrollments', ['status' => 'A'], ['id' => $f->enrollment->value]);
                }
            });
            try {
                $f->confirm($review, $f->service($audit));
                self::fail('A changed final source or duty committed a CEU decision.');
            } catch (AccessDeniedException | \Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testReplayRequiresTheExplicitWithdrawnScopeAndKeepsEarlierActiveEventsValid(): void
    {
        foreach ([false, true] as $withdrawn) {
            $f = new EarnedCeuFixture(withdrawn: $withdrawn);
            $f->confirm($f->review());
            $id = new \Domain\AcademicRecord\ValueObject\AcademicCreditId($f->credit->value);
            $stream = new \Domain\Shared\EventSourcing\StreamReference($id, 'academic_credit');
            $history = \EventStoreFixture::history($f->sis->connection->queryBuilder());
            $page = $history->page($stream);
            $root = \Domain\AcademicRecord\AcademicCredit::root($id);
            foreach ($page->events as $stored) {
                $event = $stored->event;
                if ($event instanceof \Domain\AcademicRecord\Event\EarnedCeusWereDecided) {
                    self::assertSame($withdrawn, $event->metaParam('withdrawn_ceu_decision', false));
                    $meta = $event->metadata();
                    if ($withdrawn) {
                        unset($meta['withdrawn_ceu_decision']);
                    } else {
                        $meta['withdrawn_ceu_decision'] = true;
                    }
                    try {
                        $root->acceptHistorical($event->withMetadata($meta));
                        self::fail('A CEU event was replayed in another academic scope.');
                    } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                        self::assertNull($root->earnedCeus());
                    }
                } else {
                    $root->acceptHistorical($event);
                }
            }
        }
    }

    public function testAChangedFailingGradeBlocksNumericAwardsButAllowsExplicitRevocation(): void
    {
        $f = new EarnedCeuFixture(withdrawn: true);
        $f->confirm($f->review());
        $s = $f->sis;
        $section = $f->offering->section->id;
        $version = $s->academicCredits()->auditFacts($f->credit, $f->student)['credit']['version'];
        $grade = new \Domain\Shared\ValueObject\EntityId(array_column($s->sql->rows('sis_grades'), 'id', 'code')['W']);
        $review = $s->academicCredits()->review(
            $f->student,
            $section,
            $f->enrollment,
            $grade,
            $version,
            reason: 'Fictional corrected institutional grade evidence.',
            effective: '2026-09-02',
        );
        $s->academicCredits()->commit($f->student, $section, $review['review_token']);
        try {
            $f->review(750);
            self::fail('A failing withdrawn grade awarded numeric CEUs.');
        } catch (ValidationException) {
            self::assertSame(
                1250,
                $f->service->view($f->student, $section, $f->enrollment)['earned_ceus']['earned_milli'],
            );
        }
        $f->confirm($f->review(null));
        self::assertNull($f->service->view($f->student, $section, $f->enrollment)['earned_ceus']['earned_milli']);
        self::assertSame(0, (int) $s->sql->one('sis_academic_credits', ['id' => $f->credit->value])['completed_milli']);
    }

    private function snapshot(EarnedCeuFixture $f): array
    {
        return array_map($f->sis->sql->rows(...), ['sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_academic_credits', 'sis_academic_credit_streams',
            'sis_enrollments', 'sis_audit']);
    }
}
