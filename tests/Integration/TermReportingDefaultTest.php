<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ClassificationService;
use Domain\Catalog\Service\TermReportingDefaultService;
use Domain\Registration\Service\SectionClassificationService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionClassificationRepository;
use Infrastructure\Persistence\Repository\SqlTermReportingDefaultRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TermReportingDefaultTest extends TestCase
{
    private function fixture(): CatalogWorkflowFixture
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        return new CatalogWorkflowFixture();
    }

    private function service(CatalogWorkflowFixture $c, ?AuditTrail $audit = null): TermReportingDefaultService
    {
        $f = $c->sis;
        return new TermReportingDefaultService(
            new SqlTermReportingDefaultRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            new SqlClassificationRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
    }

    private function references(CatalogWorkflowFixture $c): ClassificationService
    {
        $f = $c->sis;
        return new ClassificationService(
            new SqlClassificationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit
        );
    }

    private function group(CatalogWorkflowFixture $c, string $code = 'FA'): array
    {
        return $this->references($c)->save(
            ClassificationKind::ReportingGroup,
            null,
            $code,
            'Fictional ' . $code . ' reporting',
            true,
            null
        );
    }

    public function testDefaultsAreExplicitAuditedPresetsAndDoNotRewriteSectionsOrPublications(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $f->section();
        $review = $c->publications()->review($section->courseId, 'Fictional course approval');
        $course = $c->publications()->publish($section->courseId, $review['review_token']);
        $review = $c->bindings()->review($section->id, new EntityId($course['operation_id']), 'Fictional binding');
        $c->bindings()->commit($section->id, $review['review_token']);
        $publisher = $c->sectionPublications();
        $review = $publisher->review($section->id, '2026-08-01', '2026-08-31', 'Fictional offering');
        $publisher->publish($section->id, $review['review_token']);
        $before = [];
        foreach (
            ['sis_sections', 'sis_section_classifications', 'sis_section_versions',
            'sis_history_events', 'sis_enrollments', 'sis_academic_credits'] as $table
        ) {
            $before[$table] = $f->sql->rows($table);
        }
        $service = $this->service($c);
        $initial = $service->show($f->term);
        self::assertNull($initial['reporting_group']);
        self::assertSame([], $f->sql->rows('sis_term_reporting_defaults'));
        $group = $this->group($c);
        $saved = $service->save($f->term, new EntityId($group['id']), $initial['revision']);
        self::assertSame($group['id'], $saved['reporting_group']['id']);
        $service->save($f->term, null, $saved['revision']);
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $f->sql->rows($table));
        }
        self::assertCount(2, array_filter(
            $f->sql->rows('sis_audit'),
            static fn(array $row): bool => $row['action'] === 'term.reporting_default_saved'
        ));
    }

    public function testCopiedChoiceIsAnOrdinaryExplicitAssignmentAndSurvivesLaterDefaults(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $f->section()->id;
        $service = $this->service($c);
        $group = $this->group($c);
        $saved = $service->save($f->term, new EntityId($group['id']), $service->show($f->term)['revision']);
        $references = new SqlClassificationRepository($f->sql);
        $sections = new SectionClassificationService(
            new SqlSectionClassificationRepository($f->sql, $references),
            $references,
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit
        );
        self::assertNull($sections->show($section)['classifications']['reporting_group']);
        $sections->assign(
            $section,
            null,
            null,
            new EntityId($saved['reporting_group']['id']),
            $sections->show($section)['revision'],
        );
        $courseId = $f->registration->section($section)->courseId;
        $review = $c->publications()->review($courseId, 'Fictional course approval');
        $course = $c->publications()->publish($courseId, $review['review_token']);
        $review = $c->bindings()->review($section, new EntityId($course['operation_id']), 'Fictional binding');
        $c->bindings()->commit($section, $review['review_token']);
        $publisher = $c->sectionPublications();
        $review = $publisher->review($section, '2026-08-01', '2026-08-31', 'Fictional offering');
        $publication = $publisher->publish($section, $review['review_token']);
        $review = $publisher->reviewSelection(
            $section,
            new EntityId($publication['operation_id']),
            'Fictional selection',
        );
        $publisher->select($section, $review['review_token']);
        $f->service->register($f->student($f->user())->id, $section);
        $accepted = $f->sql->rows('sis_history_events');
        $other = $this->group($c, 'ALT');
        $service->save($f->term, new EntityId($other['id']), $saved['revision']);
        self::assertSame($group['id'], $sections->show($section)['classifications']['reporting_group']['id']);
        self::assertSame($accepted, $f->sql->rows('sis_history_events'));
        self::assertSame(
            $group['id'],
            $publisher->versions($section)['items'][0]['classifications']['reporting_group']['id'],
        );
        $sections->assign($section, null, null, null, $sections->show($section)['revision']);
        self::assertNull($sections->show($section)['classifications']['reporting_group']);
        self::assertSame($other['id'], $service->show($f->term)['reporting_group']['id']);
    }

    public function testStaleEditsAndChangedAwayThenBackDefaultsCannotOverwriteNewerSetup(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $service = $this->service($c);
        $old = $service->show($f->term);
        $group = $this->group($c);
        $saved = $service->save($f->term, new EntityId($group['id']), $old['revision']);
        $cleared = $service->save($f->term, null, $saved['revision']);
        self::assertNotSame($old['revision'], $cleared['revision']);
        $this->expectException(ConcurrentModificationException::class);
        $service->save($f->term, new EntityId($group['id']), $old['revision']);
    }

    public function testWrongCategoriesAndNewRetiredDefaultsAreRejectedButExistingRetiredCanBeCleared(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $service = $this->service($c);
        $group = $this->group($c);
        $references = $this->references($c);
        $saved = $service->save($f->term, new EntityId($group['id']), $service->show($f->term)['revision']);
        $references->save(
            ClassificationKind::ReportingGroup,
            new EntityId($group['id']),
            $group['code'],
            $group['title'],
            false,
            $group['revision']
        );
        try {
            $service->save($f->term, null, $saved['revision']);
            self::fail('Retirement should invalidate an older edit revision.');
        } catch (ConcurrentModificationException) {
            self::assertFalse((bool) $service->show($f->term)['reporting_group']['active']);
        }
        $service->save($f->term, null, $service->show($f->term)['revision']);
        $wrong = $references->save(ClassificationKind::Campus, null, 'MAIN', 'Fictional campus', true, null);
        foreach ([$wrong, $group] as $invalid) {
            try {
                $service->save($f->term, new EntityId($invalid['id']), $service->show($f->term)['revision']);
                self::fail('Invalid preset was accepted.');
            } catch (ValidationException) {
                self::assertNull($service->show($f->term)['reporting_group']);
            }
        }
    }

    public function testFinalAuditSourceAndAuthorityFailuresRollbackTheWholePresetWrite(): void
    {
        foreach (['audit', 'reference', 'default', 'term', 'authority'] as $change) {
            $c = $this->fixture();
            $f = $c->sis;
            $group = $this->group($c);
            $tables = ['sis_terms', 'sis_term_reporting_defaults', 'sis_catalog_classifications', 'sis_audit'];
            $before = array_map($f->sql->rows(...), $tables);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($f, $group, $change): void {
                match ($change) {
                    'audit' => throw new RuntimeException('Fictional audit failure'),
                    'reference' => $f->sql->update(
                        'sis_catalog_classifications',
                        ['active' => 0], ['id' => $group['id']],
                    ),
                    'default' => $f->sql->update(
                        'sis_term_reporting_defaults',
                        ['reporting_group_id' => null], ['id' => $f->term->value],
                    ),
                    'term' => $f->sql->update('sis_terms', ['code' => 'CHANGED'], ['id' => $f->term->value]),
                    'authority' => $f->access->staff = false,
                };
            });
            $service = $this->service($c, $audit);
            try {
                $service->save($f->term, new EntityId($group['id']), $service->show($f->term)['revision']);
                self::fail('Failed final check was accepted.');
            } catch (ConcurrentModificationException | AccessDeniedException | RuntimeException) {
                self::assertSame($before, array_map($f->sql->rows(...), $tables));
            }
        }
    }

    public function testUnprivilegedReadersCannotSeeOrChangeDefaults(): void
    {
        $c = $this->fixture();
        $c->sis->access->staff = false;
        $c->sis->access->grants = ['sis:registration:manage'];
        foreach (['read', 'write'] as $action) {
            try {
                $service = $this->service($c);
                $action === 'read' ? $service->show($c->sis->term) : $service->save($c->sis->term, null, '');
                self::fail('Registration duty acquired catalog setup.');
            } catch (AccessDeniedException) {
                self::assertSame([], $c->sis->sql->rows('sis_term_reporting_defaults'));
            }
        }
    }
}
