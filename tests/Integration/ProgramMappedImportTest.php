<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ProgramMappedImportTest extends TestCase
{
    private const array CORE = ["external_id" => 0, "code" => 1, "title" => 2];

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";
    }

    private function preview(
        SisFixture $f,
        string $csv,
        array $mapping = self::CORE,
        array $choices = [],
    ): array {
        return $f
            ->imports()
            ->preview("fictional", $csv, $mapping, choices: $choices, target: CatalogImportTarget::Program);
    }

    private function commit(
        SisFixture $f,
        string $csv,
        array $r,
        array $mapping = self::CORE,
        array $choices = [],
    ): array {
        return $f
            ->imports()
            ->commit(
                "fictional",
                $csv,
                $mapping,
                $r["review_token"],
                choices: $choices,
                target: CatalogImportTarget::Program,
            );
    }

    public function testOneSharedWorkflowCreatesWorkingProgramsAndExplicitlyLinksWithoutPublicationsOrEvents(): void
    {
        $f = new SisFixture();
        $old = $f->sql->one("sis_programs", ["id" => $f->program->value]);
        $csv =
        "Source,Code,Title\nnew,ba.eng,Fictional English\nlink," .
        $old["code"] .
        "," .
        $old["title"] .
        "\n";
        $events = $f->sql->rows("sis_history_events");
        $first = $this->preview($f, $csv);
        self::assertFalse($first["ready"]);
        self::assertSame(["create", "match"], array_column($first["plan"], "action"));
        $choices = [["external_id" => "link", "action" => "link", "program_id" => $f->program->value]];
        $review = $this->preview($f, $csv, choices: $choices);
        $receipt = $this->commit($f, $csv, $review, choices: $choices);
        self::assertSame(1, $receipt["imported"]);
        self::assertSame(1, $receipt["linked"]);
        $new = $f->sql->one("sis_programs", ["code" => "BA.ENG"]);
        new EntityId($new["id"]);
        self::assertSame("Fictional English", $new["title"]);
        self::assertSame($old, $f->sql->one("sis_programs", ["id" => $f->program->value]));
        self::assertSame([], $f->sql->rows("sis_program_catalogs"));
        self::assertSame([], $f->sql->rows("sis_import_mappings"));
        self::assertSame([], $f->sql->rows("sis_courses"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $f->sql->update("sis_programs", ["title" => "Later edit"], ["id" => $new["id"]]);
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($receipt, $this->commit($f, $csv, $review, choices: $choices));
        self::assertSame("Later edit", $f->sql->one("sis_programs", ["id" => $new["id"]])["title"]);
        self::assertSame(["skip", "skip"], array_column($this->preview($f, $csv)["plan"], "action"));
    }

    public function testAllProgramDependenciesAndDescriptionAreAssignedOnlyToNewDrafts(): void
    {
        $p = new \ProgramPublicationFixture();
        $f = $p->sis;
        $codes = [];
        $ids = [];
        $mapping = self::CORE + ["description" => 3];
        foreach (array_keys(CatalogImportTarget::Program->references()) as $i => $kind) {
            $row = $p->classifications->save(
                ClassificationKind::from($kind),
                null,
                "REF." . $i,
                "Fictional " . $kind,
                true,
                null,
            );
            $codes[] = $row["code"];
            $ids[$kind] = $row["id"];
            $mapping[$kind . "_code"] = $i + 4;
        }
        $csv =
        "Source,Code,Title,Description," .
        implode(",", array_keys($ids)) .
        "\np,BA.ENG,English,Plain <b>text</b>," .
        implode(",", $codes) .
        "\n";
        $review = $this->preview($f, $csv, $mapping);
        self::assertTrue($review["ready"]);
        $this->commit($f, $csv, $review, $mapping);
        $program = $f->sql->one("sis_programs", ["code" => "BA.ENG"]);
        $draft = $f->sql->one("sis_program_details", ["id" => $program["id"]]);
        self::assertSame("Plain <b>text</b>", $draft["description"]);
        foreach ($ids as $kind => $id) {
            self::assertSame($id, $draft[$kind . "_id"]);
        }
        $service = $p->service->draft(new EntityId($program["id"]));
        self::assertSame("REF.5", $service["definition"]["classifications"]["campus"]["code"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
    }

    public function testPublishedProgramCannotBeOverwrittenAndUnmappedValuesDoNotClearIt(): void
    {
        $p = new \ProgramPublicationFixture();
        $f = $p->sis;
        $draft = $p->service->draft($f->program);
        $p->service->save(
            $f->program,
            "BA.SCI",
            "Fictional Science",
            "Retain this description",
            null,
            null,
            null,
            $draft["revision"],
        );
        $year = $p->calendar->year(2024);
        $review = $p->service->review($f->program, new EntityId($year["id"]), "Fictional publication.");
        $p->service->publish($f->program, $review["review_token"]);
        $events = $f->sql->rows("sis_history_events");
        $details = $f->sql->rows("sis_program_details");
        $publications = $f->sql->rows("sis_program_catalog_details");
        $csv = "ID,Code,Title\nx,BA.SCI,Fictional Science\n";
        $choices = [["external_id" => "x", "action" => "link", "program_id" => $f->program->value]];
        $this->commit($f, $csv, $this->preview($f, $csv, choices: $choices), choices: $choices);
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($details, $f->sql->rows("sis_program_details"));
        self::assertSame($publications, $f->sql->rows("sis_program_catalog_details"));
        $bad = "ID,Code,Title,Description\ny,BA.SCI,Fictional Science,Different description\n";
        $result = $this->preview($f, $bad, self::CORE + ["description" => 3]);
        self::assertSame("conflict", $result["plan"][0]["action"]);
        self::assertNull($result["review_token"]);
    }

    public function testMissingRetiredOrChangedDependenciesNeedReviewAndAnOmittedRowDoesNotCreateReferences(): void
    {
        $p = new \ProgramPublicationFixture();
        $f = $p->sis;
        $csv = "ID,Code,Title,Campus\na,BA.ENG,English,MAIN\n";
        $mapping = self::CORE + ["campus_code" => 3];
        self::assertSame("conflict", $this->preview($f, $csv, $mapping)["plan"][0]["action"]);
        $reference = $p->classifications->save(
            ClassificationKind::Campus,
            null,
            "MAIN",
            "Fictional campus",
            true,
            null,
        );
        $review = $this->preview($f, $csv, $mapping);
        self::assertTrue($review["ready"]);
        $p->classifications->save(
            ClassificationKind::Campus,
            new EntityId($reference["id"]),
            "MAIN",
            "Changed campus",
            false,
            $reference["revision"],
        );
        try {
            $this->commit($f, $csv, $review, $mapping);
            self::fail("A changed dependency was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_program_import_mappings"));
        }
        $choices = [["external_id" => "a", "action" => "skip", "program_id" => null]];
        $review = $this->preview($f, $csv, $mapping, $choices);
        $receipt = $this->commit($f, $csv, $review, $mapping, $choices);
        self::assertSame(1, $receipt["omitted"]);
        self::assertSame(1, count($f->sql->rows("sis_programs")));
    }

    public function testProgramAndCourseSourceMappingsAndReviewProofsCannotBeCrossed(): void
    {
        $f = new SisFixture();
        $program = "ID,Code,Title\nsame,BA.ENG,English\n";
        $review = $this->preview($f, $program);
        $this->commit($f, $program, $review);
        $course = "ID,Code,Title,Credits\nsame,MATH.101,Algebra,3\n";
        $mapping = self::CORE + ["credits" => 3];
        $r = $f->imports()->preview("fictional", $course, $mapping);
        $f->imports()->commit("fictional", $course, $mapping, $r["review_token"]);
        self::assertCount(1, $f->sql->rows("sis_import_mappings"));
        self::assertCount(1, $f->sql->rows("sis_program_import_mappings"));
        $this->expectException(ValidationException::class);
        $f->imports()->commit("fictional", $course, $mapping, $review["review_token"]);
    }

    public function testFinalAuditFailureAuthorityLossExpiryOrDraftMutationRollsBackWholeProgramBatch(): void
    {
        foreach (["audit", "authority", "expiry", "draft", "mapping"] as $failure) {
            $f = new SisFixture();
            $csv = "ID,Code,Title\na,BA.ENG,English\nb,BS.MATH,Mathematics\n";
            $review = $this->preview($f, $csv);
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function ($actor, $action) use ($f, $failure): void {
                    if ($action !== "catalog.import_completed") {
                        return;
                    }
                    if ($failure === "authority") {
                        $f->access->staff = false;
                    } elseif ($failure === "expiry") {
                        $f->clock->time = $f->clock->time->modify("+21 minutes");
                    } elseif ($failure === "draft") {
                        $f->sql->update(
                            "sis_programs",
                            ["title" => "Changed at final audit"],
                            ["code" => "BA.ENG"],
                        );
                    } elseif ($failure === "mapping") {
                        $f->sql->execute("DELETE FROM sis_program_import_mappings");
                    } else {
                        throw new \RuntimeException("Fictional audit failure.");
                    }
                });
            $before = $f->sql->rows("sis_programs");
            try {
                $f->imports($audit)->commit(
                    "fictional",
                    $csv,
                    self::CORE,
                    $review["review_token"],
                    target: CatalogImportTarget::Program,
                );
                self::fail("A failed batch committed.");
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                self::assertNotSame("A failed batch committed.", $e->getMessage());
                self::assertSame($before, $f->sql->rows("sis_programs"));
                self::assertSame([], $f->sql->rows("sis_program_import_mappings"));
                self::assertSame([], $f->sql->rows("sis_catalog_operations"));
            }
        }
    }

    public function testProgramMappingsRejectCourseFieldsDuplicateIdentitiesAndSourceChanges(): void
    {
        $f = new SisFixture();
        $csv = "ID,Code,Title\na,BA.ENG,English\nA,BA.HIST,History\n";
        self::assertFalse($this->preview($f, $csv)["ready"]);
        $valid = "ID,Code,Title\na,BA.ENG,English\n";
        $r = $this->preview($f, $valid);
        try {
            $this->commit($f, str_replace("English", "History", $valid), $r);
            self::fail("An edited source was accepted.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_program_import_mappings"));
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->preview($f, $valid, self::CORE + ["credits" => 2]);
    }
    public function testMappingMigrationPreservesExistingReceiptsAndCompilesForTheFrameworkDialects(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows("sis_import_mappings");
        $events = $f->sql->rows("sis_history_events");
        $migration = new \CreateSisProgramImportMappings("program-mappings");
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
        self::assertSame($before, $f->sql->rows("sis_import_mappings"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        $foreign = $f->connection->pdo
            ->query("PRAGMA foreign_key_list(sis_program_import_mappings)")
            ->fetchAll(\PDO::FETCH_ASSOC);
        self::assertContains("program_id", array_column($foreign, "from"));
        $tables = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $build) use (&$tables): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $tables[] = $table;
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
            $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $sql = json_encode(new $compiler($connection)->create($tables[0]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("program_id", $sql);
            self::assertStringContainsString("content_hash", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE |UPDATE |INSERT |AUTO_INCREMENT/i",
                $sql,
            );
        }
    }
}
