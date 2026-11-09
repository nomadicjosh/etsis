<?php

declare(strict_types=1);

require_once __DIR__ . '/SisFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Service\ReportService;
use Domain\Reporting\Service\ReportRequestService;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRequestRepository;

final class ReportRequestFixture
{
    public SisFixture $sis;
    public SqlReportRepository $reports;
    public ReportService $sharing;
    public SqlReportRequestRepository $repository;
    public \Domain\Shared\ValueObject\EntityId $requester;
    public \Domain\Shared\ValueObject\EntityId $specialist;
    public function __construct()
    {
        $this->sis = $f = new SisFixture();
        $this->requester = $f->access->actor;
        $f->student(); // A fictional requester with a canonical person record.
        $this->specialist = $f->user();
        $this->reports = new SqlReportRepository($f->sql);
        $this->repository = new SqlReportRequestRepository($f->sql);
        $this->sharing = new ReportService($this->reports, $f->access, $f->transactions, $f->audit);
    }
    public function service(?AuditTrail $audit = null): ReportRequestService
    {
        $f = $this->sis;
        return new ReportRequestService($this->repository, $this->reports, new SqlRelationalReportRepository($f->sql),
            $this->sharing, $f->access, $f->transactions, $audit ?? $f->audit, $f->dropCipher, $f->clock);
    }
    public function asRequester(): void
    {
        $f = $this->sis; $f->access->actor = $this->requester; $f->access->staff = false; $f->access->grants = ['sis:reporting:run'];
    }
    public function asSpecialist(): void
    {
        $f = $this->sis; $f->access->actor = $this->specialist; $f->access->staff = false; $f->access->grants = ['sis:reporting:manage', 'sis:reporting:run'];
    }
}
