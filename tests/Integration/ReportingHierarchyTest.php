<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Service\ClassificationService;
use Domain\Catalog\Service\ReportingHierarchyReader;
use Domain\Catalog\Service\ReportingHierarchyService;
use Domain\Registration\Service\SectionClassificationService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlClassificationRepository;
use Infrastructure\Persistence\Repository\SqlReportingHierarchyRepository;
use Infrastructure\Persistence\Repository\SqlSectionClassificationRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportingHierarchyTest extends TestCase
{
    private function fixture(): CatalogWorkflowFixture
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
        return new CatalogWorkflowFixture();
    }

    private function references(CatalogWorkflowFixture $c): ClassificationService
    {
        $f = $c->sis;
        return new ClassificationService(
            new SqlClassificationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }

    private function group(CatalogWorkflowFixture $c, string $code): EntityId
    {
        return new EntityId(
            $this->references($c)->save(
                ClassificationKind::ReportingGroup,
                null,
                $code,
                "Fictional " . $code,
                true,
                null,
            )["id"],
        );
    }

    private function service(CatalogWorkflowFixture $c, ?AuditTrail $audit = null): ReportingHierarchyService
    {
        $f = $c->sis;
        $repo = new SqlReportingHierarchyRepository($f->sql);
        $references = new SqlClassificationRepository($f->sql);
        return new ReportingHierarchyService(
            $repo,
            $references,
            new ReportingHierarchyReader($repo, $references),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
    }

    private function parent(CatalogWorkflowFixture $c, EntityId $group, ?EntityId $parent): array
    {
        $service = $this->service($c);
        return $service->save($group, $parent, $service->show($group)["revision"]);
    }

    private function sections(CatalogWorkflowFixture $c): SectionClassificationService
    {
        $f = $c->sis;
        $references = new SqlClassificationRepository($f->sql);
        return new SectionClassificationService(
            new SqlSectionClassificationRepository($f->sql, $references),
            $references,
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }

    private function publish(CatalogWorkflowFixture $c, EntityId $group): array
    {
        $section = $c->sis->section();
        $sections = $this->sections($c);
        $sections->assign($section->id, null, null, $group, $sections->show($section->id)["revision"]);
        $review = $c->publications()->review($section->courseId, "Fictional approval");
        $course = $c->publications()->publish($section->courseId, $review["review_token"]);
        $review = $c
            ->bindings()
            ->review($section->id, new EntityId($course["operation_id"]), "Fictional binding");
        $c->bindings()->commit($section->id, $review["review_token"]);
        $publisher = $c->sectionPublications();
        $review = $publisher->review($section->id, "2026-08-01", "2026-08-31", "Fictional offering");
        $publication = $publisher->publish($section->id, $review["review_token"]);
        $review = $publisher->reviewSelection(
            $section->id,
            new EntityId($publication["operation_id"]),
            "Fictional selection",
        );
        $publisher->select($section->id, $review["review_token"]);
        return [$section->id, $publication];
    }

    public function testExplicitParentsRetainOrderedPathsAndClearWithoutWritingAcademicHistory(): void
    {
        $c = $this->fixture();
        $root = $this->group($c, "YEAR");
        $parent = $this->group($c, "FALL");
        $leaf = $this->group($c, "SHORT-A");
        $before = $c->sis->sql->rows("sis_history_events");
        $this->parent($c, $parent, $root);
        $saved = $this->parent($c, $leaf, $parent);
        self::assertSame([$leaf->value, $parent->value, $root->value], array_column($saved["path"], "id"));
        self::assertSame($parent->value, $saved["parent"]["id"]);
        $cleared = $this->parent($c, $leaf, null);
        self::assertSame([$leaf->value], array_column($cleared["path"], "id"));
        self::assertNull($cleared["parent"]);
        self::assertSame($before, $c->sis->sql->rows("sis_history_events"));
    }

    public function testCyclesWrongKindsAndRetiredNewParentsAreRejectedWithoutPartialChanges(): void
    {
        $c = $this->fixture();
        $a = $this->group($c, "A");
        $b = $this->group($c, "B");
        $this->parent($c, $b, $a);
        $references = $this->references($c);
        $wrong = new EntityId(
            $references->save(ClassificationKind::Campus, null, "SITE", "Fictional site", true, null)["id"],
        );
        $retired = $references->save(ClassificationKind::ReportingGroup, null, "OLD", "Retired", false, null);
        foreach ([$a, $b, $wrong, new EntityId($retired["id"])] as $invalid) {
            $before = $c->sis->sql->rows("sis_reporting_hierarchy");
            try {
                $this->parent($c, $a, $invalid);
                self::fail("An invalid parent was accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $c->sis->sql->rows("sis_reporting_hierarchy"));
            }
        }
        $version = $references->page(ClassificationKind::ReportingGroup)["items"];
        $row = array_values(
            array_filter($version, static fn(array $row): bool => $row["id"] === $a->value),
        )[0];
        $references->save(
            ClassificationKind::ReportingGroup,
            $a,
            $row["code"],
            $row["title"],
            false,
            $row["revision"],
        );
        $this->parent($c, $b, $a);
        self::assertSame($a->value, $this->service($c)->show($b)["parent"]["id"]);
        self::assertNull($this->parent($c, $b, null)["parent"]);
    }

    public function testDepthChecksIncludeDescendantsWhenAnExistingRootMoves(): void
    {
        $c = $this->fixture();
        $groups = [];
        for ($index = 0; $index < 9; $index++) {
            $groups[] = $this->group($c, "G" . $index);
            if ($index > 0 && $index < 8) {
                $this->parent($c, $groups[$index], $groups[$index - 1]);
            }
        }
        self::assertCount(8, $this->service($c)->show($groups[7])["path"]);
        foreach ([[$groups[8], $groups[7]], [$groups[0], $groups[8]]] as [$child, $parent]) {
            try {
                $this->parent($c, $child, $parent);
                self::fail("A ninth level was accepted.");
            } catch (ValidationException) {
                self::assertCount(7, $c->sis->sql->rows("sis_reporting_hierarchy"));
            }
        }
    }

    public function testStaleEditsCannotOverwriteChangedAwayThenBackOrRenamedAncestors(): void
    {
        $c = $this->fixture();
        $a = $this->group($c, "A");
        $b = $this->group($c, "B");
        $service = $this->service($c);
        $old = $service->show($b);
        $this->parent($c, $b, $a);
        $this->parent($c, $b, null);
        try {
            $service->save($b, $a, $old["revision"]);
            self::fail("An ABA change accepted an older edit.");
        } catch (ConcurrentModificationException) {
            self::assertNull($service->show($b)["parent"]);
        }
        $this->parent($c, $b, $a);
        $old = $service->show($b);
        $c->sis->sql->update(
            "sis_catalog_classifications",
            ["title" => "Changed fictional label"],
            ["id" => $a->value],
        );
        $this->expectException(ConcurrentModificationException::class);
        $service->save($b, null, $old["revision"]);
    }

    public function testRequiredAuditAndFinalSourcesAuthorityAndTopologyRollbackAtomically(): void
    {
        foreach (["audit", "reference", "relation", "state", "authority"] as $change) {
            $c = $this->fixture();
            $f = $c->sis;
            $a = $this->group($c, "A");
            $b = $this->group($c, "B");
            $tables = [
                "sis_reporting_hierarchy",
                "sis_reporting_hierarchy_state",
                "sis_catalog_classifications",
                "sis_audit",
            ];
            $before = array_map($f->sql->rows(...), $tables);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(function () use ($f, $a, $b, $change): void {
                match ($change) {
                    "audit" => throw new RuntimeException("Fictional audit failure"),
                    "reference" => $f->sql->update(
                        "sis_catalog_classifications",
                        ["active" => 0],
                        ["id" => $a->value],
                    ),
                    "relation" => $f->sql->update(
                        "sis_reporting_hierarchy",
                        ["parent_id" => null],
                        ["id" => $b->value],
                    ),
                    "state" => $f->sql->update(
                        "sis_reporting_hierarchy_state",
                        ["version" => 200],
                        ["id" => \Domain\Catalog\Repository\ReportingHierarchyRepository::STATE_ID],
                    ),
                    "authority" => ($f->access->staff = false),
                };
            });
            $service = $this->service($c, $audit);
            try {
                $service->save($b, $a, $service->show($b)["revision"]);
                self::fail("Failed final validation was accepted.");
            } catch (ConcurrentModificationException | AccessDeniedException | RuntimeException) {
                self::assertSame($before, array_map($f->sql->rows(...), $tables));
            }
        }
    }

    public function testAcceptedPublicationsAndStudentRootsSurviveCurrentReparentingAndRenaming(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $root = $this->group($c, "YEAR");
        $leaf = $this->group($c, "SHORT");
        $this->parent($c, $leaf, $root);
        [$section, $publication] = $this->publish($c, $leaf);
        $f->service->register($f->student($f->user())->id, $section);
        $source = new SqlSectionVersionRepository($f->sql);
        $retained = $source->find(new EntityId($publication["operation_id"]));
        self::assertSame($root->value, $retained->classifications->reportingRoot()->id->value);
        $events = $f->sql->rows("sis_history_events");
        $this->parent($c, $leaf, null);
        $f->sql->update(
            "sis_catalog_classifications",
            ["title" => "Renamed current root"],
            ["id" => $root->value],
        );
        self::assertSame(
            $retained->fingerprint(),
            $c->sectionPublications()->cacheVersion($section, $retained->publication)->fingerprint(),
        );
        self::assertSame(
            "Fictional YEAR",
            $source->find($retained->publication)->classifications->reportingRoot()->title,
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame(
            [],
            $this->sections($c)->show($section)["classifications"]["reporting_group_ancestors"],
        );
        $reports = new \Domain\Reporting\Service\RelationalReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($f->sql, $f->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($f->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $c->fixture->cipher,
            $f->clock,
            $f->reportLifecycles(cipher: $c->fixture->cipher),
        );
        $query = [
            "dataset" => "credits",
            "columns" => [
                ["field" => "retained_reporting_root_title", "label" => "Root", "aggregate" => "none"],
                ["field" => "student_id", "label" => "Students", "aggregate" => "count_distinct"],
            ],
            "joins" => [],
            "filters" => null,
            "having" => null,
            "distinct" => false,
            "sort" => [],
        ];
        self::assertSame("Fictional YEAR", $reports->preview($query, 0)["items"][0]["c0"]);
        self::assertSame(1, (int) $reports->preview($query, 0)["items"][0]["c1"]);
        $f->sql->update(
            "sis_section_version_classes",
            ["reporting_root_title" => "Damaged root"],
            ["id" => $retained->publication->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $source->find($retained->publication);
    }

    public function testNewPublicationReviewsRejectChangedParentPathsAndPrivateSetupRequiresCatalogDuty(): void
    {
        $c = $this->fixture();
        $root = $this->group($c, "YEAR");
        $leaf = $this->group($c, "SHORT");
        [$section] = $this->publish($c, $leaf);
        $publisher = $c->sectionPublications();
        $review = $publisher->review($section, "2026-08-01", "2026-08-31", "Fictional updated offering");
        $this->parent($c, $leaf, $root);
        try {
            $publisher->publish($section, $review["review_token"]);
            self::fail("An old review accepted a changed hierarchy.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $publisher->versions($section)["items"]);
        }
        $c->sis->access->staff = false;
        $c->sis->access->grants = ["sis:registration:manage"];
        foreach (["read", "write"] as $action) {
            try {
                if ($action === "read") {
                    $this->service($c)->show($leaf);
                } else {
                    $this->service($c)->save($leaf, null, "");
                }
                self::fail("Registration duty acquired hierarchy configuration.");
            } catch (AccessDeniedException) {
                self::assertSame(
                    $root->value,
                    new SqlReportingHierarchyRepository($c->sis->sql)->parent($leaf)->value,
                );
            }
        }
    }

    public function testSetupLimitRejectsAdditionalEntriesWithoutTouchingTheExistingFiveHundred(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        for ($index = 0; $index < 500; $index++) {
            $id = EntityId::generate()->value;
            $f->sql->insert("sis_catalog_classifications", [
                "id" => $id,
                "kind" => "reporting_group",
                "code" => "LIMIT-" . $index,
                "title" => "Fictional root",
                "active" => 1,
            ]);
            $f->sql->insert("sis_reporting_hierarchy", ["id" => $id, "parent_id" => null]);
        }
        $group = $this->group($c, "EXTRA");
        try {
            $this->parent($c, $group, null);
            self::fail("The setup limit was exceeded.");
        } catch (ValidationException) {
            self::assertCount(500, $f->sql->rows("sis_reporting_hierarchy", [], 501));
            self::assertSame(0, new SqlReportingHierarchyRepository($f->sql)->version());
        }
    }

    public function testMaximumDepthUnicodePathsRemainReadableAndMalformedCachesFailClosed(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $previous = null;
        for ($index = 0; $index < 8; $index++) {
            $group = $this->group($c, "UNICODE-" . $index);
            $f->sql->update(
                "sis_catalog_classifications",
                ["title" => str_repeat("🌿", 191)],
                ["id" => $group->value],
            );
            if ($previous !== null) {
                $this->parent($c, $group, $previous);
            }
            $previous = $group;
        }
        [$section, $publication] = $this->publish($c, $group);
        $source = new SqlSectionVersionRepository($f->sql);
        $version = new EntityId($publication["operation_id"]);
        $retained = $source->find($version);
        self::assertCount(7, $retained->classifications->reportingAncestors);
        self::assertSame(str_repeat("🌿", 191), $retained->classifications->reportingRoot()->title);
        $row = $f->sql->current("sis_section_version_classes", $version->value);
        self::assertGreaterThan(8192, strlen($row["reporting_group_ancestors"]));
        $damageCases = [
            ["reporting_group_ancestors" => "{}"],
            ["reporting_group_ancestors" => "[null]"],
            ["reporting_hierarchy_recorded" => 0],
            ["reporting_root_id" => null],
        ];
        foreach ($damageCases as $damage) {
            $f->sql->update("sis_section_version_classes", $damage, ["id" => $version->value]);
            try {
                $source->find($version);
                self::fail("A malformed hierarchy cache was accepted.");
            } catch (CorruptHistoryException) {
                $f->sql->update("sis_section_version_classes", $row, ["id" => $version->value]);
            }
        }
        self::assertSame(
            $retained->fingerprint(),
            $c->sectionPublications()->cacheVersion($section, $version)->fingerprint(),
        );
    }

    public function testHierarchyAndSectionLockCollectionNeverAcquireCurrentReferencesBeforeCanonicalLocks(): void
    {
        $c = $this->fixture();
        $f = $c->sis;
        $root = $this->group($c, 'ROOT');
        $leaf = $this->group($c, 'LEAF');
        $revision = $this->service($c)->show($leaf)['revision'];
        $expected = [$root->value, $leaf->value];
        sort($expected, SORT_STRING);
        $actual = new SqlClassificationRepository($f->sql);
        $rows = [$root->value => $actual->get($root), $leaf->value => $actual->get($leaf)];
        $locked = [];
        $references = $this->createStub(\Domain\Catalog\Repository\ClassificationRepository::class);
        $references->method('peek')->willReturnCallback(static fn(EntityId $id): array => $rows[$id->value]);
        $references->method('lock')->willReturnCallback(function (EntityId $id) use (&$locked, $actual): void {
            $locked[] = $id->value;
            $actual->lock($id);
        });
        $references->method('get')->willReturnCallback(function (EntityId $id) use (&$locked, $expected, $rows): array {
            self::assertSame($expected, $locked, 'All canonical locks must precede a current reference read.');
            return $rows[$id->value];
        });
        $hierarchy = new SqlReportingHierarchyRepository($f->sql);
        $service = new ReportingHierarchyService(
            $hierarchy,
            $references,
            new ReportingHierarchyReader($hierarchy, $references),
            $f->access,
            $f->transactions,
            $f->audit
        );
        $service->save($leaf, $root, $revision);
        self::assertSame($expected, $locked);
        $locked = [];
        $section = $f->section()->id;
        $sections = new SqlSectionClassificationRepository($f->sql, $references);
        $f->transactions->run(fn() => $sections->lockReferences($section, [$leaf]));
        self::assertSame($expected, $locked);
    }
}
