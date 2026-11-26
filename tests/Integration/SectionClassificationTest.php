<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use EventStoreFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ClassificationService;
use Domain\Registration\Service\SectionClassificationService;
use Domain\Registration\Service\SectionOfferingAmendmentService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SectionClassificationTest extends TestCase
{
    private function fixture(): CatalogWorkflowFixture
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
        return new CatalogWorkflowFixture();
    }

    private function references(
        CatalogWorkflowFixture $c,
    ): ClassificationService {
        $f = $c->sis;
        return new ClassificationService(
            new SqlClassificationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }

    private function sections(
        CatalogWorkflowFixture $c,
        ?AuditTrail $audit = null,
    ): SectionClassificationService {
        $f = $c->sis;
        $references = new SqlClassificationRepository($f->sql);
        return new SectionClassificationService(
            new SqlSectionClassificationRepository($f->sql, $references),
            $references,
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
    }

    private function definition(
        CatalogWorkflowFixture $c,
        ClassificationKind $kind,
        string $code,
        string $title,
    ): array {
        return $this->references($c)->save(
            $kind,
            null,
            $code,
            $title,
            true,
            null,
        );
    }

    private function assign(
        CatalogWorkflowFixture $c,
        EntityId $section,
        ?array $method = null,
        ?array $campus = null,
        ?array $group = null,
    ): void {
        $service = $this->sections($c);
        $service->assign(
            $section,
            $method === null ? null : new EntityId($method["id"]),
            $campus === null ? null : new EntityId($campus["id"]),
            $group === null ? null : new EntityId($group["id"]),
            $service->show($section)["revision"],
        );
    }

    private function bound(CatalogWorkflowFixture $c): EntityId
    {
        $section = $c->sis->section();
        $review = $c
            ->publications()
            ->review($section->courseId, "Fictional course approval.");
        $course = $c
            ->publications()
            ->publish($section->courseId, $review["review_token"]);
        $review = $c
            ->bindings()
            ->review(
                $section->id,
                new EntityId($course["operation_id"]),
                "Fictional course binding.",
            );
        $c->bindings()->commit($section->id, $review["review_token"]);
        return $section->id;
    }

    private function publish(
        CatalogWorkflowFixture $c,
        EntityId $section,
    ): array {
        $service = $c->sectionPublications();
        $review = $service->review(
            $section,
            "2026-08-01",
            "2026-08-31",
            "Fictional offering approval.",
        );
        return $service->publish($section, $review["review_token"]);
    }

    private function select(
        CatalogWorkflowFixture $c,
        EntityId $section,
        array $publication,
    ): void {
        $service = $c->sectionPublications();
        $review = $service->reviewSelection(
            $section,
            new EntityId($publication["operation_id"]),
            "Fictional selection.",
        );
        $service->select($section, $review["review_token"]);
    }

    private function amendments(CatalogWorkflowFixture $c): SectionOfferingAmendmentService
    {
        $f = $c->sis;
        return new SectionOfferingAmendmentService(
            $c->sectionPublications(),
            new SqlSectionVersionRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            new \Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository(
                $f->sql,
            ),
            $f->registration,
            EventStoreFixture::history($f->connection->queryBuilder()),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
    }

    private function reports(CatalogWorkflowFixture $c): \Domain\Reporting\Service\RelationalReportService
    {
        $f = $c->sis;
        $h = $c->fixture;
        return new \Domain\Reporting\Service\RelationalReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository(
                $f->sql,
                $f->clock,
            ),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository(
                $f->sql,
            ),
            new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $h->cipher,
            $f->clock,
            $f->reportLifecycles(cipher: $h->cipher),
        );
    }

    private function groupQuery(string $dataset = 'credits', string $field = 'retained_reporting_group_title'): array
    {
        return ['dataset' => $dataset,
            'columns' => [['field' => $field, 'label' => 'Reporting group', 'aggregate' => 'none']],
            'joins' => [], 'filters' => null, 'having' => null, 'distinct' => false, 'sort' => []];
    }

    public function testReferenceSetupAndOptionalSectionAssignmentsAreAuditedCrudWithoutAcademicEvents(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $f->section()->id;
        $before = $f->sql->rows("sis_history_events");
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LAB",
            "Laboratory",
        );
        $campus = $this->definition(
            $c,
            ClassificationKind::Campus,
            "EAST",
            "Fictional East Campus",
        );
        self::assertSame(
            ["instructional_method" => null, "campus" => null,
                "reporting_group" => null, 'reporting_group_ancestors' => []],
            $this->sections($c)->show($section)["classifications"],
        );
        $this->assign($c, $section, $method, $campus);
        $saved = $this->sections($c)->show($section);
        self::assertSame(
            $method["id"],
            $saved["classifications"]["instructional_method"]["id"],
        );
        self::assertSame(
            $campus["id"],
            $saved["classifications"]["campus"]["id"],
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertCount(
            1,
            $this->references($c)->page(
                ClassificationKind::InstructionalMethod,
            )["items"],
        );
        $this->assign($c, $section);
        self::assertNull(
            $this->sections($c)->show($section)["classifications"]["campus"],
        );
    }

    public function testAssignmentRejectsWrongCategoryNewRetiredChoicesAndStaleOrRenamedDrafts(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $f->section()->id;
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LEC",
            "Lecture",
        );
        $campus = $this->definition(
            $c,
            ClassificationKind::Campus,
            "NORTH",
            "Fictional North Site",
        );
        $old = $this->sections($c)->show($section)["revision"];
        try {
            $this->assign($c, $section, $campus);
            self::fail("A campus was accepted as an instructional method.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_section_classifications"));
        }
        $retired = $this->references($c)->save(
            ClassificationKind::InstructionalMethod,
            new EntityId($method["id"]),
            $method["code"],
            $method["title"],
            false,
            $method["revision"],
        );
        try {
            $this->assign($c, $section, $retired);
            self::fail("A newly assigned retired method was accepted.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_section_classifications"));
        }
        $this->assign($c, $section, campus: $campus);
        try {
            $this->sections($c)->assign($section, null, null, null, $old);
            self::fail("An older draft overwrote newer choices.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $campus["id"],
                $this->sections($c)->show($section)["classifications"][
                    "campus"
                ]["id"],
            );
        }
        $old = $this->sections($c)->show($section)["revision"];
        $this->references($c)->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "NORTH",
            "Fictional renamed site",
            true,
            $campus["revision"],
        );
        $this->expectException(ConcurrentModificationException::class);
        $this->sections($c)->assign($section, null, null, null, $old);
    }

    public function testRetiringAnAssignedReferenceDoesNotEraseItOrPreventAnUnchangedAssignment(): void
    {
        $c = $this->fixture();
        $section = $c->sis->section()->id;
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "SEM",
            "Seminar",
        );
        $this->assign($c, $section, $method);
        $this->references($c)->save(
            ClassificationKind::InstructionalMethod,
            new EntityId($method["id"]),
            $method["code"],
            $method["title"],
            false,
            $method["revision"],
        );
        $this->assign($c, $section, $method);
        self::assertSame(
            $method["id"],
            $this->sections($c)->show($section)["classifications"][
                "instructional_method"
            ]["id"],
        );
    }

    public function testAssignmentRequiresCurrentCatalogAuthorityAndRollsBackWhenAuditFails(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $f->section()->id;
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LEC",
            "Lecture",
        );
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(
                new RuntimeException("Fictional audit interruption."),
            );
        $service = $this->sections($c, $audit);
        try {
            $service->assign(
                $section,
                new EntityId($method["id"]),
                null,
                null,
                $service->show($section)["revision"],
            );
            self::fail("An unaudited assignment survived.");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit interruption.", $e->getMessage());
            self::assertSame([], $f->sql->rows("sis_section_classifications"));
        }
        $f->access->staff = false;
        $f->access->grants = ["sis:registration:manage"];
        $this->expectException(AccessDeniedException::class);
        $this->sections($c)->show($section);
    }

    public function testPublicationRetainsSnapshotsAcrossRenamingWithoutChangingSelectedFingerprint(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LEC",
            "Lecture",
        );
        $campus = $this->definition(
            $c,
            ClassificationKind::Campus,
            "MAIN",
            "Fictional Main Campus",
        );
        $this->assign($c, $section, $method, $campus);
        $pub = $this->publish($c, $section);
        $this->select($c, $section, $pub);
        $cache = new SqlSectionVersionRepository($f->sql);
        $before = $cache->selected($section);
        $events = $f->sql->rows("sis_history_events");
        $this->references($c)->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "MAIN",
            "Fictional Campus Renamed",
            true,
            $campus["revision"],
        );
        self::assertSame(
            $before->fingerprint(),
            $cache->selected($section)->fingerprint(),
        );
        self::assertSame(
            "Fictional Main Campus",
            $cache->selected($section)->classifications->campus->title,
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $page = $c->sectionPublications()->versions($section);
        self::assertSame(
            "Lecture",
            $page["items"][0]["classifications"]["instructional_method"][
                "title"
            ],
        );
        self::assertSame(
            "Fictional Main Campus",
            $page["items"][0]["classifications"]["campus"]["title"],
        );
        $student = $f->student();
        $enrollment = $f->service->register($student->id, $section);
        self::assertSame(
            $pub["operation_id"],
            $cache->enrollmentVersion($enrollment->id)->value,
        );
    }

    public function testChangingDraftAssignmentsOrReferenceLabelsInvalidatesPublicationReview(): void
    {
        foreach (["assignment", "label"] as $change) {
            $c = $this->fixture();
            $f = $c->sis;
            $section = $this->bound($c);
            $method = $this->definition(
                $c,
                ClassificationKind::InstructionalMethod,
                "LAB",
                "Laboratory",
            );
            $this->assign($c, $section, $method);
            $review = $c
                ->sectionPublications()
                ->review(
                    $section,
                    "2026-08-01",
                    "2026-08-31",
                    "Fictional review.",
                );
            if ($change === "assignment") {
                $this->assign($c, $section);
            } else {
                $this->references($c)->save(
                    ClassificationKind::InstructionalMethod,
                    new EntityId($method["id"]),
                    "LAB",
                    "Fictional laboratory renamed",
                    true,
                    $method["revision"],
                );
            }
            $before = $f->sql->rows("sis_history_events");
            try {
                $c->sectionPublications()->publish(
                    $section,
                    $review["review_token"],
                );
                self::fail(
                    "Changed classifications were published under an earlier review.",
                );
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testEarlierUnknownPublicationIsNotDecoratedFromCurrentSectionOrRecachedWithInventedFacts(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $service = $c->sectionPublications();
        $review = $service->review(
            $section,
            "2026-08-01",
            "2026-08-31",
            "Fictional earlier publication.",
        );
        $op = new EntityId($review["operation_id"]);
        $stream = new StreamReference(
            new \Domain\Registration\ValueObject\SectionPublicationId(
                $section->value,
            ),
            "section_publication",
        );
        $event = \Domain\Registration\Event\SectionDefinitionWasPublished::occur(
            $stream->aggregateId,
            [
                "version_id" => $op->value,
                "previous_version_id" => null,
                "definition" => $review["definition"],
            ],
            ["__aggregate_type" => "section_publication"],
        )->withPlayhead(1);
        $f->transactions->run(function () use ($f, $stream, $op, $event): void {
            EventStoreFixture::history($f->connection->queryBuilder())->append(
                $stream,
                $op,
                0,
                new EventContext(
                    $f->access->actor,
                    $f->clock->now(),
                    $op,
                    reason: "Fictional earlier publication.",
                ),
                [$event],
            );
        });
        $cache = new SqlSectionVersionRepository($f->sql);
        $old = $service->cacheVersion($section, $op);
        self::assertNull($old->classifications);
        $hash = $old->fingerprint();
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LEC",
            "Lecture",
        );
        $this->assign($c, $section, $method);
        $events = $f->sql->rows("sis_history_events");
        self::assertSame(
            $hash,
            $service->cacheVersion($section, $op)->fingerprint(),
        );
        self::assertNull(
            $service->versions($section)["items"][0]["classifications"],
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $this->expectException(ConcurrentModificationException::class);
        $service->reviewSelection(
            $section,
            $op,
            "Fictional mismatched selection.",
        );
    }

    public function testNewRegistrationStopsOnChangedAssignmentWhileEarlierRetryKeepsItsOriginalReference(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $first = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LEC",
            "Lecture",
        );
        $second = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LAB",
            "Laboratory",
        );
        $this->assign($c, $section, $first);
        $pub = $this->publish($c, $section);
        $this->select($c, $section, $pub);
        $student = $f->student();
        $enrollment = $f->service->register($student->id, $section);
        $this->assign($c, $section, $second);
        self::assertSame(
            $enrollment->id->value,
            $f->service->register($student->id, $section)->id->value,
        );
        $other = $f->student();
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->service->register($other->id, $section);
            self::fail("Registration accepted an unreviewed offering change.");
        } catch (\DomainException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertCount(1, $f->sql->rows("sis_enrollments"));
        }
        $next = $this->publish($c, $section);
        $amend = $this->amendments($c);
        $review = $amend->review(
            $section,
            new EntityId($next["operation_id"]),
            "Fictional change for future students.",
        );
        $amend->commit($section, $review["review_token"]);
        $newEnrollment = $f->service->register($other->id, $section);
        $cache = new SqlSectionVersionRepository($f->sql);
        self::assertSame(
            $pub["operation_id"],
            $cache->enrollmentVersion($enrollment->id)->value,
        );
        self::assertSame(
            $next["operation_id"],
            $cache->enrollmentVersion($newEnrollment->id)->value,
        );
    }

    public function testReportsUseRetainedOfferingLabelsForCreditsAndCurrentLabelsForSectionSetup(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $campus = $this->definition(
            $c,
            ClassificationKind::Campus,
            "EAST",
            "Fictional Original Campus",
        );
        $this->assign($c, $section, campus: $campus);
        $pub = $this->publish($c, $section);
        $this->select($c, $section, $pub);
        $f->service->register($f->student()->id, $section);
        $this->references($c)->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "EAST",
            "Fictional Renamed Campus",
            true,
            $campus["revision"],
        );
        $reports = $this->reports($c);
        $query = [
            "dataset" => "credits",
            "columns" => [
                [
                    "field" => "retained_campus_title",
                    "label" => "Campus",
                    "aggregate" => "none",
                ],
            ],
            "joins" => [],
            "filters" => null,
            "having" => null,
            "distinct" => false,
            "sort" => [],
        ];
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(
            "Fictional Original Campus",
            $reports->preview($query, 0)["items"][0]["c0"],
        );
        $query["dataset"] = "sections";
        $query["columns"][0]["field"] = "current_campus_title";
        self::assertSame(
            "Fictional Renamed Campus",
            $reports->preview($query, 0)["items"][0]["c0"],
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
    }

    public function testAcceptedPublicationRetryKeepsItsOriginalClassificationsAfterLaterDraftChanges(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $method = $this->definition(
            $c,
            ClassificationKind::InstructionalMethod,
            "LEC",
            "Lecture",
        );
        $this->assign($c, $section, $method);
        $service = $c->sectionPublications();
        $review = $service->review(
            $section,
            "2026-08-01",
            "2026-08-31",
            "Fictional approval.",
        );
        $receipt = $service->publish($section, $review["review_token"]);
        $this->assign($c, $section);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(
            $receipt,
            $service->publish($section, $review["review_token"]),
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame(
            $method["id"],
            new SqlSectionVersionRepository($f->sql)->find(
                new EntityId($receipt["operation_id"]),
            )->classifications->instructionalMethod->id->value,
        );
    }

    public function testOfferingPlanIncludesClassificationsInReviewAndAtomicActivation(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $plan = $c->plan();
        $offerings = $c->offerings();
        $review = $offerings->review($plan);
        $op = new EntityId($review["operation_id"]);
        $offerings->save($op, $plan, $review["revision"]);
        $section = $plan->sections[0]->id;
        $campus = $this->definition(
            $c,
            ClassificationKind::Campus,
            "WEST",
            "Fictional West Campus",
        );
        $this->assign($c, $section, campus: $campus);
        $proof = $c->publicationReview($offerings, $op);
        $this->references($c)->save(
            ClassificationKind::Campus,
            new EntityId($campus["id"]),
            "WEST",
            "Fictional West Site",
            true,
            $campus["revision"],
        );
        $before = $f->sql->rows("sis_history_events");
        try {
            $offerings->publish($op, $proof["review_token"]);
            self::fail(
                "An offering plan ignored changed reviewed campus labels.",
            );
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
            self::assertFalse($f->registration->section($section)->active);
        }
        $proof = $c->publicationReview($offerings, $op);
        $offerings->publish($op, $proof["review_token"]);
        self::assertSame(
            "Fictional West Site",
            new SqlSectionVersionRepository($f->sql)->selected($section)
                ->classifications->campus->title,
        );
        self::assertTrue($f->registration->section($section)->active);
    }

    public function testMissingKnownClassificationCacheIsRejectedRatherThanBecomingUnknownHistory(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $pub = $this->publish($c, $section);
        $f->sql->execute(
            "DELETE FROM sis_section_version_classes WHERE id = ?",
            [$pub["operation_id"]],
        );
        $this->expectException(CorruptHistoryException::class);
        new SqlSectionVersionRepository($f->sql)->find(
            new EntityId($pub["operation_id"]),
        );
    }
    public function testReportingGroupsPreserveOriginalAttemptsAcrossRenamesAndReviewedAmendments(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $group = $this->definition($c, ClassificationKind::ReportingGroup, '2026-FA', 'Fictional fall reporting');
        $this->assign($c, $section, group: $group);
        $service = $c->sectionPublications();
        $r = $service->review($section, '2026-08-01', '2026-08-31', 'Fictional reporting-group approval');
        $pub = $service->publish($section, $r['review_token']);
        $this->select($c, $section, $pub);
        $student = $f->student();
        $enrollment = $f->service->register($student->id, $section);
        $source = new SqlSectionVersionRepository($f->sql);
        $retained = $source->selected($section);
        self::assertTrue($retained->classifications->reportingGroupRecorded);
        self::assertSame('Fictional fall reporting', $retained->classifications->reportingGroup->title);
        $before = $f->sql->rows('sis_history_events');
        $this->references($c)->save(
            ClassificationKind::ReportingGroup,
            new EntityId($group['id']),
            '2026-FA',
            'Renamed current reporting group',
            true,
            $group['revision']
        );
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        self::assertSame(
            'Fictional fall reporting',
            $this->reports($c)->preview($this->groupQuery(), 0)['items'][0]['c0']
        );
        self::assertSame('Renamed current reporting group', $this->reports($c)->preview(
            $this->groupQuery('sections', 'current_reporting_group_title'),
            0
        )['items'][0]['c0']);
        $replacement = $this->definition(
            $c,
            ClassificationKind::ReportingGroup,
            '2026-ALT',
            'Different reporting basis'
        );
        $this->assign($c, $section, group: $replacement);
        self::assertSame($pub, $service->publish($section, $r['review_token']));
        $other = $f->student($f->user());
        try {
            $f->service->register($other->id, $section);
            self::fail('Registration ignored a changed reporting group.');
        } catch (\DomainException) {
            self::assertCount(1, $f->sql->rows('sis_enrollments'));
        }
        $next = $this->publish($c, $section);
        $amend = $this->amendments($c);
        $review = $amend->review(
            $section,
            new EntityId($next['operation_id']),
            'Fictional future registration grouping'
        );
        $amend->commit($section, $review['review_token']);
        $new = $f->service->register($other->id, $section);
        self::assertSame($pub['operation_id'], $source->enrollmentVersion($enrollment->id)->value);
        self::assertSame($next['operation_id'], $source->enrollmentVersion($new->id)->value);
        $labels = array_column($this->reports($c)->preview($this->groupQuery(), 0)['items'], 'c0');
        sort($labels);
        self::assertSame(['Different reporting basis', 'Fictional fall reporting'], $labels);
        foreach ($f->sql->rows('sis_enrollments') as $row) {
            self::assertSame($f->term->value, $row['term_id']);
        }
        self::assertSame(1, (int) $this->reports($c)->preview(
            $this->groupQuery('credits', 'retained_reporting_group_recorded'),
            0
        )['items'][0]['c0']);
    }

    public function testRelatedSectionsShareAReportingGroupWithoutChangingTheirInstructionalTerms(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $group = $this->definition($c, ClassificationKind::ReportingGroup, 'FA-2026', 'Fictional fall group');
        $terms = [];
        foreach (['A', 'B'] as $suffix) {
            $section = $this->bound($c);
            $term = EntityId::generate();
            $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => '26/SHORT-' . $suffix,
                'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'add_drop_ends_on' => '2026-08-15']);
            $window = $f->sql->rows('sis_reg_windows', ['term_id' => $f->term->value], 1)[0];
            $window['term_id'] = $term->value;
            $window['id'] = EntityId::generate()->value;
            $f->sql->insert('sis_reg_windows', $window);
            $f->sql->update('sis_sections', ['term_id' => $term->value], ['id' => $section->value]);
            $this->assign($c, $section, group: $group);
            $publication = $this->publish($c, $section);
            $this->select($c, $section, $publication);
            $f->service->register($f->student($f->user())->id, $section);
            $terms[] = $term->value;
        }
        self::assertSame(
            ['Fictional fall group', 'Fictional fall group'],
            array_column($this->reports($c)->preview($this->groupQuery(), 0)['items'], 'c0')
        );
        $actual = array_column($f->sql->rows('sis_enrollments'), 'term_id');
        sort($terms);
        sort($actual);
        self::assertSame($terms, $actual);
        $query = $this->groupQuery();
        $query['columns'][] = ['field' => 'student_id', 'label' => 'Students', 'aggregate' => 'count_distinct'];
        self::assertSame(2, (int) $this->reports($c)->preview($query, 0)['items'][0]['c1']);
    }

    public function testReportingGroupChoicesEnforceCategoryRetirementAndExplicitClearing(): void
    {
        $c = $this->fixture();
        $section = $c->sis->section()->id;
        $group = $this->definition($c, ClassificationKind::ReportingGroup, 'FA', 'Fall reporting');
        $this->assign($c, $section, group: $group);
        $this->references($c)->save(
            ClassificationKind::ReportingGroup,
            new EntityId($group['id']),
            'FA',
            'Fall reporting',
            false,
            $group['revision']
        );
        $this->assign($c, $section, group: $group);
        self::assertSame($group['id'], $this->sections($c)->show($section)['classifications']['reporting_group']['id']);
        $this->assign($c, $section);
        self::assertNull($this->sections($c)->show($section)['classifications']['reporting_group']);
        $wrong = $this->definition($c, ClassificationKind::Campus, 'MAIN', 'Campus');
        foreach ([$group, $wrong] as $invalid) {
            try {
                $this->assign($c, $section, group: $invalid);
                self::fail('An invalid reporting-group reference was assigned.');
            } catch (ValidationException) {
                self::assertNull($this->sections($c)->show($section)['classifications']['reporting_group']);
            }
        }
    }

    public function testReportingGroupPublicationReviewsBindTheExactReviewedReference(): void
    {
        foreach (['assignment', 'label'] as $change) {
            $c = $this->fixture();
            $section = $this->bound($c);
            $group = $this->definition($c, ClassificationKind::ReportingGroup, 'FA', 'Fall group');
            $this->assign($c, $section, group: $group);
            $service = $c->sectionPublications();
            $r = $service->review($section, '2026-08-01', '2026-08-31', 'Fictional grouping review');
            if ($change === 'assignment') {
                $this->assign($c, $section);
            } else {
                $this->references($c)->save(
                    ClassificationKind::ReportingGroup,
                    new EntityId($group['id']),
                    'FA',
                    'Changed label',
                    true,
                    $group['revision']
                );
            }
            $before = $c->sis->sql->rows('sis_history_events');
            try {
                $service->publish($section, $r['review_token']);
                self::fail('Changed reporting-group source used an old review.');
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $c->sis->sql->rows('sis_history_events'));
            }
        }
    }

    public function testReportingGroupAssignmentRejectsFinalSourceChangesAndRollsBackRequiredAudit(): void
    {
        foreach (['reference', 'assignment', 'authority', 'audit'] as $change) {
            $c = $this->fixture();
            $f = $c->sis;
            $section = $f->section()->id;
            $group = $this->definition($c, ClassificationKind::ReportingGroup, 'FA', 'Fall group');
            $tables = ['sis_section_classifications', 'sis_catalog_classifications', 'sis_audit'];
            $before = array_map($f->sql->rows(...), $tables);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($f, $section, $group, $change): void {
                if ($change === 'audit') {
                    throw new RuntimeException('Fictional audit failure');
                }
                if ($change === 'authority') {
                    $f->access->staff = false;
                }
                if ($change === 'reference') {
                    $f->sql->update('sis_catalog_classifications', ['active' => 0], ['id' => $group['id']]);
                }
                if ($change === 'assignment') {
                    $f->sql->update(
                        'sis_section_classifications',
                        ['reporting_group_id' => null],
                        ['id' => $section->value]
                    );
                }
            });
            $service = $this->sections($c, $audit);
            try {
                $service->assign(
                    $section,
                    null,
                    null,
                    new EntityId($group['id']),
                    $service->show($section)['revision']
                );
                self::fail('A failed final reporting-group check was accepted.');
            } catch (ConcurrentModificationException | AccessDeniedException | RuntimeException) {
                self::assertSame($before, array_map($f->sql->rows(...), $tables));
            }
        }
    }

    public static function earlierClassifications(): array
    {
        return [
            'group unknown' => [['instructional_method' => null, 'campus' => null]],
            'hierarchy unknown' => [['instructional_method' => null, 'campus' => null, 'reporting_group' => null]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('earlierClassifications')]
    public function testEarlierPublicationsRetainUnknownRelationships(array $oldClasses): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $service = $c->sectionPublications();
        $review = $service->review($section, '2026-08-01', '2026-08-31', 'Fictional earlier definition');
        $operation = new EntityId($review['operation_id']);
        $stream = new StreamReference(
            new \Domain\Registration\ValueObject\SectionPublicationId($section->value),
            'section_publication'
        );
        $event = \Domain\Registration\Event\SectionDefinitionWasPublished::occur(
            $stream->aggregateId,
            ['version_id' => $operation->value, 'previous_version_id' => null, 'definition' => $review['definition']],
            ['__aggregate_type' => 'section_publication', 'offering_classifications' => $oldClasses]
        )->withPlayhead(1);
        $f->transactions->run(function () use ($f, $stream, $operation, $event): void {
            EventStoreFixture::history($f->connection->queryBuilder())->append(
                $stream,
                $operation,
                0,
                new EventContext(
                    $f->access->actor,
                    $f->clock->now(),
                    $operation,
                    reason: 'Fictional earlier definition'
                ),
                [$event]
            );
        });
        $old = $service->cacheVersion($section, $operation);
        self::assertSame(
            array_key_exists('reporting_group', $oldClasses),
            $old->classifications->reportingGroupRecorded,
        );
        self::assertNull($old->classifications->reportingAncestors);
        self::assertSame($oldClasses, $old->classifications->describe());
        $source = new SqlSectionVersionRepository($f->sql);
        self::assertSame($old->fingerprint(), $source->find($operation)->fingerprint());
        $before = $f->sql->rows('sis_history_events');
        $group = $this->definition($c, ClassificationKind::ReportingGroup, 'FA', 'Fall group');
        $this->assign($c, $section, group: $group);
        self::assertSame($old->fingerprint(), $service->cacheVersion($section, $operation)->fingerprint());
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $next = $this->publish($c, $section);
        self::assertTrue($source->find(new EntityId($next['operation_id']))->classifications->reportingGroupRecorded);
    }

    public function testMalformedReportingGroupReferencesFailClosedAndExplicitBlankStaysDistinct(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $section = $this->bound($c);
        $empty = $this->publish($c, $section);
        $source = new SqlSectionVersionRepository($f->sql);
        $blank = $source->find(new EntityId($empty['operation_id']));
        self::assertTrue($blank->classifications->reportingGroupRecorded);
        self::assertNull($blank->classifications->reportingGroup);
        $group = $this->definition($c, ClassificationKind::ReportingGroup, 'FA', 'Fall group');
        $this->assign($c, $section, group: $group);
        $pub = $this->publish($c, $section);
        foreach (['reporting_group_recorded' => 0, 'reporting_group_code' => null] as $field => $value) {
            $row = $f->sql->rows('sis_section_version_classes', ['id' => $pub['operation_id']], 1)[0];
            $f->sql->update('sis_section_version_classes', [$field => $value], ['id' => $pub['operation_id']]);
            try {
                $source->find(new EntityId($pub['operation_id']));
                self::fail('Malformed retained reporting-group evidence was accepted.');
            } catch (CorruptHistoryException) {
                self::assertSame(
                    $value,
                    $f->sql->rows('sis_section_version_classes', ['id' => $pub['operation_id']], 1)[0][$field]
                );
            }
            $f->sql->update('sis_section_version_classes', [$field => $row[$field]], ['id' => $pub['operation_id']]);
        }
    }
}
