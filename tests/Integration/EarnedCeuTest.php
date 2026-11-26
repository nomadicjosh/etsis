<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\EarnedCeuFixture;

final class EarnedCeuTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/EarnedCeuFixture.php';
    }

    public function testReviewedAwardsCorrectionsAndRevocationPreserveAcademicAndFinancialQuantities(): void
    {
        $f = new EarnedCeuFixture();
        $s = $f->sis;
        $before = $s->sql->one('sis_academic_credits', ['id' => $f->credit->value]);
        $finance = $s->financialEvents();
        $review = $f->review();
        self::assertNull($s->academicCredits()->auditFacts($f->credit, $f->student)['earned_ceus']);
        $receipt = $f->confirm($review);
        self::assertSame(
            1250,
            $s->academicCredits()->auditFacts($f->credit, $f->student)['earned_ceus']['earned_milli'],
        );
        $f->confirm($f->review(750));
        $f->confirm($f->review(null));
        self::assertNull($s->academicCredits()->auditFacts($f->credit, $f->student)['earned_ceus']['earned_milli']);
        self::assertSame($receipt, $f->confirm($review));
        self::assertEquals(
            array_replace($before, ['version' => (int) $before['version'] + 3]),
            $s->sql->one('sis_academic_credits', ['id' => $f->credit->value])
        );
        self::assertSame($finance, $s->financialEvents());
        $history = $f->service->history($f->student, $f->offering->section->id, $f->enrollment);
        self::assertSame([1250, 750, null], array_column(array_column($history['items'], 'after'), 'earned_milli'));
    }

    public function testExplicitCeuCompletionEvidenceIsRequiredWithoutAnAcademicGrade(): void
    {
        $f = new EarnedCeuFixture(false);
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $current = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        $f->service->review(
            $f->student,
            $f->offering->section->id,
            $f->enrollment,
            $current['expected_version'],
            $current['expected_position'],
            1250,
            '2026-08-01',
            'Fictional CEU evidence requiring an explicit completion date.',
            true,
            null,
        );
    }

    public function testChangedAcademicPositionAndExpiredReviewRefuseNewDecisionsButPreserveAcceptedRetries(): void
    {
        $f = new EarnedCeuFixture();
        $stale = $f->review(1000);
        $first = $f->review();
        $receipt = $f->confirm($first);
        try {
            $f->confirm($stale);
            self::fail('A stale CEU review completed.');
        } catch (ConcurrentModificationException) {
            self::assertSame(1250, $f->service->view(
                $f->student,
                $f->offering->section->id,
                $f->enrollment
            )['earned_ceus']['earned_milli']);
        }
        $expired = $f->review(750);
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        self::assertSame($receipt, $f->confirm($first));
        $this->expectException(ConcurrentModificationException::class);
        $f->confirm($expired);
    }

    public function testProjectionTamperingCannotBecomeAnAwardOrTranscriptSource(): void
    {
        $f = new EarnedCeuFixture();
        $f->confirm($f->review());
        $award = $f->sis->sql->one('sis_academic_credit_streams', ['id' => $f->credit->value]);
        $data = json_decode($award['earned_ceus'], true, flags: JSON_THROW_ON_ERROR);
        $data['earned_milli'] = 1;
        $f->sis->sql->update(
            'sis_academic_credit_streams',
            ['earned_ceus' => json_encode($data)],
            ['id' => $f->credit->value]
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->sis->academicCredits()->auditFacts($f->credit, $f->student);
    }

    public function testRequiredAuditFinalAuthorityExpiryAndProjectionChecksRollBackTheWholeDecision(): void
    {
        foreach (['audit', 'authority', 'expiry', 'projection'] as $failure) {
            $f = new EarnedCeuFixture();
            $s = $f->sis;
            $review = $f->review();
            $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
                'sis_academic_credits', 'sis_academic_credit_streams', 'sis_audit'];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit->expects(self::once())->method('record')->willReturnCallback(static function () use (
                $f,
                $s,
                $failure,
            ): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit unavailable.');
                }
                if ($failure === 'authority') {
                    $s->access->staff = false;
                    $s->access->grants = ['sis:student:read'];
                } elseif ($failure === 'expiry') {
                    $s->clock->time = $s->clock->time->modify('+21 minutes');
                } else {
                    $s->sql->update(
                        'sis_academic_credit_streams',
                        ['earned_ceus' => null],
                        ['id' => $f->credit->value],
                    );
                }
            });
            try {
                $f->confirm($review, $f->service($audit));
                self::fail('A failed CEU boundary completed.');
            } catch (\RuntimeException | \DomainException $error) {
                self::assertNotEmpty($error->getMessage());
                self::assertSame($before, array_map($s->sql->rows(...), $tables));
            }
        }
    }

    public function testProofsDoNotAuthorizeAnotherActorStudentOrChangedCommand(): void
    {
        foreach (['actor', 'student', 'command'] as $failure) {
            $f = new EarnedCeuFixture();
            $review = $f->review();
            $before = $f->sis->sql->rows('sis_history_events');
            if ($failure === 'actor') {
                $f->sis->access->actor = $f->sis->user();
            } elseif ($failure === 'student') {
                $f->student = $f->sis->student()->id;
            } else {
                $review['command']['reason'] = 'Different fictional instruction.';
            }
            try {
                $f->confirm($review);
                self::fail('A different CEU command was accepted.');
            } catch (\Domain\Shared\Exception\AccessDeniedException | \Domain\Shared\Exception\ValidationException) {
                self::assertSame($before, $f->sis->sql->rows('sis_history_events'));
            }
        }
    }

    public function testCurrentOfferingEditsDoNotChangeTheAcceptedCeuCeilingAndZeroIsExplicit(): void
    {
        $f = new EarnedCeuFixture();
        $section = $f->offering->section->id;
        $f->offering->editor->continuingEducation(
            'sections',
            $section,
            new \Domain\Registration\ValueObject\OfferingCeuChoice('fixed', 9000),
            $f->offering->editor->continuingEducationSettings('sections', $section)['revision']
        );
        $f->offering->amend($f->offering->publish());
        self::assertSame(1250, $f->service->view($f->student, $section, $f->enrollment)['offered_milli']);
        $f->confirm($f->review(0));
        self::assertSame(0, $f->service->view($f->student, $section, $f->enrollment)['earned_ceus']['earned_milli']);
        $this->expectException(\InvalidArgumentException::class);
        $f->review(1251);
    }

    public function testAnExplicitNoCeuOfferingCannotBeConvertedIntoAnEarnedAward(): void
    {
        $f = new EarnedCeuFixture(offeredMilli: null);
        self::assertNull($f->service->view($f->student, $f->offering->section->id, $f->enrollment)['offered_milli']);
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $f->review(1);
    }

    public function testRevocationAndExplicitZeroStayDistinctAndCommandKeyOrderDoesNotChangeTheDecision(): void
    {
        $f = new EarnedCeuFixture();
        $f->confirm($f->review(0));
        $f->confirm($f->review(null));
        $review = $f->review(0);
        $review['command']['previous_award'] = array_reverse($review['command']['previous_award'], true);
        $review['command']['award'] = array_reverse($review['command']['award'], true);
        $f->confirm($review);
        self::assertSame(
            0,
            $f->service->view($f->student, $f->offering->section->id, $f->enrollment)['earned_ceus']['earned_milli'],
        );
        $f->confirm($f->review(null));
        self::assertNull(
            $f->service->view($f->student, $f->offering->section->id, $f->enrollment)['earned_ceus']['earned_milli'],
        );
    }
}
