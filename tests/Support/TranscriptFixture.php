<?php

declare(strict_types=1);

require_once __DIR__ . "/CampusFixture.php";

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
        $this->campus = new CampusFixture();
        $this->sis = $this->campus->sis;
        $this->repository = new SqlTranscriptRepository($this->sis->sql);
    }

    public function request(): EntityId
    {
        $id = EntityId::generate();
        $student = $this->sis->student();
        $this->sis->service->register($student->id, $this->sis->section(20, 0)->id);
        $actor = $this->sis->access->actor;
        $this->sis->access->actor = $this->sis->admissions->person($student->personId)->ownerUserId;
        try {
            $this->campus->service->requestTranscript($id, $student->id, "pickup", "");
        } finally {
            $this->sis->access->actor = $actor;
        }
        return $id;
    }

    /** Genuine academic decision for fictional transcript fixtures. */
    public function postGrade(EntityId $request): void
    {
        $student = new EntityId($this->repository->request($request)["student_id"]);
        $enrollment = $this->sis->sql->rows("sis_enrollments", ["student_id" => $student->value])[0];
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "A", 4000, true, true);
        $this->sis->legacyGrade($grade);
        $credit = $this->sis->sql->one("sis_academic_credits", ["source_id" => $enrollment["id"]]);
        $this->campus->gradebook->finalGrade(
            $student,
            new EntityId($enrollment["section_id"]),
            $grade->id,
            (int) $credit["version"],
            reason: "Fictional reviewed grade.",
        );
    }

    public function service(
        ?TranscriptDocumentRenderer $renderer = null,
        ?AuditTrail $audit = null,
        ?TranscriptIssuer $issuer = null,
        ?\Domain\Transcript\Repository\TranscriptRepository $records = null,
        ?\Domain\Transcript\Service\TranscriptLayoutCatalog $layouts = null,
        ?\Domain\Transcript\Service\TranscriptStandingSource $standing = null,
    ): TranscriptFulfillmentService {
        $f = $this->sis;
        return new TranscriptFulfillmentService(
            $records ?? $this->repository,
            $f->admissions,
            new SqlStudentRecordRepository($f->sql),
            $renderer ?? new ProtectedTranscriptRenderer(),
            $issuer ?? new TranscriptIssuer("Fictional University", "Fictional campus address"),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->campus->cipher,
            $f->clock,
            new \Domain\Shared\Service\ReviewProof($this->campus->cipher, $f->clock),
            $f->inputVerifier(),
            $f->profileLedger(),
            $f->primaryProgramLedger(),
            new \Domain\Catalog\Service\PublishedProgramReader(
                EventStoreFixture::history($f->connection->queryBuilder()),
                new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($f->sql),
            ),
            $layouts ?? new \Infrastructure\Documents\RegisteredTranscriptLayouts(),
            new \Infrastructure\Persistence\Repository\SqlTranscriptReleasePolicyRepository($f->sql, $this->campus->cipher),
            $standing ?? $f->standingLedger(),
            new \Domain\Transcript\Service\TranscriptNotifications(
                new \Infrastructure\Persistence\Repository\SqlTranscriptUpdatesRepository($f->sql),
                $records ?? $this->repository,
                $f->admissions,
                $f->clock,
            ),
            $f->graduationReader(),
        );
    }
}
