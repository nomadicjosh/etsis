<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Course;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Catalog\ValueObject\CourseText;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use PHPUnit\Framework\TestCase;
use CatalogWorkflowFixture;
use SisFixture;

final class CourseNumberTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
    }
    private function editor(
        SisFixture $s,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): CatalogEditor {
        return new CatalogEditor(
            new SqlCatalogRevisionRepository($s->sql),
            $s->registration,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
        );
    }
    private function number(SisFixture $s, EntityId $course, ?string $number): void
    {
        $e = $this->editor($s);
        $row = $e->view("courses", $course);
        $e->courseText(
            $course,
            new CourseText($row["transcript_title"], $row["description"], $number),
            $row["revision"],
        );
    }
    private function reject(callable $run, string $type): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            self::assertInstanceOf($type, $e);
            return;
        }
        self::fail("An invalid course-number action was accepted.");
    }
    public function testCourseNumbersAreOptionalTextWithLeadingZerosSuffixesAndStrictBounds(): void
    {
        foreach (["001A", "00-01", "实验01", "000", "0"] as $number) {
            self::assertSame(
                $number,
                new CourseText(courseNumber: $number)->describe()["course_number"],
            );
        }
        self::assertNull(new CourseText(courseNumber: " ")->courseNumber);
        self::assertSame("001A", new CourseText(courseNumber: " 001A ")->courseNumber);
        foreach ([str_repeat("a", 33), "001\nA", "\xff", "A\tB"] as $number) {
            $this->reject(
                fn() => new CourseText(courseNumber: $number),
                \InvalidArgumentException::class,
            );
        }
        $this->reject(
            fn() => CourseText::fromArray([
                "transcript_title" => null,
                "description" => null,
                "course_number" => 1,
            ]),
            \InvalidArgumentException::class,
        );
    }
    public function testHistoricalAbsenceRoundTripsWithoutInventingAnExplicitBlank(): void
    {
        $old = new CourseDefinition(
            "MATH.101",
            "Algebra",
            3000,
            [],
            CourseText::fromArray([
                "transcript_title" => null,
                "description" => null,
            ]),
        );
        self::assertFalse($old->text->courseNumberRecorded);
        self::assertArrayNotHasKey("course_number", $old->describe());
        self::assertSame(
            $old->describe(),
            CourseDefinition::fromArray($old->describe())->describe(),
        );
        $new = new CourseDefinition("MATH.101", "Algebra", 3000, []);
        self::assertArrayHasKey("course_number", $new->describe());
        self::assertNull($new->describe()["course_number"]);
        self::assertNotSame($old->describe(), $new->describe());
    }
    public function testDraftPublicationAndEarlierNumberRemainIndependentAcrossLaterEdits(): void
    {
        $c = new CatalogWorkflowFixture();
        $s = $c->sis;
        $section = $s->section();
        $id = $section->courseId;
        $this->number($s, $id, "001A");
        $p = $c->publications();
        $review = $p->review($id, "Fictional course number approval.");
        self::assertSame("001A", $review["definition"]["course_number"]);
        $receipt = $p->publish($id, $review["review_token"]);
        $version = new EntityId($receipt["operation_id"]);
        $published = $p->publishedDefinition($id, $version);
        $cache = new SqlCourseVersionProjection($s->sql);
        $cache->retain($published);
        $events = $s->sql->rows("sis_history_events");
        $this->number($s, $id, "002B");
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame(
            "001A",
            $s->sql->one("sis_course_descriptions", ["course_id" => $id->value])[
                "published_course_number"
            ],
        );
        $next = $p->review($id, "Fictional later course numbering.");
        $p->publish($id, $next["review_token"]);
        self::assertSame("001A", $cache->get($version)->definition->text->courseNumber);
        self::assertSame($receipt, $p->publish($id, $review["review_token"]));
        self::assertSame(
            "002B",
            $s->sql->one("sis_course_descriptions", ["course_id" => $id->value])[
                "published_course_number"
            ],
        );
        $s->sql->update(
            "sis_course_versions",
            ["course_number" => "bad"],
            ["id" => $version->value],
        );
        $this->reject(fn() => $cache->retain($published), CorruptHistoryException::class);
    }
    public function testNumberOnlyChangesInvalidatePublicationAndCloneReviewsAndCopyAsDraft(): void
    {
        $c = new CatalogWorkflowFixture();
        $s = $c->sis;
        $id = $s->section()->courseId;
        $this->number($s, $id, "001A");
        $review = $c->publications()->review($id, "Fictional original review.");
        $clone = $c->clones()->review($id);
        $this->number($s, $id, "001B");
        $this->reject(
            fn() => $c->publications()->publish($id, $review["review_token"]),
            ConcurrentModificationException::class,
        );
        $draft = new Course(EntityId::generate(), "MATH.COPY", "Copied algebra", 3000, false);
        $this->reject(
            fn() => $c->clones()->commit($id, $draft, [], $clone["revision"]),
            ConcurrentModificationException::class,
        );
        $clone = $c->clones()->review($id);
        $result = $c->clones()->commit($id, $draft, [], $clone["revision"]);
        self::assertSame("001B", $this->editor($s)->view("courses", $draft->id)["course_number"]);
        $this->number($s, $id, null);
        self::assertSame($result, $c->clones()->commit($id, $draft, [], $clone["revision"]));
        self::assertSame("001B", $this->editor($s)->view("courses", $draft->id)["course_number"]);
    }
    public function testFinalAuthorityAndAuditFailuresRollbackNumberEdits(): void
    {
        foreach (["audit", "permission", "actor"] as $failure) {
            $s = new SisFixture();
            $id = $s->section()->courseId;
            $e = $this->editor($s);
            $row = $e->view("courses", $id);
            $before = $s->sql->rows("sis_course_descriptions");
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(static function () use ($s, $failure): void {
                    if ($failure === "audit") {
                        throw new \RuntimeException("Fictional audit failure.");
                    }
                    if ($failure === "permission") {
                        $s->access->staff = false;
                        $s->access->grants = [];
                    }
                    if ($failure === "actor") {
                        $s->access->actor = $s->user();
                    }
                });
            $this->reject(
                fn() => $this->editor($s, $audit)->courseText(
                    $id,
                    new CourseText(courseNumber: "001A"),
                    $row["revision"],
                ),
                $failure === "audit" ? \RuntimeException::class : AccessDeniedException::class,
            );
            self::assertSame($before, $s->sql->rows("sis_course_descriptions"));
        }
    }
    public function testImportsPreserveTextMatchOnlyMappedNumbersAndRetainOriginalUnmappedFingerprints(): void
    {
        $s = new SisFixture();
        $imports = $s->imports();
        $csv = "ID,Code,Title,Credits,Number\na,MATH.NUM,Numbered algebra,3,001A\n";
        $map = [
            "external_id" => 0,
            "code" => 1,
            "title" => 2,
            "credits" => 3,
            "course_number" => 4,
        ];
        $review = $imports->preview("number-source", $csv, $map);
        self::assertSame("001A", $review["plan"][0]["course_number"]);
        $result = $imports->commit("number-source", $csv, $map, $review["review_token"]);
        $id = new EntityId($s->sql->rows("sis_courses", ["code" => "MATH.NUM"])[0]["id"]);
        self::assertSame("001A", $this->editor($s)->view("courses", $id)["course_number"]);
        $changed = str_replace("001A", "001B", $csv);
        $r = $imports->preview("another-source", $changed, $map);
        self::assertFalse($r["ready"]);
        self::assertSame("conflict", $r["plan"][0]["action"]);
        $this->number($s, $id, "002");
        self::assertSame(
            $result,
            $imports->commit("number-source", $csv, $map, $review["review_token"]),
        );
        unset($map["course_number"]);
        $choices = [["external_id" => "a", "action" => "link", "course_id" => $id->value]];
        $r = $imports->preview("unmapped-source", $changed, $map, choices: $choices);
        self::assertSame("link", $r["plan"][0]["action"]);
        $imports->commit(
            "unmapped-source",
            $changed,
            $map,
            $r["review_token"],
            choices: [["external_id" => "a", "action" => "link", "course_id" => $id->value]],
        );
        self::assertSame("002", $this->editor($s)->view("courses", $id)["course_number"]);
        $row = new \Domain\Import\ValueObject\CatalogImportRow(
            2,
            "a",
            "MATH.NUM",
            "Numbered algebra",
            3000,
        );
        self::assertSame(
            hash(
                "sha256",
                json_encode(["MATH.NUM", "Numbered algebra", 3000], JSON_THROW_ON_ERROR),
            ),
            $row->fingerprint(),
        );
    }
    public function testBoundSectionPortalAndStudentReportsKeepTheSelectedNumber(): void
    {
        $c = new CatalogWorkflowFixture();
        $s = $c->sis;
        $section = $s->section();
        $id = $section->courseId;
        $this->number($s, $id, "001A");
        $p = $c->publications();
        $r = $p->review($id, "Fictional first numbered publication.");
        $receipt = $p->publish($id, $r["review_token"]);
        $r = $c
            ->bindings()
            ->review(
                $section->id,
                new EntityId($receipt["operation_id"]),
                "Fictional selected definition.",
            );
        $c->bindings()->commit($section->id, $r["review_token"]);
        $student = $s->student();
        $s->service->register($student->id, $section->id);
        $before = $s->sql->rows("sis_academic_credits");
        $this->number($s, $id, "002B");
        $r = $p->review($id, "Fictional later numbered publication.");
        $p->publish($id, $r["review_token"]);
        $portal = new \Infrastructure\Persistence\Repository\SqlCampusRepository(
            $s->sql,
            $s->clock,
            new \Infrastructure\Portal\SymfonyContentMarkup(),
        );
        $items = $portal->sections($s->term, "001A", null)["items"];
        self::assertCount(1, $items);
        self::assertSame("001A", $items[0]["course_number"]);
        self::assertSame([], $portal->sections($s->term, "002B", null)["items"]);
        $definition = [
            "dataset" => "credits",
            "columns" => [
                ["field" => "course_number", "label" => "Retained number", "aggregate" => "none"],
                ["field" => "course_number_recorded", "label" => "Known", "aggregate" => "none"],
            ],
        ];
        $query = new \Domain\Reporting\ValueObject\ReportQuery(
            $definition,
            \Infrastructure\Reporting\AcademicReportSchema::datasets()["credits"],
        );
        $rows = new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql)->execute(
            $query,
            0,
        );
        self::assertSame("001A", $rows[0]["c0"]);
        self::assertTrue($rows[0]["c1"]);
        self::assertSame($before, $s->sql->rows("sis_academic_credits"));
    }
    public function testEarlierCachedPublicationsStayUnknownAndNewBlankPublicationsAreKnown(): void
    {
        $s = new SisFixture();
        $id = $s->section()->courseId;
        $cache = new SqlCourseVersionProjection($s->sql);
        foreach ([false, true] as $known) {
            $text = new CourseText(courseNumberRecorded: $known);
            $d = new CourseDefinition("HIST.001", "Fictional history", 3000, [], $text);
            $version = EntityId::generate();
            $pub = \Domain\Catalog\ValueObject\PublishedCourseDefinition::fromPublication($id, [
                "version_id" => $version->value,
                "definition" => $d->describe(),
            ]);
            $cache->retain($pub);
            self::assertSame($known, $cache->get($version)->definition->text->courseNumberRecorded);
            self::assertSame($d->describe(), $cache->get($version)->definition->describe());
            $row = $s->sql->one("sis_course_versions", ["id" => $version->value]);
            self::assertNull($row["course_number"]);
            self::assertSame((int) $known, (int) $row["number_recorded"]);
        }
        $events = $s->sql->rows("sis_history_events");
        $rows = $s->sql->rows("sis_course_versions");
        $migration = new \AddSisCourseNumbers("course-number-repeat");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $s->connection,
                    "fixture",
                ),
            ]),
        );
        $migration->up();
        self::assertSame($rows, $s->sql->rows("sis_course_versions"));
        self::assertSame($events, $s->sql->rows("sis_history_events"));
    }
    public function testAdditiveMigrationCompilesInEveryFrameworkDialect(): void
    {
        $tables = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("getColumns")->willReturn(["id"]);
        $schema
            ->method("alter")
            ->willReturnCallback(static function (
                string $name,
                callable $build
            ) use (
                &$tables,
            ): void {
                $table = new \Qubus\Expressive\Schema\AlterTable($name);
                $build($table);
                $tables[] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
        $migration = new \AddSisCourseNumbers("course-number-schema");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "fixture",
                ),
            ]),
        );
        $migration->up();
        self::assertCount(5, $tables);
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(array_map($compiler->alter(...), $tables), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("course_number", $sql);
            self::assertStringContainsString("number_recorded", $sql);
            self::assertDoesNotMatchRegularExpression("/DROP |DELETE |UPDATE |INSERT /i", $sql);
        }
    }
}
