<?php

declare(strict_types=1);

require_once __DIR__ . "/AcademicCalendarFixture.php";

final class ProgramPublicationFixture
{
    public AcademicCalendarFixture $calendar;
    public SisFixture $sis;
    public \Domain\Catalog\Service\ProgramPublicationService $service;
    public \Domain\Catalog\Service\ClassificationService $classifications;

    public function __construct(?SisFixture $sis = null)
    {
        $this->calendar = new AcademicCalendarFixture($sis);
        $this->sis = $f = $this->calendar->sis;
        $this->calendar->type("PG");
        $catalog = new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql);
        $references = new \Infrastructure\Persistence\Repository\SqlClassificationRepository($f->sql);
        $this->classifications = new \Domain\Catalog\Service\ClassificationService(
            $references,
            $catalog,
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $this->service = $this->publisher();
    }

    public function publisher(
        ?\Domain\Shared\Persistence\UnitOfWork $transactions = null,
    ): \Domain\Catalog\Service\ProgramPublicationService {
        $f = $this->sis;
        return new \Domain\Catalog\Service\ProgramPublicationService(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlClassificationRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlAcademicCalendarRepository($f->sql),
            new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
                $f->sql,
                $f->transactions,
                $f->clock,
                new \Infrastructure\EventSourcing\ProtectedEventCodec(
                    EventStoreFixture::config($f->connection->queryBuilder()),
                ),
                new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
            ),
            $f->access,
            $transactions ?? $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new \Domain\Catalog\Service\SpecializationPolicyService(
                new \Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository($f->sql),
                new \Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository($f->sql),
                new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($f->sql),
                $f->access, $f->transactions, $f->audit,
            ),
        );
    }
}
