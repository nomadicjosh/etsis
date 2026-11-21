<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\ProgramClassifications;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use ProgramPublicationFixture;

final class ProgramSitesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
    }

    private function campus(ProgramPublicationFixture $p, string $code, bool $active = true): array
    {
        return $p->classifications->save(
            ClassificationKind::Campus,
            null,
            $code,
            "Fictional " . $code,
            $active,
            null,
        );
    }

    private function save(
        ProgramPublicationFixture $p,
        ?array $primary,
        array $sites,
        ?AuditTrail $audit = null,
    ): array {
        $f = $p->sis;
        $draft = $p->service->draft($f->program);
        return $p
            ->publisher(audit: $audit)
            ->save(
                $f->program,
                "BSCS",
                "Fictional Computer Science",
                "Fictional program.",
                null,
                null,
                null,
                $draft["revision"],
                campus: $primary === null ? null : new EntityId($primary["id"]),
                additionalCampuses: array_map(
                    static fn(array $site): EntityId => new EntityId($site["id"]),
                    $sites,
                ),
            );
    }

    public function testDraftSitesAreCanonicalReferenceCrudAndPublicationRetainsTheExactReviewedSet(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $primary = $this->campus($p, "MAIN");
        $first = $this->campus($p, "EAST");
        $second = $this->campus($p, "WEST");
        $before = $f->sql->rows("sis_history_events");
        $draft = $this->save($p, $primary, [$second, $first]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $ids = [$first["id"], $second["id"]];
        sort($ids, SORT_STRING);
        self::assertSame(
            $ids,
            array_column($draft["definition"]["classifications"]["additional_campuses"], "id"),
        );
        $rows = $f->sql->rows("sis_program_sites");
        $this->save($p, $primary, [$first, $second]);
        self::assertSame(
            $rows,
            $f->sql->rows("sis_program_sites"),
            "Unchanged affiliations keep their support-row identities.",
        );
        $year = $p->calendar->year(2024);
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional site approval.");
        $receipt = $p->service->publish($f->program, $review["review_token"]);
        $projection = $f->sql->one("sis_program_catalog_details", ["id" => $receipt["operation_id"]]);
        self::assertSame(1, (int) $projection["additional_campuses_recorded"]);
        self::assertCount(2, $f->sql->rows("sis_program_catalog_sites"));
        self::assertEquals(
            $review["definition"],
            $p->service->versions($f->program)["items"][0]["definition"],
        );
        $this->save($p, null, []);
        self::assertSame($receipt, $p->service->publish($f->program, $review["review_token"]));
        self::assertSame([], $f->sql->rows("sis_program_sites"));
        self::assertCount(2, $f->sql->rows("sis_program_catalog_sites"));
    }

    public function testPriorSingleCampusShapeRemainsUnknownAndExplicitEmptySitesAreADifferentFact(): void
    {
        $old = [
            "department" => null,
            "academic_level" => null,
            "award" => null,
            "school" => null,
            "field_of_study" => null,
            "campus" => null,
        ];
        $previous = ProgramClassifications::fromArray($old);
        self::assertTrue($previous->campusRecorded);
        self::assertFalse($previous->additionalCampusesRecorded);
        self::assertSame($old, $previous->describe());
        $current = ProgramClassifications::fromArray($old + ["additional_campuses" => []]);
        self::assertTrue($current->additionalCampusesRecorded);
        self::assertSame($old + ["additional_campuses" => []], $current->describe());
        foreach ([null, false, ["not-a-snapshot"], ["key" => []]] as $invalid) {
            try {
                ProgramClassifications::fromArray($old + ["additional_campuses" => $invalid]);
                self::fail("Invalid site evidence was accepted.");
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame("Invalid site evidence was accepted.", $error->getMessage());
            }
        }
    }

    public function testSelectionRejectsDuplicatesPrimaryRepetitionWrongCategoryAndNewRetiredSites(): void
    {
        $p = new ProgramPublicationFixture();
        $first = $this->campus($p, "FIRST");
        $retired = $this->campus($p, "OLD", false);
        $department = $p->classifications->save(
            ClassificationKind::Department,
            null,
            "SCI",
            "Sciences",
            true,
            null,
        );
        $selections = [
            [$first, [$first]],
            [null, [$first, $first]],
            [null, [$retired]],
            [null, [$department]],
            [null, array_fill(0, 21, $first)],
        ];
        foreach ($selections as [$primary, $sites]) {
            try {
                $this->save($p, $primary, $sites);
                self::fail("Invalid site selection was saved.");
            } catch (ValidationException $error) {
                self::assertStringContainsString("campus", $error->errors[0]["message"]);
                self::assertSame([], $p->sis->sql->rows("sis_program_sites"));
            }
        }
        $this->save($p, null, [$first]);
        $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($first["id"]),
            $first["code"],
            $first["title"],
            false,
            $first["revision"],
        );
        $this->save($p, null, [$first]); // Retirement does not silently remove a saved affiliation.
        $this->save($p, null, []);
        $this->expectException(ValidationException::class);
        $this->save($p, null, [$first]);
    }

    public function testChangedSiteLabelMembershipAndDraftRevisionInvalidateAnUnconfirmedPublication(): void
    {
        foreach (["label", "membership"] as $change) {
            $p = new ProgramPublicationFixture();
            $f = $p->sis;
            $site = $this->campus($p, "EAST");
            $saved = $this->save($p, null, [$site]);
            $year = $p->calendar->year(2024);
            $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional review.");
            if ($change === "label") {
                $p->classifications->save(
                    ClassificationKind::Campus,
                    new EntityId($site["id"]),
                    "EAST",
                    "Changed site",
                    true,
                    $site["revision"],
                );
            } else {
                $this->save($p, null, []);
            }
            try {
                $p->service->publish($f->program, $review["review_token"]);
                self::fail("Stale offering-site review was published.");
            } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
                self::assertSame([], $f->sql->rows("sis_program_catalog_sites"));
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
            try {
                $p->service->save(
                    $f->program,
                    "BSCS",
                    "Old draft",
                    null,
                    null,
                    null,
                    null,
                    $saved["revision"],
                );
                self::fail("A stale program draft was saved.");
            } catch (\Domain\Shared\Exception\ConcurrentModificationException) {
                self::assertSame(
                    "Fictional Computer Science",
                    $p->service->draft($f->program)["definition"]["title"],
                );
            }
        }
    }

    public function testFinalDraftChangesRollBackAffiliationsCoreDataAndRequiredAudits(): void
    {
        foreach (["site", "reference", "retirement", "authority", "audit"] as $failure) {
            $p = new ProgramPublicationFixture();
            $f = $p->sis;
            $site = $this->campus($p, "EAST");
            $before = [
                $f->sql->rows("sis_programs"),
                $f->sql->rows("sis_program_details"),
                $f->sql->rows("sis_program_sites"),
            ];
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(static function () use ($f, $site, $failure): void {
                if ($failure === "site") {
                    $f->sql->execute("DELETE FROM sis_program_sites");
                } elseif ($failure === "reference") {
                    $f->sql->update(
                        "sis_catalog_classifications",
                        ["title" => "Changed while saving"],
                        ["id" => $site["id"]],
                    );
                } elseif ($failure === "retirement") {
                    $f->sql->update("sis_catalog_classifications", ["active" => 0], ["id" => $site["id"]]);
                } elseif ($failure === "authority") {
                    $f->access->staff = false;
                } else {
                    throw new \RuntimeException("Fictional audit failure.");
                }
            });
            try {
                $this->save($p, null, [$site], $audit);
                self::fail("A changed draft completed.");
            } catch (\RuntimeException | \InvalidArgumentException $error) {
                self::assertNotSame("A changed draft completed.", $error->getMessage());
                self::assertSame($before, [
                    $f->sql->rows("sis_programs"),
                    $f->sql->rows("sis_program_details"),
                    $f->sql->rows("sis_program_sites"),
                ]);
                self::assertSame([], $f->sql->rows("sis_history_events"));
            }
        }
    }

    public function testFinalPublicationChangesRollBackEventsSiteSnapshotsAndCurrentSelection(): void
    {
        foreach (["source", "snapshot", "flag", "authority", "expiry"] as $failure) {
            $p = new ProgramPublicationFixture();
            $f = $p->sis;
            $site = $this->campus($p, "EAST");
            $this->save($p, null, [$site]);
            $year = $p->calendar->year(2024);
            $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional publication.");
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($f, $site, $review, $failure): void {
                    if ($failure === "source") {
                        $f->sql->update(
                            "sis_catalog_classifications",
                            ["title" => "Changed after append"],
                            ["id" => $site["id"]],
                        );
                    } elseif ($failure === "snapshot") {
                        $f->sql->execute("DELETE FROM sis_program_catalog_sites");
                    } elseif ($failure === "flag") {
                        $f->sql->update(
                            "sis_program_catalog_details",
                            ["additional_campuses_recorded" => 0],
                            ["id" => $review["operation_id"]],
                        );
                    } elseif ($failure === "authority") {
                        $f->access->staff = false;
                    } else {
                        $f->clock->time = $f->clock->time->modify("+21 minutes");
                    }
                });
            try {
                $p->publisher(audit: $audit)->publish($f->program, $review["review_token"]);
                self::fail("A changed publication completed.");
            } catch (\RuntimeException | \InvalidArgumentException $error) {
                self::assertNotSame("A changed publication completed.", $error->getMessage());
                self::assertSame([], $f->sql->rows("sis_program_catalog_sites"));
                self::assertSame([], $f->sql->rows("sis_program_catalog_details"));
                self::assertSame([], $f->sql->rows("sis_history_events"));
                self::assertCount(1, $f->sql->rows("sis_program_sites"));
            }
        }
    }

    public function testMissingKnownRetainedSiteCannotBeReplacedFromTodaysProgramSetup(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $site = $this->campus($p, "EAST");
        $this->save($p, null, [$site]);
        $year = $p->calendar->year(2024);
        $r = $p->service->review($f->program, new EntityId($year["id"]), "Fictional publication.");
        $p->service->publish($f->program, $r["review_token"]);
        $before = $f->sql->rows("sis_history_events");
        $f->sql->execute("DELETE FROM sis_program_catalog_sites");
        try {
            $p->service->review($f->program, new EntityId($year["id"]), "Fictional next publication.");
            self::fail("Missing retained offering sites were silently repaired.");
        } catch (CorruptHistoryException) {
            self::assertSame([], $f->sql->rows("sis_program_catalog_sites"));
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    private function reports(ProgramPublicationFixture $p): \Domain\Reporting\Service\RelationalReportService
    {
        $f = $p->sis;
        return new \Domain\Reporting\Service\RelationalReportService(
            new SqlReportRepository($f->sql, $f->clock),
            new SqlRelationalReportRepository($f->sql),
            new SqlRelationalReportSource($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            $f->reportLifecycles(),
        );
    }

    public function testReportingSeparatesCurrentSitesFromCohortSnapshotsWithoutMultiplyingStudentCounts(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $first = $this->campus($p, "EAST");
        $second = $this->campus($p, "WEST");
        $this->save($p, null, [$first, $second]);
        $year = $p->calendar->year(2024);
        $r = $p->service->review($f->program, new EntityId($year["id"]), "Fictional site publication.");
        $p->service->publish($f->program, $r["review_token"]);
        $person = $f->legacyPerson($f->user(), "Fictional", "Student");
        $application = $f->legacyApplication($person->id, $f->program, new EntityId($year["terms"][0]["id"]));
        $f->legacyAdmissionDecision($application->id, true);
        $f->matriculate($application->id);
        $this->save($p, null, [$second]);
        $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($second["id"]),
            "WEST",
            "Changed current West",
            true,
            $second["revision"],
        );
        $before = $f->sql->rows("sis_history_events");
        $definition = [
            "dataset" => "programs",
            "columns" => [
                [
                    "field" => "program_additional_campus_title",
                    "label" => "Published offering site",
                    "aggregate" => "none",
                ],
                [
                    "field" => "program_additional_campuses_recorded",
                    "label" => "Recorded",
                    "aggregate" => "none",
                ],
                ["field" => "student_id", "label" => "Students", "aggregate" => "count_distinct"],
            ],
        ];
        $rows = $this->reports($p)->preview($definition, 0)["items"];
        self::assertSame(["Fictional EAST", "Fictional WEST"], array_column($rows, "c0"));
        self::assertSame([1, 1], array_column($rows, "c2"));
        $definition["dataset"] = "catalog_program_sites";
        $definition["columns"] = [
            ["field" => "additional_campus_title", "label" => "Current site", "aggregate" => "none"],
        ];
        self::assertSame(
            "Changed current West",
            $this->reports($p)->preview($definition, 0)["items"][0]["c0"],
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $reports = $this->reports($p);
        $report = EntityId::generate();
        $reports->save($report, EntityId::generate(), -1, "Fictional program offering sites", $definition);
        $f->reportLifecycles()->configure($report, EntityId::generate(), 0, "static", null);
        $current = $p->classifications->page(ClassificationKind::Campus, "WEST")["items"][0];
        $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($current["id"]),
            "WEST",
            "Later current West",
            true,
            $current["revision"],
        );
        self::assertSame("Changed current West", $reports->run($report, 0)["items"][0]["c0"]);
        $recipient = $f->user();
        new SqlReportRepository($f->sql, $f->clock)->share($report, $recipient);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        $f->access->grants = ["sis:reporting:run", "sis:catalog:manage"];
        self::assertSame("Changed current West", $reports->run($report, 0)["items"][0]["c0"]);
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $f->access->staff = false;
        $f->access->grants = ["sis:reporting:run", "sis:student:read"];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $this->reports($p)->preview($definition, 0);
    }
    public function testMigrationIsAdditiveAndIdempotentAndCompilesForTheFiveFrameworkDialects(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $site = $this->campus($p, "EAST");
        $this->save($p, null, [$site]);
        $year = $p->calendar->year(2024);
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional publication.");
        $p->service->publish($f->program, $review["review_token"]);
        $tables = [
            "sis_program_sites",
            "sis_program_catalog_sites",
            "sis_program_catalog_details",
            "sis_history_events",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        $migration = new \AddSisProgramSites("program-sites");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        $migration->up();
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
        self::assertCount(
            2,
            $f->connection->pdo
                ->query("PRAGMA foreign_key_list(sis_program_sites)")
                ->fetchAll(\PDO::FETCH_ASSOC),
        );
        self::assertCount(
            1,
            $f->connection->pdo
                ->query("PRAGMA foreign_key_list(sis_program_catalog_sites)")
                ->fetchAll(\PDO::FETCH_ASSOC),
        );
        $created = $altered = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema->method("getColumns")->willReturn(["id"]);
        $schema
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $build) use (&$created): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $created[] = $table;
            });
        $schema
            ->method("alter")
            ->willReturnCallback(static function (string $name, callable $build) use (&$altered): void {
                $table = new \Qubus\Expressive\Schema\AlterTable($name);
                $build($table);
                $altered[] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "migration",
                ),
            ]),
        );
        $migration->up();
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $type = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $compiler = new $type($connection);
            $sql = json_encode(
                array_merge(
                    array_map($compiler->create(...), $created),
                    array_map($compiler->alter(...), $altered),
                ),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("additional_campuses_recorded", $sql);
            self::assertStringContainsString("spcsite_catalog_site_unique", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE |UPDATE |INSERT |AUTO_INCREMENT/i",
                $sql,
            );
        }
    }
    public function testAdditionalSitesAreMinimizedBeforeOptionalAiConsentAndProviderUse(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $site = $this->campus($p, "EAST");
        $this->save($p, null, [$site]);
        $assistant = $this->createMock(\Domain\Assistance\Service\AuthoringAssistant::class);
        $assistant->method("availability")->willReturn([
            "enabled" => true,
            "label" => "Fictional provider",
            "fingerprint" => "fictional-settings",
        ]);
        $assistant
            ->expects(self::once())
            ->method("suggest")
            ->willReturnCallback(static function (
                $task,
                array $draft
            ) use (
                $site,
            ): \Domain\Assistance\ValueObject\AuthoringAdvice {
                self::assertSame(
                    [["code" => "EAST", "title" => "Fictional EAST"]],
                    $draft["classifications"]["additional_campuses"],
                );
                self::assertStringNotContainsString($site["id"], json_encode($draft, JSON_THROW_ON_ERROR));
                return new \Domain\Assistance\ValueObject\AuthoringAdvice(
                    "Fictional advice.",
                    [],
                    "Fictional description.",
                );
            });
        $service = new \Domain\Catalog\Service\ProgramAssistanceService(
            $p->service,
            $assistant,
            $f->access,
            $f->audit,
        );
        $preview = $service->preview($f->program);
        self::assertSame(
            [["code" => "EAST", "title" => "Fictional EAST"]],
            $preview["draft"]["classifications"]["additional_campuses"],
        );
        self::assertStringNotContainsString($site["id"], json_encode($preview["draft"], JSON_THROW_ON_ERROR));
        $service->suggest(
            $f->program,
            \Domain\Assistance\ValueObject\AuthoringTask::Description,
            true,
            $preview["revision"],
        );
    }
}
