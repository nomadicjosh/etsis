<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\AdvisingCheckIn;
use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Enum\StudentPopulationAction;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAdvisingCheckInRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdvisingFollowUpReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function reports(SisFixture $s, ?AuditTrail $audit = null): RelationalReportService
    {
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }
    private function fixture(): array
    {
        $s = new SisFixture();
        $student = $s->student($s->user());
        $pursuit = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $student->id->value], 1)[0][
                "id"
            ],
        );
        $repo = new SqlAdvisingCheckInRepository($s->sql, $s->dropCipher);
        $owner = $s->admissions->person($student->personId)->ownerUserId;
        $a = AdvisingCheckIn::create(
            EntityId::generate(),
            $student->id,
            $pursuit,
            $owner,
            $s->access->actor,
            "2026-08-09",
            "2026-08-20",
            "Fictional PRIVATE note.",
            "Fictional next step.",
            "2026-08-10T00:30:00Z",
        );
        $repo->save($a, null);
        $b = AdvisingCheckIn::create(
            EntityId::generate(),
            $student->id,
            $pursuit,
            $owner,
            $s->access->actor,
            "2026-08-10",
            null,
            "",
            "Second step.",
            "2026-08-10T17:00:00Z",
        );
        $repo->save($b, null);
        $b->change("respond", $owner, 0, "Fictional PRIVATE response.", "2026-08-11T00:30:00Z");
        $repo->save($b, 0);
        return [$s, $student, $pursuit, $a, $b, $repo];
    }
    private function definition(
        array $fields = ["institutional_id", "held_on", "due_on", "followup_status"],
    ): array {
        return [
            "dataset" => "advising_followups",
            "columns" => array_map(
                static fn(string $f): array => [
                    "field" => $f,
                    "label" => $f,
                    "aggregate" => "none",
                ],
                $fields,
            ),
        ];
    }
    private function followup(): array
    {
        return $this->definition() + [
            "filters" => [
                "condition" => "AND",
                "rules" => [
                    [
                        "id" => "followup_status",
                        "operator" => "in",
                        "value" => ["open", "responded"],
                    ],
                ],
            ],
        ];
    }
    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_advising_checkins",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_student_programs",
            "sis_students",
            "sis_campaigns",
            "sis_deliveries",
            "sis_campaign_dispatches",
        ]);
    }
    private function rejects(callable $run, string $type): void
    {
        try {
            $run();
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
            return;
        }
        self::fail("An invalid advising report action was accepted.");
    }
    private function migration(SisFixture $s): \AddSisAdvisingFollowUpReports
    {
        $migration = new \AddSisAdvisingFollowUpReports("followup-test");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $s->connection,
                    "fixture",
                ),
                \Domain\Communications\Service\MessageCipher::class => $s->dropCipher,
            ]),
        );
        return $migration;
    }
    public function testDatesStatusesAndDistinctStudentSummariesStaySeparateFromConversationContent(): void
    {
        [$s, $student] = $this->fixture();
        $before = $this->facts($s);
        $reports = $this->reports($s);
        $schema = array_column($reports->catalog()["datasets"], null, "id")["advising_followups"];
        foreach (
            [
                "private_note",
                "next_step",
                "response",
                "resolution",
                "receipts",
                "owner_user_id",
                "sealed_data",
                "actor_id",
                "email",
            ] as $key
        ) {
            self::assertArrayNotHasKey($key, $schema["fields"]);
            $this->rejects(
                fn() => $reports->preview($this->definition([$key]), 0),
                ValidationException::class,
            );
        }
        self::assertCount(2, $reports->preview($this->definition(), 0)["items"]);
        $d = $this->definition(["held_on", "recorded_on", "responded_on"]);
        $rows = $reports->preview($d, 0)["items"];
        self::assertSame(["2026-08-10", "2026-08-10"], array_column($rows, "c1"));
        self::assertSame([null, "2026-08-11"], array_column($rows, "c2"));
        $d = $this->followup();
        $d["filters"]["rules"][] = [
            "id" => "due_on",
            "operator" => "less",
            "value" => "2026-08-21",
        ];
        self::assertSame(["open"], array_column($reports->preview($d, 0)["items"], "c3"));
        $d = $this->definition(["student_id", "checkin_id"]);
        $d["columns"][0]["aggregate"] = "count_distinct";
        $d["columns"][1]["aggregate"] = "count_distinct";
        self::assertSame([["c0" => 1, "c1" => 2]], $reports->preview($d, 0)["items"]);
        self::assertSame($before, $this->facts($s));
    }
    public function testSavedLiveFollowupDeduplicatesAndOnlyOffersReviewedMessaging(): void
    {
        [$s, $student, $pursuit, $a, $b, $repo] = $this->fixture();
        $reports = $this->reports($s);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional advising follow-up", $this->followup());
        self::assertSame(["messaging"], $reports->view($id)["student_actions"]);
        $before = $this->facts($s);
        $population = $reports->population($id, 0, StudentPopulationAction::Messaging);
        self::assertSame([$student->id->value], array_column($population["items"], "id"));
        self::assertCount(
            1,
            $reports->selectStudents(
                $id,
                [$student->id->value],
                0,
                StudentPopulationAction::Messaging,
            )["items"],
        );
        foreach (
            [StudentPopulationAction::Billing, StudentPopulationAction::Registration] as $action
        ) {
            $this->rejects(
                fn() => $reports->population($id, 0, $action),
                ValidationException::class,
            );
            $this->rejects(
                fn() => $reports->selectStudents($id, [$student->id->value], 0, $action),
                ValidationException::class,
            );
        }
        self::assertSame($before, $this->facts($s));
        $transport = $this->createMock(\Domain\Communications\Service\MailTransport::class);
        $transport->expects(self::never())->method("send");
        $campaigns = new \Domain\Communications\Service\CampaignService(
            new \Infrastructure\Persistence\Repository\SqlCampaignRepository($s->sql),
            $s->dropCipher,
            $transport,
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $s->sql->update(
            "users",
            ["email" => "fictional-advising@example.invalid"],
            ["user_id" => $a->facts()["owner_user_id"]],
        );
        self::assertCount(1, $campaigns->studentRecipients([$student->id])["items"]);
        self::assertSame($before, $this->facts($s));
        $a->change(
            "close",
            $s->access->actor,
            0,
            "Fictional PRIVATE outcome.",
            "2026-08-12T00:00:00Z",
        );
        $repo->save($a, 0);
        $b->change("cancel", $s->access->actor, 1, "Duplicate.", "2026-08-12T00:00:00Z");
        $repo->save($b, 1);
        $this->rejects(
            fn() => $reports->selectStudents(
                $id,
                [$student->id->value],
                0,
                StudentPopulationAction::Messaging,
            ),
            ConcurrentModificationException::class,
        );
        self::assertSame(
            "2026-08-12",
            $reports->preview($this->definition(["resolved_on"]), 0)["items"][0]["c0"],
        );
    }
    public function testEarlierAccountRecordsCannotBeReopenedByOrFiltersLeftJoinsOrNamedBranches(): void
    {
        [$s, $student] = $this->fixture();
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $s->user()->value],
            ["id" => $student->personId->value],
        );
        $d = $this->definition(["checkin_id", "full_name"]);
        $d["filters"] = [
            "condition" => "OR",
            "rules" => [
                ["id" => "followup_status", "operator" => "equal", "value" => "open"],
                ["id" => "followup_status", "operator" => "equal", "value" => "responded"],
            ],
        ];
        $d["joins"] = ["student" => "left", "pursuit" => "left"];
        $d["aliases"] = ["copy1" => ["relation" => "person", "label" => "Second name branch"]];
        $d["columns"][] = [
            "field" => "copy1__full_name",
            "label" => "Second name",
            "aggregate" => "none",
        ];
        self::assertSame([], $this->reports($s)->preview($d, 0)["items"]);
    }
    public function testSharedStaticResultsStillRequireAllCurrentSourceDuties(): void
    {
        [$s, $student] = $this->fixture();
        $reports = $this->reports($s);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional fixed follow-up", $this->followup());
        $s->reportLifecycles()->configure($id, EntityId::generate(), 0, "static", null);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        foreach (
            [[], ["sis:student:read"], ["sis:advising:checkin"], ["sis:advising:manage"]] as $grants
        ) {
            $s->access->grants = ["sis:reporting:run", "sis:reporting:manage", ...$grants];
            self::assertNotContains(
                "advising_followups",
                array_column($reports->catalog()["datasets"], "id"),
            );
            $this->rejects(fn() => $reports->run($id, 0), AccessDeniedException::class);
        }
        $s->access->grants = ["sis:reporting:run", "sis:student:read", "sis:advising:checkin"];
        self::assertCount(2, $reports->run($id, 0)["items"]);
        self::assertSame([], $reports->view($id)["student_actions"]);
        $this->rejects(
            fn() => $reports->population($id, 0, StudentPopulationAction::Messaging),
            AccessDeniedException::class,
        );
    }
    public function testIncompleteDatesAndChangedProjectionsFailClosedWithoutDecryptingReports(): void
    {
        [$s, $student, $pursuit, $a, $b, $repo] = $this->fixture();
        $s->sql->update("sis_advising_checkins", ["held_on" => null], ["id" => $a->facts()["id"]]);
        $this->rejects(
            fn() => $this->reports($s)->preview($this->definition(), 0),
            ValidationException::class,
        );
        $this->rejects(
            fn() => $repo->find($student->id, $pursuit, new EntityId($a->facts()["id"])),
            CorruptHistoryException::class,
        );
        $this->migration($s)->up();
        self::assertCount(2, $this->reports($s)->preview($this->definition(), 0)["items"]);
        $s->sql->update(
            "sis_advising_checkins",
            ["due_on" => "2026-08-19"],
            ["id" => $a->facts()["id"]],
        );
        $this->rejects(
            fn() => $repo->find($student->id, $pursuit, new EntityId($a->facts()["id"])),
            CorruptHistoryException::class,
        );
    }
    public function testMigrationPreservesOriginalCiphertextAndReceiptsAndRollsBackDamagedBatches(): void
    {
        [$s] = $this->fixture();
        $original = $s->sql->rows("sis_advising_checkins");
        foreach ($original as $row) {
            $s->sql->update(
                "sis_advising_checkins",
                array_fill_keys(
                    ["held_on", "due_on", "recorded_on", "responded_on", "resolved_on"],
                    null,
                ),
                ["id" => $row["id"]],
            );
        }
        $facts = array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_audit",
        ]);
        $migration = $this->migration($s);
        $migration->up();
        self::assertSame($original, $s->sql->rows("sis_advising_checkins"));
        $migration->up();
        self::assertSame($original, $s->sql->rows("sis_advising_checkins"));
        self::assertSame(
            $facts,
            array_map($s->sql->rows(...), [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_audit",
            ]),
        );
        foreach ($original as $row) {
            $s->sql->update("sis_advising_checkins", ["held_on" => null], ["id" => $row["id"]]);
        }
        $s->sql->update(
            "sis_advising_checkins",
            ["sealed_data" => "damaged"],
            ["id" => $original[1]["id"]],
        );
        $before = $s->sql->rows("sis_advising_checkins");
        $this->rejects($migration->up(...), CorruptHistoryException::class);
        self::assertSame($before, $s->sql->rows("sis_advising_checkins"));
    }
    public function testConversionAdvancesAtMostTwoHundredOriginalRecordsPerInvocation(): void
    {
        [$s, $student, $pursuit, $a, $b, $repo] = $this->fixture();
        for ($i = 0; $i < 201; $i++) {
            $item = AdvisingCheckIn::create(
                EntityId::generate(),
                $student->id,
                $pursuit,
                new EntityId($a->facts()["owner_user_id"]),
                $s->access->actor,
                "2026-08-10",
                null,
                "",
                "Fictional historical step.",
                "2026-08-10T17:00:00Z",
            );
            $item->change("close", $s->access->actor, 0, "Completed.", "2026-08-10T18:00:00Z");
            $repo->save($item, null);
            $s->sql->update(
                "sis_advising_checkins",
                ["held_on" => null],
                ["id" => $item->facts()["id"]],
            );
        }
        $migration = $this->migration($s);
        $this->rejects($migration->up(...), \LogicException::class);
        self::assertCount(
            1,
            $s->sql->fetch("SELECT id FROM sis_advising_checkins WHERE held_on IS NULL"),
        );
        $this->rejects(
            fn() => $this->reports($s)->preview($this->definition(), 0),
            ValidationException::class,
        );
        $migration->up();
        self::assertSame(
            [],
            $s->sql->fetch("SELECT id FROM sis_advising_checkins WHERE held_on IS NULL"),
        );
    }
    public function testDetailPagesAndEncryptedCompleteExportUseTheSameOwnerBoundary(): void
    {
        [$s, $student, $pursuit, $a, $b, $repo] = $this->fixture();
        for ($i = 0; $i < 51; $i++) {
            $item = AdvisingCheckIn::create(
                EntityId::generate(),
                $student->id,
                $pursuit,
                new EntityId($a->facts()["owner_user_id"]),
                $s->access->actor,
                "2026-08-10",
                null,
                "PRIVATE",
                "PRIVATE next step.",
                "2026-08-10T17:00:00Z",
            );
            $repo->save($item, null);
            $item->change("close", $s->access->actor, 0, "Completed.", "2026-08-10T18:00:00Z");
            $repo->save($item, 0);
        }
        $d = $this->definition(["checkin_id", "held_on", "followup_status"]);
        $reports = $this->reports($s);
        $first = $reports->preview($d, 0);
        $last = $reports->preview($d, 50);
        self::assertCount(50, $first["items"]);
        self::assertSame(50, $first["next_offset"]);
        self::assertCount(3, $last["items"]);
        self::assertNull($last["next_offset"]);
        self::assertCount(
            53,
            array_unique(array_column([...$first["items"], ...$last["items"]], "c0")),
        );
        $source = new SqlRelationalReportSource($s->sql);
        $capture = new \Infrastructure\Reporting\SqlReportExportCapture(
            new \Infrastructure\Reporting\SqlReportResultReader(
                $s->connection->queryBuilder(),
                $source,
            ),
            new \Domain\Reporting\Service\ReportSnapshotReader(
                new \Infrastructure\Persistence\Repository\SqlReportLifecycleRepository($s->sql),
                $s->dropCipher,
            ),
            $s->dropCipher,
        );
        $query = new ReportQuery($d, $source->datasets()["advising_followups"]);
        $before = $this->facts($s);
        $artifact = $capture->capture(EntityId::generate(), EntityId::generate(), $query, null);
        self::assertSame(53, $artifact->rows);
        self::assertStringNotContainsString("2026-08-10", implode("", $artifact->chunks));
        $csv = implode(
            "",
            array_map(
                static fn(string $chunk): string => json_decode(
                    $s->dropCipher->open($chunk),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                )["content"],
                $artifact->chunks,
            ),
        );
        self::assertStringContainsString("2026-08-10", $csv);
        self::assertStringNotContainsString("PRIVATE", $csv);
        self::assertSame($before, $this->facts($s));
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $s->user()->value],
            ["id" => $student->personId->value],
        );
        self::assertSame(
            0,
            $capture->capture(EntityId::generate(), EntityId::generate(), $query, null)->rows,
        );
        self::assertFalse($s->connection->pdo->inTransaction());
    }

    public function testAdditiveDateAndIndexDefinitionsCompileForFiveDialects(): void
    {
        [$s] = $this->fixture();
        $s->sql->execute("DROP INDEX sac_followup_due_idx");
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
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
        $migration = new \AddSisAdvisingFollowUpReports("followup-compiler");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "fixture",
                ),
            ]),
        );
        $migration->up();
        self::assertCount(6, $tables);
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $compiler = new $class($connection);
            $sql = json_encode(array_map($compiler->alter(...), $tables), JSON_THROW_ON_ERROR);
            foreach (
                [
                    "held_on",
                    "due_on",
                    "recorded_on",
                    "responded_on",
                    "resolved_on",
                    "sac_followup_due_idx",
                ] as $field
            ) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE |UPDATE |INSERT |sis_history_events/i",
                $sql,
            );
        }
        $this->rejects($migration->down(...), \LogicException::class);
    }

    public function testFinalAuthorityFailureSuppressesResults(): void
    {
        [$s] = $this->fixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
            $s->access->grants = [];
        });
        $this->rejects(
            fn() => $this->reports($s, $audit)->preview($this->definition(), 0),
            AccessDeniedException::class,
        );
    }
    public function testDateBucketsAndOwnerBoundaryCompileForAllFiveDatabases(): void
    {
        foreach (["mysql", "pgsql", "sqlite", "sqlsrv", "oci"] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $pdo = $this->createStub(\PDO::class);
            $pdo->method("getAttribute")->willReturn($driver);
            $connection
                ->method(\PHPUnit\Framework\MockObject\Runtime\PropertyHook::get("pdo"))
                ->willReturn($pdo);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method("getConnection")->willReturn($connection);
            $d = $this->definition(["due_on", "student_id"]);
            $d["columns"][0]["date_bucket"] = "month";
            $d["columns"][1]["aggregate"] = "count_distinct";
            $plan = new ReportSelectCompiler(
                new \Infrastructure\Persistence\SqlRecords($db),
            )->compile(new ReportQuery($d, AcademicReportSchema::datasets()["advising_followups"]));
            self::assertStringContainsString("EXISTS (SELECT 1", $plan["sql"]);
            self::assertStringContainsString("followup_person", $plan["sql"]);
            self::assertStringContainsString("COUNT(DISTINCT", $plan["sql"]);
            self::assertStringNotContainsString("sealed_data", $plan["sql"]);
            self::assertStringNotContainsString("sis_history_events", $plan["sql"]);
            self::assertSame([], $plan["parameters"]);
        }
    }
}
