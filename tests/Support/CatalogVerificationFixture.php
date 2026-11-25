<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use Infrastructure\Projection\SqlCatalogProjectionVerifier;

final class CatalogVerificationFixture
{
    public readonly \SisFixture $sis;
    public readonly EntityId $course;
    public readonly EntityId $section;
    public readonly EntityId $courseVersion;
    public readonly EntityId $sectionVersion;

    public function __construct(?\SisFixture $sis = null, bool $select = true)
    {
        require_once __DIR__ . '/SisFixture.php';
        $s = $this->sis = $sis ?? new \SisFixture();
        $section = $s->section();
        $this->course = $section->courseId;
        $this->section = $section->id;
        $this->courseVersion = $s->publishCourse($this->course);
        $bindings = new SectionCourseBindingService(
            new SqlCatalogRevisionRepository($s->sql),
            $s->coursePublications(),
            new SqlSectionCourseBindingRepository($s->sql),
            $this->history(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock
        );
        $bindings->commit($this->section, $bindings->review(
            $this->section,
            $this->courseVersion,
            'Fictional retained course.'
        )['review_token']);
        $service = $this->publications();
        $this->sectionVersion = new EntityId($service->publish(
            $this->section,
            $service->review(
                $this->section,
                '2026-08-24',
                '2026-12-18',
                'Fictional retained section.'
            )['review_token']
        )['operation_id']);
        if ($select) {
            $service->select($this->section, $service->reviewSelection(
                $this->section,
                $this->sectionVersion,
                'Fictional selected offering.'
            )['review_token']);
        }
    }

    public function history(): HistoricalEventStore
    {
        return \EventStoreFixture::history($this->sis->connection->queryBuilder());
    }

    public function verifier(?HistoricalEventStore $history = null): SqlCatalogProjectionVerifier
    {
        $s = $this->sis;
        return new SqlCatalogProjectionVerifier(
            $s->sql,
            $history ?? $this->history(),
            new SqlCourseVersionProjection($s->sql),
            new SqlSectionVersionRepository($s->sql),
            new SqlSectionCourseBindingRepository($s->sql)
        );
    }

    public function publications(): SectionPublicationService
    {
        $s = $this->sis;
        return new SectionPublicationService(
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogWorkflowRepository($s->sql),
            new SqlSectionCourseBindingRepository($s->sql),
            $s->coursePublications(),
            $this->history(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            new SqlSectionVersionRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlSectionClassificationRepository(
                $s->sql,
                new \Infrastructure\Persistence\Repository\SqlClassificationRepository($s->sql)
            ),
            $s->sectionContactHours()
        );
    }

    public function facts(): array
    {
        $s = $this->sis;
        return [\EventStoreFixture::historyDigest($s->sql), ...array_map($s->sql->rows(...), [
            'sis_history_streams', 'sis_history_operations', 'sis_course_versions', 'sis_section_versions',
            'sis_section_course_versions', 'sis_section_offering_versions', 'sis_enrollments',
            'sis_academic_credits', 'sis_account_entries', 'sis_history_checks', 'sis_audit',
        ])];
    }
}
