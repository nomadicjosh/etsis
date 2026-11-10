<?php

declare(strict_types=1);

require_once __DIR__ . "/HiatusFixture.php";

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\CourseCloneService;
use Domain\Catalog\Service\OfferingService;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;

final class CatalogWorkflowFixture
{
    public HiatusFixture $fixture;
    public SisFixture $sis;
    public SqlCatalogWorkflowRepository $operations;

    public function __construct()
    {
        $this->fixture = new HiatusFixture();
        $this->sis = $this->fixture->sis;
        $this->operations = new SqlCatalogWorkflowRepository($this->sis->sql);
    }

    public function clones(?AuditTrail $audit = null): CourseCloneService
    {
        $f = $this->sis;
        return new CourseCloneService(
            $this->operations,
            new SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->fixture->cipher,
            $f->clock,
        );
    }

    public function offerings(
        ?AuditTrail $audit = null,
        ?\Domain\Shared\Persistence\UnitOfWork $transactions = null,
    ): OfferingService {
        $f = $this->sis;
        return new OfferingService(
            $this->operations,
            new SqlCatalogRevisionRepository($f->sql),
            $f->registration,
            $f->access,
            $transactions ?? $f->transactions,
            $audit ?? $f->audit,
            $this->fixture->cipher,
            $f->clock,
            $this->publications(),
            $this->bindings($audit),
            new \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository($f->sql),
            $this->sectionPublications($audit),
        );
    }

    public function sectionPublications(
        ?AuditTrail $audit = null,
    ): \Domain\Registration\Service\SectionPublicationService {
        $f = $this->sis;
        return new \Domain\Registration\Service\SectionPublicationService(
            new SqlCatalogRevisionRepository($f->sql),
            $this->operations,
            new \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository($f->sql),
            $this->publications(),
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
            $f->transactions,
            $audit ?? $f->audit,
            $this->fixture->cipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),
        );
    }

    public function publications(): \Domain\Catalog\Service\CoursePublicationService
    {
        $f = $this->sis;
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Catalog\Service\CoursePublicationService(
            new SqlCatalogRevisionRepository($f->sql),
            $this->operations,
            $history,
            $f->access,
            $f->transactions,
            $f->audit,
            $this->fixture->cipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlCourseTextProjection($f->sql),
            new \Infrastructure\Persistence\Repository\SqlSubjectRepository($f->sql),
        );
    }

    public function bindings(
        ?AuditTrail $audit = null,
    ): \Domain\Registration\Service\SectionCourseBindingService {
        $f = $this->sis;
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Registration\Service\SectionCourseBindingService(
            new SqlCatalogRevisionRepository($f->sql),
            $this->publications(),
            new \Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository($f->sql),
            $history,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->fixture->cipher,
            $f->clock,
        );
    }

    /** Explicit fictional publication choices for the pre-existing offering workflow tests. */
    public function publicationReview(OfferingService $service, EntityId $id): array
    {
        $versions = [];
        $byCourse = [];
        foreach ($service->preparePublication($id)["sections"] as $section) {
            if ($section["was_bound"]) {
                continue;
            }
            $course = new EntityId($section["course_id"]);
            if (!isset($byCourse[$course->value])) {
                $review = $this->publications()->review($course, "Fictional catalog approval");
                $byCourse[$course->value] = new EntityId(
                    $this->publications()->publish($course, $review["review_token"])["operation_id"],
                );
            }
            $versions[$section["id"]] = $byCourse[$course->value];
        }
        return $service->reviewPublication($id, $versions, "Fictional offering approval");
    }

    public function templates(?AuditTrail $audit = null): \Domain\Catalog\Service\OfferingTemplateService
    {
        $f = $this->sis;
        return new \Domain\Catalog\Service\OfferingTemplateService(
            new \Infrastructure\Persistence\Repository\SqlOfferingTemplateRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $this->operations,
            $f->registration,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $this->fixture->cipher,
            $f->clock,
        );
    }

    public function plan(): OfferingPlan
    {
        $section = $this->sis->section();
        return new OfferingPlan(
            $this->sis->term,
            new Section(
                EntityId::generate(),
                $section->courseId,
                $this->sis->term,
                null,
                "MATH.101.26FA.A",
                30,
                false,
                true,
                new Money(12500, "USD"),
            ),
        );
    }
}
