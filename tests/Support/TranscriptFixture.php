<?php

declare(strict_types=1);

require_once __DIR__ . '/CampusFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptFulfillmentService;
use Domain\Transcript\ValueObject\TranscriptIssuer;
use Infrastructure\Documents\ProtectedTranscriptRenderer;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptRepository;

final class TranscriptFixture
{
    public CampusFixture $campus;
    public SisFixture $sis;
    public SqlTranscriptRepository $repository;

    public function __construct()
    {
        $this->campus = new CampusFixture(); $this->sis = $this->campus->sis;
        $this->repository = new SqlTranscriptRepository($this->sis->sql);
    }

    public function request(): EntityId
    {
        $id = EntityId::generate(); $student = $this->sis->student();
        $this->sis->service->register($student->id, $this->sis->section(20, 0)->id);
        $actor = $this->sis->access->actor;
        $this->sis->access->actor = $this->sis->admissions->person($student->personId)->ownerUserId;
        try { $this->campus->service->requestTranscript($id, $student->id, 'pickup', ''); }
        finally { $this->sis->access->actor = $actor; }
        return $id;
    }

    public function service(?TranscriptDocumentRenderer $renderer = null, ?AuditTrail $audit = null,
        ?TranscriptIssuer $issuer = null): TranscriptFulfillmentService
    {
        $f = $this->sis;
        return new TranscriptFulfillmentService($this->repository, $f->admissions,
            new SqlStudentRecordRepository($f->sql), $renderer ?? new ProtectedTranscriptRenderer(),
            $issuer ?? new TranscriptIssuer('Fictional University', 'Fictional campus address'),
            $f->access, $f->transactions, $audit ?? $f->audit, $this->campus->cipher, $f->clock);
    }
}
