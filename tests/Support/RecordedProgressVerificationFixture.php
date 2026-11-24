<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\DegreeAudit\Service\DegreeProgressRecordService;
use Domain\DegreeAudit\Service\RecordedProgressReader;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlDegreeProgressRecordRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;

final class RecordedProgressVerificationFixture
{
    public \StudentSpecializationRequirementsFixture $curriculum;
    public \SisFixture $sis;
    public EntityId $record;
    public DegreeProgressRecordService $records;

    public function __construct()
    {
        require_once __DIR__ . '/StudentSpecializationRequirementsFixture.php';
        require_once __DIR__ . '/DegreeProgressFixture.php';
        $this->curriculum = $f = new \StudentSpecializationRequirementsFixture();
        $this->sis = $s = $f->r->sis;
        $f->r->saveDegree();
        $version = new EntityId($f->r->publishDegree()['operation_id']);
        $requirements = $f->requirements();
        $selection = $requirements->review(
            $f->student,
            $f->assignment,
            $f->r->degree,
            $version,
            new \Domain\DegreeAudit\ValueObject\PursuitStart('2026-01-31'),
            'Fictional degree selection.'
        );
        $requirements->assign($f->student, $f->assignment, $selection['review_token']);
        $f->assign();
        $cipher = $f->r->catalog->fixture->cipher;
        $history = $f->r->history;
        $repository = new SqlDegreeProgressRecordRepository($s->sql);
        $this->records = new DegreeProgressRecordService(
            \DegreeProgressFixture::service($s, $history, $requirements, $cipher, specializations: $f->service),
            $repository,
            new SqlStudentRecordRepository($s->sql),
            $f->service,
            $s->admissions,
            $history,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
            new ReviewProof($cipher, $s->clock),
            new RecordedProgressReader($repository, $history),
        );
        $review = $this->records->review($f->student, $f->assignment, 'Fictional private progress evidence.');
        $this->record = new EntityId($review['operation_id']);
        $this->records->confirm(
            $f->student,
            $f->assignment,
            $this->record,
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
    }

    public function facts(): array
    {
        $result = [];
        foreach (
            ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_degree_progress_records', 'sis_student_programs', 'sis_academic_credits', 'sis_audit'] as $table
        ) {
            $result[$table] = $this->sis->sql->rows($table);
        }
        return $result;
    }
}
