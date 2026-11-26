<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\EarnedCeusWereDecided;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\EarnedCeuFixture;

final class IndependentCeuCompletionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/EarnedCeuFixture.php';
    }

    public function testCeuCompletionNeedsNoAcademicGradeAndCreatesNoAcademicCredit(): void
    {
        $f = new EarnedCeuFixture(complete: false, academicMilli: 0);
        $s = $f->sis;
        $before = $s->sql->one('sis_academic_credits', ['id' => $f->credit->value]);
        $finance = $s->financialEvents();
        self::assertNull($before['grade_id']);
        self::assertTrue($f->service->view($f->student, $f->offering->section->id, $f->enrollment)['can_record']);
        $f->confirm($f->review());
        self::assertEquals(
            array_replace($before, ['version' => (int) $before['version'] + 1]),
            $s->sql->one('sis_academic_credits', ['id' => $f->credit->value]),
        );
        self::assertSame($finance, $s->financialEvents());
        $view = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        self::assertNull($view['completed_on']);
        self::assertSame('2026-08-01', $view['earned_ceus']['completed_on']);
        self::assertSame(1250, $f->records()->record($f->student)[0]['earned_ceus']['earned_milli']);
    }

    public function testIndependentDateCorrectionsDoNotReplaceTheAcademicCompletionDate(): void
    {
        $f = new EarnedCeuFixture();
        $current = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        $review = $f->service->review(
            $f->student,
            $f->offering->section->id,
            $f->enrollment,
            $current['expected_version'],
            $current['expected_position'],
            1250,
            '2026-08-10',
            'Fictional independently verified continuing education completion.',
            true,
            '2026-08-02',
        );
        $receipt = $f->confirm($review);
        $f->confirm($f->review(750));
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        self::assertSame($receipt, $f->confirm($review));
        $view = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        self::assertSame('2026-08-01', $view['completed_on']);
        self::assertSame('2026-08-01', $view['earned_ceus']['completed_on']);
        self::assertSame(750, $view['earned_ceus']['earned_milli']);
    }

    public function testEarlyRemovalRequiresExplicitCeuResolutionAndKeepsOriginalReceipts(): void
    {
        foreach ([0, 1250] as $milli) {
            $f = new EarnedCeuFixture(complete: false, academicMilli: 0);
            $review = $f->review($milli);
            $receipt = $f->confirm($review);
            $before = $this->snapshot($f);
            try {
                $f->sis->service->drop($f->student, $f->offering->section->id);
                self::fail('An unresolved CEU record was removed.');
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
            $f->confirm($f->review(null));
            $events = $f->sis->sql->rows('sis_history_events');
            $f->sis->service->drop($f->student, $f->offering->section->id);
            self::assertSame([], $f->sis->sql->rows('sis_academic_credits', ['id' => $f->credit->value]));
            self::assertSame([], $f->records()->record($f->student));
            $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
            self::assertSame($receipt, $f->confirm($review));
            $after = $f->sis->sql->rows('sis_history_events');
            foreach ($events as $original) {
                self::assertContains($original, $after);
            }
            $f->sis->access->staff = false;
            $f->sis->access->grants = ['sis:student:read', 'sis:academic:ceus:amend'];
            try {
                $f->confirm($review);
                self::fail('A removed-record retry bypassed closed-record authority.');
            } catch (\Domain\Shared\Exception\AccessDeniedException) {
                self::assertSame($after, $f->sis->sql->rows('sis_history_events'));
            }
        }
    }

    public function testLateWithdrawalPreservesIndependentlyEarnedCeus(): void
    {
        $f = new EarnedCeuFixture(complete: false, academicMilli: 0, lateDropPolicy: true);
        $review = $f->review();
        $receipt = $f->confirm($review);
        $finance = $f->sis->financialEvents();
        $f->sis->clock->time = new \DateTimeImmutable('2026-09-02T12:00:00-07:00');
        $single = $f->sis->drops->review($f->student, $f->offering->section->id);
        self::assertTrue($single['has_earned_ceus']);
        $plan = $f->sis->drops->reviewPlan(new \Domain\Registration\ValueObject\RegistrationPlanSelection(
            $f->student,
            $f->offering->section->id,
        ), true);
        self::assertTrue($plan['courses'][0]['has_earned_ceus']);
        self::assertArrayNotHasKey('ceu_award', $plan['courses'][0]);
        $f->sis->service->drop($f->student, $f->offering->section->id);
        $view = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        self::assertTrue($view['withdrawn']);
        self::assertNull($view['completed_on']);
        self::assertSame(1250, $view['earned_ceus']['earned_milli']);
        self::assertSame(1250, $f->records()->record($f->student)[0]['earned_ceus']['earned_milli']);
        self::assertSame($receipt, $f->confirm($review));
        self::assertSame($finance, $f->sis->financialEvents());
    }

    public function testEarlierUnmarkedDecisionsKeepTheirAcademicDateRule(): void
    {
        foreach ([false, true] as $complete) {
            $f = new EarnedCeuFixture(complete: $complete);
            $f->confirm($f->review());
            $id = new AcademicCreditId($f->credit->value);
            $page = \EventStoreFixture::history($f->sis->connection->queryBuilder())->page(
                new StreamReference($id, 'academic_credit'),
            );
            $root = AcademicCredit::root($id);
            foreach ($page->events as $stored) {
                $event = $stored->event;
                if ($event instanceof EarnedCeusWereDecided) {
                    self::assertTrue($event->metaParam('independent_ceu_completion'));
                    $metadata = $event->metadata();
                    unset($metadata['independent_ceu_completion']);
                    try {
                        $root->acceptHistorical($event->withMetadata($metadata));
                        self::assertTrue($complete);
                        self::assertSame(1250, $root->earnedCeus()['earned_milli']);
                    } catch (CorruptHistoryException) {
                        self::assertFalse($complete);
                    }
                } else {
                    $root->acceptHistorical($event);
                }
            }
        }
    }

    public function testLaterAcademicGradeAndDateDoNotRecalculateTheCeuDecision(): void
    {
        $f = new EarnedCeuFixture(complete: false);
        $s = $f->sis;
        $original = $f->review();
        $receipt = $f->confirm($original);
        $grade = new \Domain\Shared\ValueObject\EntityId($s->sql->rows('sis_grades')[0]['id']);
        $version = (int) $s->sql->one('sis_academic_credits', ['id' => $f->credit->value])['version'];
        $review = $s->academicCredits()->review(
            $f->student,
            $f->offering->section->id,
            $f->enrollment,
            $grade,
            $version,
        );
        $s->academicCredits()->commit($f->student, $f->offering->section->id, $review['review_token']);
        $date = $s->academicDates()->review(
            $f->student,
            $f->offering->section->id,
            $f->enrollment,
            '2026-08-03',
            $version + 1,
            'Fictional separately verified academic completion.',
        );
        $s->academicDates()->commit($f->student, $f->offering->section->id, $date['review_token']);
        $current = $f->service->view($f->student, $f->offering->section->id, $f->enrollment);
        self::assertSame('2026-08-03', $current['completed_on']);
        self::assertSame('2026-08-01', $current['earned_ceus']['completed_on']);
        self::assertSame(1250, $current['earned_ceus']['earned_milli']);
        self::assertSame($receipt, $f->confirm($original));
    }

    private function snapshot(EarnedCeuFixture $f): array
    {
        return array_map($f->sis->sql->rows(...), ['sis_history_events', 'sis_history_operations',
            'sis_history_streams', 'sis_academic_credits', 'sis_academic_credit_streams',
            'sis_enrollments', 'sis_audit']);
    }
}
