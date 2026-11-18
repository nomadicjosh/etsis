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
    ): void {
        $service = $this->sections($c);
        $service->assign(
            $section,
            $method === null ? null : new EntityId($method["id"]),
            $campus === null ? null : new EntityId($campus["id"]),
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
            ["instructional_method" => null, "campus" => null],
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
            $this->sections($c)->assign($section, null, null, $old);
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
        $this->sections($c)->assign($section, null, null, $old);
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
        $amend = new SectionOfferingAmendmentService(
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
        $h = $c->fixture;
        $reports = new \Domain\Reporting\Service\RelationalReportService(
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
}
