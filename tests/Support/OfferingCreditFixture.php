<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\ValueObject\CreditPolicy;
use Domain\Registration\Section;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlSectionClassificationRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use SisFixture;

final class OfferingCreditFixture
{
    public SisFixture $sis;
    public Section $section;
    public CatalogEditor $editor;
    public SectionPublicationService $publications;
    public SectionCourseBindingService $bindings;
    private SqlHistoricalEventStore $history;

    public function __construct(?SisFixture $sis = null, int $fee = 0)
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = $sis ?? new SisFixture();
        $this->section = $s->section(fee: $fee);
        $catalog = new SqlCatalogRevisionRepository($s->sql);
        $this->editor = new CatalogEditor($catalog, $s->registration, $s->access, $s->transactions, $s->audit);
        $this->history = $history = new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
        $relationships = new SqlCatalogWorkflowRepository($s->sql);
        $bound = new SqlSectionCourseBindingRepository($s->sql);
        $this->bindings = new SectionCourseBindingService(
            $catalog,
            $s->coursePublications(),
            $bound,
            $history,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock
        );
        $this->publications = new SectionPublicationService(
            $catalog,
            $relationships,
            $bound,
            $s->coursePublications(),
            $history,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            new SqlSectionVersionRepository($s->sql),
            new SqlSectionClassificationRepository($s->sql, new SqlClassificationRepository($s->sql)),
            $s->sectionContactHours(),
            new \Infrastructure\Persistence\Repository\SqlSectionProgramPolicyRepository($s->sql),
        );
    }

    public function range(): void
    {
        $this->editor->creditPolicy(
            $this->section->courseId,
            3000,
            new CreditPolicy(1000, 6000, 500),
            $this->editor->creditSettings('courses', $this->section->courseId)['revision']
        );
    }

    public function bind(): EntityId
    {
        $version = $this->sis->publishCourse($this->section->courseId);
        $review = $this->bindings->review($this->section->id, $version, 'Fictional credit policy binding.');
        $this->bindings->commit($this->section->id, $review['review_token']);
        return $version;
    }

    public function choose(?int $credits): void
    {
        $this->editor->sectionCredits(
            $this->section->id,
            $credits,
            $this->editor->creditSettings('sections', $this->section->id)['revision']
        );
    }

    public function publish(): EntityId
    {
        $term = $this->sis->sql->one('sis_terms', ['id' => $this->sis->term->value]);
        $review = $this->publications->review(
            $this->section->id,
            $term['starts_on'],
            $term['ends_on'],
            'Fictional offering credit decision.'
        );
        return new EntityId($this->publications->publish($this->section->id, $review['review_token'])['operation_id']);
    }

    public function select(EntityId $publication): void
    {
        $review = $this->publications->reviewSelection(
            $this->section->id,
            $publication,
            'Fictional accepted offering credits.'
        );
        $this->publications->select($this->section->id, $review['review_token']);
    }

    public function amend(EntityId $publication): void
    {
        $s = $this->sis;
        $service = new \Domain\Registration\Service\SectionOfferingAmendmentService(
            $this->publications,
            new SqlSectionVersionRepository($s->sql),
            new SqlCatalogRevisionRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository($s->sql),
            $s->registration,
            $this->history,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock
        );
        $review = $service->review($this->section->id, $publication, 'Fictional credits for future registrations.');
        $service->commit($this->section->id, $review['review_token']);
    }
}
