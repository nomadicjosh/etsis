<?php

declare(strict_types=1);

require_once __DIR__ . "/HiatusFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\GradeDefinition;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Service\TransferAwardService;
use Domain\Transfer\Service\TransferService;
use Domain\Transfer\TransferEquivalency;
use Infrastructure\Persistence\Repository\SqlGradebookRepository;
use Infrastructure\Persistence\Repository\SqlTransferAwardRepository;
use Infrastructure\Persistence\Repository\SqlTransferCatalogRepository;
use Infrastructure\Persistence\Repository\SqlTransferRepository;
use Domain\Transfer\ValueObject\TransferRevision;

final class TransferAwardFixture
{
    public EntityId $student;
    public EntityId $course;
    public EntityId $award;
    public EntityId $credit;
    public TransferEquivalency $equivalency;
    public SqlTransferAwardRepository $repository;

    public function __construct(
        public SisFixture $sis,
        public MessageCipher $cipher,
        ?EntityId $student = null,
        bool $legacy = false,
        ?EntityId $course = null,
        ?DateTimeImmutable $completedOn = null,
    ) {
        $f = $sis;
        $this->student = $student ?? $f->student()->id;
        $this->course = $course ?? $f->section(20, 0)->courseId;
        $grades = new SqlGradebookRepository($f->sql);
        $cases1 = [["TR", 0, true, false], ["B", 3000, true, true], ["F", 0, false, true]];
        foreach ($cases1 as [$code, $points, $passes, $gpa]) {
            $f->legacyGrade(new GradeDefinition(EntityId::generate(), $code, $points, $passes, $gpa));
        }
        $catalog = new SqlTransferCatalogRepository($f->sql, $f->gradeCatalog());
        $transfer = new TransferService(
            new SqlTransferRepository($f->sql, $f->gradeCatalog()),
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->audit,
            $catalog,
            $f->transferCredits(),
        );
        $institution = $transfer->addInstitution("FICTIONAL.EXT", "Fictional Sending College");
        $external = $transfer->addExternalCourse($institution, "MATH.101", "Fictional algebra");
        $this->equivalency = new TransferEquivalency(
            EntityId::generate(),
            $institution,
            $external,
            $this->course,
            new DateRange(new DateTimeImmutable("2020-01-01")),
            2,
            3000,
            "TR",
        );
        $transfer->equate($this->equivalency);
        if ($legacy) {
            // Explicit older current-state fixture; no existing journal events are deleted or fabricated.
            $this->credit = EntityId::generate();
            $oldAward = EntityId::generate();
            $grade = $f->gradeCatalog()->byCode("TR", $this->student);
            $f->sql->insert("sis_academic_credits", [
                "id" => $this->credit->value,
                "student_id" => $this->student->value,
                "course_id" => $this->course->value,
                "term_id" => $f->term->value,
                "source_id" => $oldAward->value,
                "credit_type" => "transfer",
                "course_code" => "OBS.101",
                "course_title" => "Observed existing transfer",
                "attempted_milli" => 3000,
                "completed_milli" => 3000,
                "grade_points_milli" => 0,
                "grade_id" => $grade["id"],
                "status" => "A",
                "version" => 0,
            ]);
            $f->sql->insert("sis_transfer_awards", [
                "id" => $oldAward->value,
                "student_id" => $this->student->value,
                "equivalency_id" => $this->equivalency->id->value,
                "completed_on" => "2026-05-01",
                "term_id" => $f->term->value,
                "credit_id" => $this->credit->value,
            ]);
        } else {
            $this->credit = $transfer->award(
                $this->student,
                $this->equivalency->id,
                $institution,
                $f->term,
                $completedOn ?? new DateTimeImmutable("2026-05-01"),
                3,
                TransferRevision::of("equivalencies", $catalog->get("equivalencies", $this->equivalency->id)),
            );
        }
        $this->award = new EntityId(
            $f->sql->one("sis_academic_credits", ["id" => $this->credit->value])["source_id"],
        );
        $this->repository = new SqlTransferAwardRepository($f->sql, $f->gradeCatalog());
    }

    public function service(?AuditTrail $audit = null): TransferAwardService
    {
        $f = $this->sis;
        return new TransferAwardService(
            $this->repository,
            $f->registration,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->cipher,
            $f->clock,
            $f->transferCredits(),
        );
    }
    public function periods(?AuditTrail $audit = null): \Domain\Transfer\Service\TransferPeriodService
    {
        $f = $this->sis;
        return new \Domain\Transfer\Service\TransferPeriodService(
            $f->transferCredits(), new \Infrastructure\Persistence\Repository\SqlTransferCreditRepository($f->sql),
            $this->repository, $f->registration,
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $f->sql, $f->transactions, $f->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ), $f->transactions, $f->access, $this->cipher, $f->clock, $audit ?? $f->audit,
        );
    }
}
