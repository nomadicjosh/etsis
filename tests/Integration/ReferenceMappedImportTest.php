<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReferenceMappedImportTest extends TestCase
{
    private const array CORE = ["external_id" => 0, "code" => 1, "title" => 2];
    private const string CSV = "External ID,Code,Title\nref-1,math,Mathematics\n";

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function review(
        SisFixture $f,
        CatalogImportTarget $target,
        string $csv = self::CSV,
        array $choices = [],
    ): array {
        return $f->imports()->preview("fictional", $csv, self::CORE, choices: $choices, target: $target);
    }

    private function commit(
        SisFixture $f,
        CatalogImportTarget $target,
        array $review,
        string $csv = self::CSV,
        array $choices = [],
    ): array {
        return $f
            ->imports()
            ->commit(
                "fictional",
                $csv,
                self::CORE,
                $review["review_token"],
                choices: $choices,
                target: $target,
            );
    }

    public function testAllReferenceLibrariesUseIndependentSourceIdentitiesAndCreateNoHistoricalEvents(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows("sis_history_events");
        foreach (CatalogImportTarget::cases() as $target) {
            if (!$target->isReference()) {
                continue;
            }
            $review = $this->review($f, $target);
            self::assertTrue($review["ready"], $target->value);
            $result = $this->commit($f, $target, $review);
            self::assertSame(1, $result["imported"]);
            $table =
            $target === CatalogImportTarget::Subject ? "sis_subjects" : "sis_catalog_classifications";
            $where =
            ["code" => "MATH"] + ($target->classification() !== null ? ["kind" => $target->value] : []);
            $record = $f->sql->one($table, $where);
            new EntityId($record["id"]);
            self::assertSame("Mathematics", $record["title"]);
            self::assertSame(1, (int) $record["active"]);
            $f->sql->update($table, ["title" => "Later edit"], ["id" => $record["id"]]);
            self::assertSame($result, $this->commit($f, $target, $review));
            self::assertSame("skip", $this->review($f, $target)["plan"][0]["action"]);
            self::assertSame(
                "conflict",
                $this->review($f, $target, str_replace("Mathematics", "Changed source", self::CSV))[
                    "plan"
                ][0]["action"],
            );
        }
        self::assertCount(1, $f->sql->rows("sis_subject_import_mappings"));
        self::assertCount(8, $f->sql->rows("sis_class_import_mappings"));
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        self::assertSame([], $f->sql->rows("sis_import_mappings"));
        self::assertSame([], $f->sql->rows("sis_program_import_mappings"));
    }

    public function testImportedReferencesAreImmediatelyAvailableToCourseAndProgramMapping(): void
    {
        $f = new SisFixture();
        $targets = [CatalogImportTarget::Subject, CatalogImportTarget::Department, CatalogImportTarget::AcademicLevel];
        foreach ($targets as $target) {
            $this->commit($f, $target, $this->review($f, $target));
        }
        $csv = "ID,Code,Title,Credits,Subject,Department,Level\nc,MATH.101,Algebra,3,MATH,MATH,MATH\n";
        $mapping = self::CORE + [
            "credits" => 3,
            "subject_code" => 4,
            "department_code" => 5,
            "academic_level_code" => 6,
        ];
        $review = $f->imports()->preview("fictional", $csv, $mapping);
        self::assertTrue($review["ready"]);
        $f->imports()->commit("fictional", $csv, $mapping, $review["review_token"]);
        $course = $f->sql->one("sis_courses", ["code" => "MATH.101"]);
        self::assertSame(0, (int) $course["active"]);
        self::assertSame(
            $f->sql->one("sis_subjects", ["code" => "MATH"])["id"],
            $f->sql->one("sis_course_subjects", ["id" => $course["id"]])["subject_id"],
        );
        $csv = "ID,Code,Title,Department,Level\np,BA.MATH,Mathematics,MATH,MATH\n";
        $mapping = self::CORE + ["department_code" => 3, "academic_level_code" => 4];
        $review = $f->imports()->preview("fictional", $csv, $mapping, target: CatalogImportTarget::Program);
        self::assertTrue($review["ready"]);
        $f->imports()->commit(
            "fictional",
            $csv,
            $mapping,
            $review["review_token"],
            target: CatalogImportTarget::Program,
        );
        $program = $f->sql->one("sis_programs", ["code" => "BA.MATH"]);
        $details = $f->sql->one("sis_program_details", ["id" => $program["id"]]);
        self::assertNotNull($details["department_id"]);
        self::assertNotNull($details["academic_level_id"]);
        self::assertSame([], $f->sql->rows("sis_history_events"));
    }

    public function testLinkingRequiresExplicitMatchingActiveReferenceAndNeverReactivatesIt(): void
    {
        foreach ([CatalogImportTarget::Subject, CatalogImportTarget::Department] as $target) {
            $f = new SisFixture();
            $this->commit($f, $target, $this->review($f, $target));
            $csv = str_replace("ref-1", "second-source-id", self::CSV);
            $review = $this->review($f, $target, $csv);
            self::assertFalse($review["ready"]);
            self::assertSame("match", $review["plan"][0]["action"]);
            $id = $review["plan"][0][$target->idField()];
            $choices = [["external_id" => "second-source-id", "action" => "link", $target->idField() => $id]];
            $review = $this->review($f, $target, $csv, $choices);
            $table =
            $target === CatalogImportTarget::Subject ? "sis_subjects" : "sis_catalog_classifications";
            $f->sql->update($table, ["active" => 0], ["id" => $id]);
            try {
                $this->commit($f, $target, $review, $csv, $choices);
                self::fail("A retired reference was linked.");
            } catch (ConcurrentModificationException) {
                $result = $this->review($f, $target, $csv);
                self::assertSame("conflict", $result["plan"][0]["action"]);
                self::assertStringContainsString("retired", $result["plan"][0]["message"]);
                self::assertSame(0, (int) $f->sql->one($table, ["id" => $id])["active"]);
            }
            $f->sql->update($table, ["active" => 1], ["id" => $id]);
            $review = $this->review($f, $target, $csv, $choices);
            self::assertSame(1, $this->commit($f, $target, $review, $csv, $choices)["linked"]);
        }
    }

    public function testReferenceKindAndActorCannotBorrowAnotherReview(): void
    {
        $f = new SisFixture();
        $review = $this->review($f, CatalogImportTarget::Department);
        foreach ([CatalogImportTarget::Subject, CatalogImportTarget::AcademicLevel] as $other) {
            try {
                $this->commit($f, $other, $review);
                self::fail("A different destination used the review.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_class_import_mappings"));
                self::assertSame([], $f->sql->rows("sis_subject_import_mappings"));
            }
        }
        $f->access->actor = EntityId::generate();
        $this->expectException(ValidationException::class);
        $this->commit($f, CatalogImportTarget::Department, $review);
    }

    public function testFinalReferenceMutationAuditFailureAuthorityLossAndMappingLossRollbackWholeBatch(): void
    {
        foreach ([CatalogImportTarget::Subject, CatalogImportTarget::Department] as $target) {
            foreach (["retired", "kind", "audit", "authority", "mapping"] as $failure) {
                $f = new SisFixture();
                $csv = self::CSV . "ref-2,eng,English\n";
                $review = $this->review($f, $target, $csv);
                $table =
                $target === CatalogImportTarget::Subject ? "sis_subjects" : "sis_catalog_classifications";
                $mappingTable =
                $target === CatalogImportTarget::Subject
                ? "sis_subject_import_mappings"
                : "sis_class_import_mappings";
                $before = $f->sql->rows($table);
                $audit = $this->createStub(AuditTrail::class);
                $audit
                    ->method("record")
                    ->willReturnCallback(static function (
                        $actor,
                        $action,
                    ) use (
                        $f,
                        $table,
                        $mappingTable,
                        $failure,
                    ): void {
                        if ($action !== "catalog.import_completed") {
                            return;
                        }
                        if ($failure === "audit" || ($failure === "kind" && $table === "sis_subjects")) {
                            throw new \RuntimeException("Fictional failed audit.");
                        } elseif ($failure === "authority") {
                            $f->access->staff = false;
                        } elseif ($failure === "mapping") {
                            $f->sql->execute("DELETE FROM " . $mappingTable);
                        } else {
                            $f->sql->update(
                                $table,
                                $failure === "kind" ? ["kind" => "award"] : ["active" => 0],
                                ["code" => "MATH"],
                            );
                        }
                    });
                try {
                    $f->imports($audit)->commit(
                        "fictional",
                        $csv,
                        self::CORE,
                        $review["review_token"],
                        target: $target,
                    );
                    self::fail("A changed reference batch committed.");
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    self::assertNotSame("A changed reference batch committed.", $e->getMessage());
                    self::assertSame($before, $f->sql->rows($table));
                    self::assertSame([], $f->sql->rows($mappingTable));
                    self::assertSame([], $f->sql->rows("sis_catalog_operations"));
                }
            }
        }
    }

    public function testReferenceValidationRejectsCourseFieldsAndInvalidNamesAndDetectsDuplicateCodes(): void
    {
        $f = new SisFixture();
        $bad = "ID,Code,Title\na,bad code,Invalid\n";
        self::assertFalse($this->review($f, CatalogImportTarget::Subject, $bad)["ready"]);
        self::assertFalse(
            $this->review(
                $f,
                CatalogImportTarget::Subject,
                str_replace("Invalid", str_repeat("x", 192), $bad),
            )["ready"],
        );
        $duplicates = self::CSV . "ref-2,MATH,Mathematics\n";
        self::assertSame(
            ["create", "conflict"],
            array_column($this->review($f, CatalogImportTarget::Department, $duplicates)["plan"], "action"),
        );
        $this->expectException(\InvalidArgumentException::class);
        $f->imports()->preview(
            "fictional",
            self::CSV,
            self::CORE + ["credits" => 2],
            target: CatalogImportTarget::Subject,
        );
    }

    public function testMigrationIsAdditiveIdempotentAndCompilesWithForeignKeysForSupportedDialects(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows("sis_history_events");
        $migration = new \CreateSisReferenceImportMappings("reference-imports");
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
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        foreach (["sis_subject_import_mappings", "sis_class_import_mappings"] as $table) {
            $foreign = $f->connection->pdo
                ->query("PRAGMA foreign_key_list(" . $table . ")")
                ->fetchAll(\PDO::FETCH_ASSOC);
            self::assertCount(1, $foreign);
        }
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
            foreach ($tables as $table) {
                $compiler = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
                $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
                self::assertStringContainsString("content_hash", $sql);
                self::assertDoesNotMatchRegularExpression(
                    "/DROP |DELETE |UPDATE |INSERT |AUTO_INCREMENT/i",
                    $sql,
                );
            }
        }
    }
}
