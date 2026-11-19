<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ReportRowSelectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(
        SisFixture $s,
        ?\Domain\Reporting\Repository\RelationalReportSource $source = null,
    ): \Domain\Reporting\Service\RelationalReportService {
        return new \Domain\Reporting\Service\RelationalReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            $source ?? new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }

    private function definition(string $ties = "all", string $direction = "latest"): array
    {
        return [
            "dataset" => "students",
            "columns" => [
                ["field" => "student_id", "label" => "Student", "aggregate" => "none"],
                ["field" => "registration_term_code", "label" => "Term", "aggregate" => "none"],
                ["field" => "registration_section_code", "label" => "Section", "aggregate" => "none"],
                ["field" => "registration_status", "label" => "Status", "aggregate" => "none"],
            ],
            "record_selection" => [
                "registration" => [
                    "date_field" => "registration_term_starts_on",
                    "direction" => $direction,
                    "ties" => $ties,
                ],
            ],
        ];
    }

    private function row(
        SisFixture $s,
        EntityId $student,
        string $code,
        string $start,
        string $status = "A",
    ): EntityId {
        $term = EntityId::generate();
        $s->sql->insert("sis_terms", [
            "id" => $term->value,
            "code" => $code,
            "starts_on" => $start,
            "ends_on" => substr($start, 0, 4) . "-12-31",
            "add_drop_ends_on" => $start,
        ]);
        $section = $s->section(fee: 0);
        $s->sql->update("sis_sections", ["term_id" => $term->value], ["id" => $section->id->value]);
        $id = EntityId::generate();
        // Fictional report projections only; these inserts do not invent historical events.
        $s->sql->insert("sis_enrollments", [
            "id" => $id->value,
            "student_id" => $student->value,
            "section_id" => $section->id->value,
            "term_id" => $term->value,
            "status" => $status,
            "registered_at" => "2026-08-10T12:00:00+00:00",
            "status_at" => "2026-08-10T12:00:00+00:00",
            "version" => 0,
        ]);
        return $id;
    }

    private function execute(SisFixture $s, array $definition): array
    {
        $plan = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($definition, AcademicReportSchema::datasets()["students"]),
        );
        return $s->sql->fetch($plan["sql"], $plan["parameters"]);
    }

    public function testLatestAndEarliestKeepCompleteRowsAndPreserveUnmatchedStudents(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $none = $s->student()->id;
        $this->row($s, $student, "ZZ.OLD", "2025-08-01", "W");
        $this->row($s, $student, "AA.NEW", "2026-08-01", "N");
        $latest = array_column($this->execute($s, $this->definition()), null, "c0");
        self::assertSame("AA.NEW", $latest[$student->value]["c1"]);
        self::assertSame("N", $latest[$student->value]["c3"]);
        self::assertNull($latest[$none->value]["c1"]);
        $earliest = array_column($this->execute($s, $this->definition(direction: "earliest")), null, "c0");
        self::assertSame("ZZ.OLD", $earliest[$student->value]["c1"]);
        self::assertSame("W", $earliest[$student->value]["c3"]);
    }

    public function testTiesAreKeptUnlessOneStableRowIsExplicitlySelected(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $first = $this->row($s, $student, "FIRST", "2026-08-01");
        $second = $this->row($s, $student, "SECOND", "2026-08-01", "N");
        self::assertCount(2, $this->execute($s, $this->definition()));
        $d = $this->definition("one");
        $d["columns"][] = ["field" => "registration_status", "label" => "Again", "aggregate" => "none"];
        $rows = $this->execute($s, $d);
        self::assertCount(1, $rows);
        self::assertSame(strcmp($first->value, $second->value) > 0 ? "A" : "N", $rows[0]["c3"]);
    }

    public function testJoinFiltersNarrowCandidatesBeforeSelectionAndRowFiltersApplyAfterwards(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $this->row($s, $student, "OLD", "2025-08-01");
        $this->row($s, $student, "NEW", "2026-08-01", "W");
        $filter = [
            "condition" => "AND",
            "rules" => [["id" => "registration_status", "operator" => "equal", "value" => "A"]],
        ];
        $d = $this->definition();
        $d["joins"] = ["registration" => "inner"];
        $d["filters"] = $filter;
        self::assertSame([], $this->execute($s, $d));
        unset($d["filters"]);
        $d["join_filters"] = ["registration" => $filter];
        self::assertSame("OLD", $this->execute($s, $d)[0]["c1"]);
    }
    public function testMatchingCardsRemainIndependentFromSelectedOutputRows(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $this->row($s, $student, "OLD", "2025-08-01");
        $this->row($s, $student, "NEW", "2026-08-01", "W");
        $d = $this->definition();
        $d["existence"] = [
            "registration" => [
                "mode" => "any",
                "filters" => [
                    "condition" => "AND",
                    "rules" => [["id" => "registration_status", "operator" => "equal", "value" => "A"]],
                ],
            ],
        ];
        self::assertSame("W", $this->execute($s, $d)[0]["c3"]);
        $d["existence"]["registration"]["mode"] = "none";
        self::assertSame([], $this->execute($s, $d));
    }

    public function testNamedCopiesChooseTheirOwnEarliestAndLatestRecords(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $this->row($s, $student, "OLD", "2025-08-01");
        $this->row($s, $student, "NEW", "2026-08-01");
        $d = $this->definition("one");
        $d["aliases"] = ["copy1" => ["relation" => "registration", "label" => "First registration"]];
        $d["record_selection"]["copy1"] = [
            "date_field" => "copy1__registration_term_starts_on",
            "direction" => "earliest",
            "ties" => "one",
        ];
        $d["columns"][] = [
            "field" => "copy1__registration_term_code",
            "label" => "First term",
            "aggregate" => "none",
        ];
        $rows = $this->execute($s, $d);
        self::assertCount(1, $rows);
        self::assertSame("NEW", $rows[0]["c1"]);
        self::assertSame("OLD", $rows[0]["c4"]);
    }

    public function testMalformedSelectorsAndUnsafeDatePathsFailBeforeExecution(): void
    {
        $schema = AcademicReportSchema::datasets()["students"];
        $cases = [];
        $invalid = ["date_field" => "registration_term_code", "direction" => "sql", "ties" => "random", "sql" => "1=1"];
        foreach ($invalid as $key => $value) {
            $d = $this->definition();
            $d["record_selection"]["registration"][$key] = $value;
            $cases[] = $d;
        }
        $d = $this->definition();
        $d["record_selection"]["student"] = $d["record_selection"]["registration"];
        $cases[] = $d;
        $d = $this->definition();
        $d["record_selection"]["registration"]["date_field"] = "latest_term_starts_on";
        $cases[] = $d;
        $d = $this->definition();
        $d["record_selection"] = "DROP TABLE";
        $cases[] = $d;
        foreach ($cases as $case) {
            try {
                new ReportQuery($case, $schema);
                self::fail("An unsafe selector was accepted.");
            } catch (\Domain\Shared\Exception\ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDateOnlyPermissionsAndMetadataAreCheckedEvenWithoutDateOutput(): void
    {
        $s = new SisFixture();
        $schemas = AcademicReportSchema::datasets();
        $schemas["students"]["fields"]["registration_term_starts_on"]["permissions"] = ["sis:catalog:manage"];
        $source = $this->createMock(\Domain\Reporting\Repository\RelationalReportSource::class);
        $source->method("datasets")->willReturn($schemas);
        $source->expects(self::never())->method("execute");
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read", "sis:reporting:manage", "sis:reporting:run"];
        $service = $this->service($s, $source);
        $catalog = array_column($service->catalog()["datasets"], null, "id");
        self::assertArrayNotHasKey(
            "registration_term_starts_on",
            $catalog["students"]["relationships"]["registration"]["date_fields"],
        );
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->preview($this->definition(), 0);
    }

    public function testLatestKnownApplicationKeepsItsOwnStatusAndUnknownEarlierSubmissionsStayUnknown(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $unknown = $s->student();
        $submitted = $s
            ->submissionService()
            ->apply(EntityId::generate(), $student->personId, $s->program, $s->term);
        $before = $s->sql->rows("sis_history_events");
        $d = [
            "dataset" => "students",
            "columns" => [
                ["field" => "student_id", "label" => "Student", "aggregate" => "none"],
                ["field" => "person_application_id", "label" => "Application", "aggregate" => "none"],
                ["field" => "person_application_status", "label" => "Status", "aggregate" => "none"],
            ],
            "record_selection" => [
                "person_application" => [
                    "date_field" => "person_application_submitted_on",
                    "direction" => "latest",
                    "ties" => "all",
                ],
            ],
        ];
        $rows = array_column($this->service($s)->preview($d, 0)["items"], null, "c0");
        self::assertSame($submitted->id->value, $rows[$student->id->value]["c1"]);
        self::assertSame("submitted", $rows[$student->id->value]["c2"]);
        self::assertNull($rows[$unknown->id->value]["c1"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $plan = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($d, AcademicReportSchema::datasets()["students"]),
        );
        $steps = $s->sql->fetch("EXPLAIN QUERY PLAN " . $plan["sql"], $plan["parameters"]);
        foreach (["pick_row", "rank_row"] as $alias) {
            self::assertNotEmpty(array_filter(
                $steps,
                static fn(array $row): bool => str_contains($row["detail"], "SEARCH " . $alias) &&
                    str_contains($row["detail"], "person_id=?") &&
                    str_contains($row["detail"], "sa_person_report"),
            ));
        }
        // Index rollback preserves applications and journal facts; restoring it restores the search plan.
        $migration = new \AddSisApplicationPersonIndex("application-index");
        $migration->setObjectMap(new \ArrayObject([
            "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                $s->connection,
                "test_migrations",
            ),
        ]));
        $applications = $s->sql->rows("sis_applications");
        $migration->down();
        self::assertSame($applications, $s->sql->rows("sis_applications"));
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $migration->up();
        self::assertSame($applications, $s->sql->rows("sis_applications"));
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read", "sis:reporting:manage", "sis:reporting:run"];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testLivePopulationRechecksSelectedRowsWhileStaticCaptureKeepsTheOriginalResult(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $this->row($s, $student, "BEFORE", "2025-08-01");
        $d = $this->definition("one");
        $d["filters"] = [
            "condition" => "AND",
            "rules" => [["id" => "registration_status", "operator" => "equal", "value" => "A"]],
        ];
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, "Fictional selected records", $d);
        foreach (\Domain\Reporting\Enum\StudentPopulationAction::cases() as $action) {
            self::assertCount(1, $service->population($id, 0, $action)["items"]);
        }
        $service->configureReport($id, EntityId::generate(), 0, "static", null);
        $this->row($s, $student, "AFTER", "2026-08-01", "W");
        self::assertSame("BEFORE", $service->run($id, 0)["items"][0]["c1"]);
        self::assertSame("one", $service->view($id)["row_selection"][0]["ties"]);
        self::assertSame([], $service->preview($d, 0)["items"]);
        $live = EntityId::generate();
        $service->save($live, $live, -1, "Fictional live selection", $d);
        $this->expectException(\Domain\Shared\Exception\ConcurrentModificationException::class);
        $service->selectStudents(
            $live,
            [$student->value],
            0,
            \Domain\Reporting\Enum\StudentPopulationAction::Registration,
        );
    }

    public function testSelectionIsPortableBoundAndIndexedToTheParent(): void
    {
        $s = new SisFixture();
        $s->student();
        foreach (["mysql", "pgsql", "sqlite", "sqlsrv", "oci"] as $driver) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method("quoteIdentifier")->willReturnCallback(
                static fn(string $name): string => match ($driver) {
                    "mysql" => "`" . $name . "`",
                    "sqlsrv" => "[" . $name . "]",
                    default => '"' . $name . '"',
                },
            );
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method("getConnection")->willReturn($connection);
            foreach (["one", "all"] as $ties) {
                $d = $this->definition($ties);
                $d["join_filters"] = [
                    "registration" => [
                        "condition" => "AND",
                        "rules" => [["id" => "registration_status", "operator" => "equal", "value" => "A"]],
                    ],
                ];
                $plan = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db))->compile(
                    new ReportQuery($d, AcademicReportSchema::datasets()["students"]),
                );
                self::assertSame(["A", "A"], $plan["parameters"]);
                self::assertStringContainsString("NOT EXISTS", $plan["sql"]);
                self::assertStringNotContainsString("= 'A'", $plan["sql"]);
            }
        }
        $plan = new ReportSelectCompiler($s->sql)->compile(
            new ReportQuery($this->definition(), AcademicReportSchema::datasets()["students"]),
        );
        $steps = $s->sql->fetch("EXPLAIN QUERY PLAN " . $plan["sql"], $plan["parameters"]);
        self::assertNotEmpty(
            array_filter(
                $steps,
                static fn(array $row): bool => str_contains($row["detail"], "SEARCH pick_row") &&
                    str_contains($row["detail"], "student_id=?"),
            ),
        );
        self::assertNotEmpty(
            array_filter(
                $steps,
                static fn(array $row): bool => str_contains($row["detail"], "SEARCH rank_row") &&
                    str_contains($row["detail"], "student_id=?"),
            ),
        );
    }
    public function testSingleRowSelectionCanBeUsedWithAccountTotalsButTiedRowsCannotMultiplyMoney(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $this->row($s, $student, "OLD", "2025-08-01");
        $this->row($s, $student, "NEW", "2026-08-01");
        $this->row($s, $student, "TIED", "2026-08-01");
        $account = \Domain\Finance\StudentAccount::open(
            new \Domain\Finance\ValueObject\AccountId(),
            $student,
            $s->term,
            "USD",
        );
        $account->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Charge,
            new \Domain\Finance\ValueObject\Money(1200, "USD"),
            $s->access->actor,
        );
        $s->saveAccount($account);
        $d = [
            "dataset" => "accounts",
            "columns" => [
                ["field" => "currency", "label" => "Currency", "aggregate" => "none"],
                ["field" => "balance_minor", "label" => "Balance in minor units", "aggregate" => "sum"],
            ],
            "record_selection" => $this->definition("one")["record_selection"],
        ];
        $events = $s->sql->rows("sis_history_events");
        self::assertSame([["c0" => "USD", "c1" => 1200]], $this->service($s)->preview($d, 0)["items"]);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        $d["record_selection"]["registration"]["ties"] = "all";
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testSharedStaticResultsStillRequireHiddenSelectorSourcePermissions(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $s->submissionService()->apply(EntityId::generate(), $student->personId, $s->program, $s->term);
        $d = [
            "dataset" => "students",
            "columns" => [["field" => "student_id", "label" => "Student", "aggregate" => "none"]],
            "record_selection" => [
                "person_application" => [
                    "date_field" => "person_application_submitted_on",
                    "direction" => "latest",
                    "ties" => "one",
                ],
            ],
        ];
        $service = $this->service($s);
        $id = EntityId::generate();
        $service->save($id, $id, -1, "Fictional private date source", $d);
        $service->configureReport($id, EntityId::generate(), 0, "static", null);
        $reader = $s->user();
        new \Infrastructure\Persistence\Repository\SqlReportRepository($s->sql, $s->clock)->share(
            $id,
            $reader,
        );
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read", "sis:reporting:run", "sis:admissions:manage"];
        self::assertCount(1, $service->run($id, 0)["items"]);
        $s->access->grants = ["sis:student:read", "sis:reporting:run"];
        $this->expectException(\Domain\Shared\Exception\AccessDeniedException::class);
        $service->run($id, 0);
    }
}
