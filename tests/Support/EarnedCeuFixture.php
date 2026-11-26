<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\AcademicRecord\Service\EarnedCeuService;
use Domain\AcademicRecord\Service\CeuRecordService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Service\RetainedSectionReader;
use Domain\Registration\ValueObject\OfferingCeuChoice;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;

final class EarnedCeuFixture
{
    public OfferingCreditFixture $offering;
    public \SisFixture $sis;
    public EntityId $student;
    public EntityId $enrollment;
    public EntityId $credit;
    public EarnedCeuService $service;

    public function records(): CeuRecordService
    {
        $s = $this->sis;
        return new CeuRecordService(
            $s->inputVerifier(),
            new SqlAcademicCreditRepository($s->sql),
            $s->admissions,
            $s->access,
            $s->transactions,
        );
    }

    public function __construct(
        bool $complete = true,
        ?int $offeredMilli = 1250,
        int $academicMilli = 3000,
        public bool $withdrawn = false,
        bool $lateDropPolicy = false,
        bool $knownWithdrawalGrade = true,
    ) {
        require_once __DIR__ . '/OfferingCreditFixture.php';
        $this->offering = $f = new OfferingCreditFixture();
        $this->sis = $s = $f->sis;
        $f->editor->continuingEducation(
            'courses',
            $f->section->courseId,
            $offeredMilli === null ? new OfferingCeuChoice('none') : new OfferingCeuChoice('fixed', $offeredMilli),
            $f->editor->continuingEducationSettings('courses', $f->section->courseId)['revision']
        );
        if ($academicMilli !== 3000) {
            $f->editor->creditPolicy(
                $f->section->courseId,
                $academicMilli,
                \Domain\Catalog\ValueObject\CreditPolicy::fixed($academicMilli),
                $f->editor->creditSettings('courses', $f->section->courseId)['revision']
            );
        }
        $s->gradeScale([['code' => 'P', 'min_score_milli' => 0, 'max_score_milli' => 100000,
            'points_milli' => 0, 'passes' => true, 'counts_gpa' => false],
            ...(($withdrawn || $lateDropPolicy) && $knownWithdrawalGrade
                ? [['code' => 'W', 'min_score_milli' => null, 'max_score_milli' => null,
                'points_milli' => 0, 'passes' => false, 'counts_gpa' => false]] : [])]);
        if (($withdrawn || $lateDropPolicy) && $knownWithdrawalGrade) {
            $s->publishDropPolicy($s->term, -1, new \Domain\Registration\ValueObject\DropWindows([
                ['through' => null, 'grade_code' => 'W'],
            ]));
        }
        $f->bind();
        $f->select($f->publish());
        $this->student = $s->student()->id;
        $this->enrollment = $s->service->register($this->student, $f->section->id, true)->id;
        $this->credit = new EntityId(
            $s->sql->one('sis_academic_credits', ['source_id' => $this->enrollment->value])['id'],
        );
        if ($withdrawn) {
            $s->clock->time = new \DateTimeImmutable('2026-09-02T12:00:00-07:00');
            $s->service->drop($this->student, $f->section->id);
        }
        if ($complete) {
            $grade = new EntityId($s->sql->rows('sis_grades')[0]['id']);
            $review = $s->academicCredits()->review(
                $this->student,
                $f->section->id,
                $this->enrollment,
                $grade,
                $withdrawn ? 1 : 0,
                reason: 'Fictional verified institutional completion evidence.',
                effective: $s->clock->now()->format('Y-m-d'),
            );
            $s->academicCredits()->commit($this->student, $f->section->id, $review['review_token']);
            if (!$withdrawn) {
                $date = $s->academicDates()->review(
                    $this->student,
                    $f->section->id,
                    $this->enrollment,
                    '2026-08-01',
                    1,
                    'Fictional verified completion.'
                );
                $s->academicDates()->commit($this->student, $f->section->id, $date['review_token']);
            }
        }
        $this->service = $this->service();
    }

    public function service(?AuditTrail $audit = null): EarnedCeuService
    {
        $s = $this->sis;
        $history = new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new ProtectedEventCodec(\EventStoreFixture::config($s->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
        return new EarnedCeuService(
            $s->academicCredits(),
            new SqlAcademicCreditRepository($s->sql),
            $history,
            new RetainedSectionReader($history),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new ReviewProof($s->dropCipher, $s->clock),
            new \Infrastructure\Persistence\SqlActorDirectory($s->sql)
        );
    }

    public function review(?int $milli = 1250): array
    {
        $current = $this->service->view($this->student, $this->offering->section->id, $this->enrollment);
        return $this->service->review(
            $this->student,
            $this->offering->section->id,
            $this->enrollment,
            $current['expected_version'],
            $current['expected_position'],
            $milli,
            $this->withdrawn ? $this->sis->clock->now()->format('Y-m-d') : '2026-08-01',
            'Fictional institutional CEU evidence.',
            true,
            '2026-08-01',
        );
    }

    public function confirm(array $review, ?EarnedCeuService $service = null): array
    {
        return ($service ?? $this->service)->confirm(
            $this->student,
            $this->offering->section->id,
            $this->enrollment,
            $review['command'],
            $review['review_token']
        );
    }
}
