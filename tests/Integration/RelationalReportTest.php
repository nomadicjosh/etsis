<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/HiatusFixture.php";

use Domain\Reporting\Service\RelationalReportService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;

final class RelationalReportTest extends TestCase
{
    private function service(HiatusFixture $h, ?AuditTrail $audit = null): RelationalReportService
    {
        $f = $h->sis;
        return new RelationalReportService(
            new SqlReportRepository($f->sql),
            new SqlRelationalReportRepository($f->sql),
            new SqlRelationalReportSource($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $h->cipher,
            $f->clock,
        );
    }
    private function query(string $dataset = "credits"): array
    {
        return [
            "dataset" => $dataset,
            "columns" => [
                ["field" => "institutional_id", "label" => "Student ID", "aggregate" => "none"],
                ["field" => "full_name", "label" => "Name", "aggregate" => "none"],
                ["field" => "course_title", "label" => "Course", "aggregate" => "none"],
            ],
            "joins" => [],
            "filters" => null,
            "having" => null,
            "distinct" => false,
            "sort" => [],
        ];
    }
    public function testMultipleJoinsAndCalculatedCreditColumnsProduceReadablePrivateResults(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section(20, 0);
        $f->service->register($student->id, $section->id);
        $q = $this->query();
        $q["columns"][] = [
            "field" => "attempted",
            "label" => "Remaining credits",
            "aggregate" => "none",
            "calculation" => ["operator" => "-", "field" => "completed"],
        ];
        $q["filters"] = [
            "condition" => "AND",
            "rules" => [["id" => "term_code", "operator" => "equal", "value" => "2026FA"]],
        ];
        $result = $this->service($h)->preview($q, 0);
        self::assertCount(1, $result["items"]);
        self::assertSame("Alex Example", $result["items"][0]["c1"]);
        self::assertSame(3.0, $result["items"][0]["c3"]);
        self::assertSame(50, $result["limit"]);
        self::assertArrayNotHasKey("rowkey0", $result["items"][0]);
    }
    public function testLeftJoinRelatedRowConditionsCountZeroEnrollmentSectionsAndHavingFiltersGroupedResults(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $empty = $f->section(20, 0);
        $full = $f->section(20, 0);
        $f->service->register($student->id, $full->id);
        $q = [
            "dataset" => "sections",
            "columns" => [
                ["field" => "section_code", "label" => "Section", "aggregate" => "none"],
                ["field" => "enrollment_id", "label" => "Students", "aggregate" => "count_distinct"],
            ],
            "joins" => ["enrollment" => "left"],
            "join_filters" => [
                "enrollment" => [
                    "condition" => "AND",
                    "rules" => [["id" => "enrollment_status", "operator" => "in", "value" => ["A", "N"]]],
                ],
            ],
            "filters" => null,
            "having" => ["condition" => "AND", "rules" => [["id" => "c1", "operator" => "less", "value" => 1]]],
            "sort" => [],
        ];
        $result = $this->service($h)->preview($q, 0);
        self::assertTrue($result["grouped"]);
        self::assertCount(1, $result["items"]);
        self::assertSame($empty->code, $result["items"][0]["c0"]);
        self::assertSame(0, $result["items"][0]["c1"]);
    }
    public function testSnapshotsAreEncryptedVersionedAndRequireFreshPermissionsWhenShared(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $service = $this->service($h);
        $id = EntityId::generate();
        $q = $this->query();
        $service->save($id, $id, -1, "Fictional joined report", $q);
        $service->save($id, $id, -1, "Fictional joined report", $q);
        self::assertCount(1, $f->sql->rows("sis_report_query_changes"));
        self::assertStringNotContainsString(
            "course_title",
            $f->sql->one("sis_report_queries", ["id" => $id->value])["encrypted_content"],
        );
        $q["columns"][2]["label"] = "Recorded title";
        $service->save($id, EntityId::generate(), 0, "Updated report", $q);
        self::assertSame(1, $service->view($id)["version"]);
        $recipient = $f->user();
        new SqlReportRepository($f->sql)->share($id, $recipient);
        $f->access->actor = $recipient;
        $f->access->staff = false;
        $f->access->grants = ["sis:reporting:run"];
        try {
            $service->run($id, 0);
            self::fail("A shared report granted academic source access.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->grants[] = "sis:student:read";
        self::assertSame([], $service->run($id, 0)["items"]);
        self::assertFalse($service->view($id)["can_manage"]);
        try {
            $service->save($id, EntityId::generate(), 1, "Recipient edit", $q);
            self::fail("A recipient changed the report definition without report management.");
        } catch (AccessDeniedException) {
            self::assertSame(1, $service->view($id)["version"]);
        }
    }
    public function testFilterOnlyAndJoinOnlyStudentReferencesRequireSourcePermission(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $f->access->staff = false;
        $f->access->grants = ["sis:reporting:manage", "sis:reporting:run", "sis:catalog:manage"];
        $catalog = $this->service($h)->catalog();
        self::assertCount(1, $catalog["datasets"]);
        self::assertArrayNotHasKey("identity", $catalog["datasets"][0]["relationships"]);
        self::assertArrayNotHasKey("person", $catalog["datasets"][0]["relationships"]);
        self::assertArrayNotHasKey("institutional_id", $catalog["datasets"][0]["fields"]);
        foreach (["field", "join"] as $case) {
            $q = [
                "dataset" => "sections",
                "columns" => [["field" => "section_code", "label" => "Section", "aggregate" => "none"]],
                "joins" => [],
            ];
            if ($case === "field") {
                $q["filters"] = [
                    "condition" => "AND",
                    "rules" => [["id" => "institutional_id", "operator" => "equal", "value" => "S-FICTIONAL"]],
                ];
            } else {
                $q["joins"] = ["identity" => "inner"];
            }
            try {
                $this->service($h)->preview($q, 0);
                self::fail("A hidden student relationship bypassed permission checks.");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testDetailPaginationPreservesEveryRowAndNumericAggregatesKeepFractions(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section(20, 0);
        $f->service->register($student->id, $section->id);
        $original = $f->sql->rows("sis_academic_credits")[0];
        for ($i = 0; $i < 60; ++$i) {
            $f->sql->insert(
                "sis_academic_credits",
                array_replace($original, [
                    "id" => EntityId::generate()->value,
                    "source_id" => EntityId::generate()->value,
                    "course_title" => "Fictional course " . $i,
                ]),
            );
        }
        $service = $this->service($h);
        $q = $this->query();
        $first = $service->preview($q, 0);
        $next = $service->preview($q, 50);
        self::assertCount(50, $first["items"]);
        self::assertCount(11, $next["items"]);
        self::assertNull($next["next_offset"]);
        self::assertSame([], array_intersect(array_column($first["items"], "c2"), array_column($next["items"], "c2")));
        $f->section(21, 0);
        $average = [
            "dataset" => "sections",
            "columns" => [["field" => "capacity", "label" => "Average seats", "aggregate" => "avg"]],
        ];
        self::assertSame(20.5, $service->preview($average, 0)["items"][0]["c0"]);
        $q["columns"] = [
            [
                "field" => "attempted",
                "label" => "Ratio",
                "aggregate" => "none",
                "calculation" => ["operator" => "/", "field" => "completed"],
            ],
        ];
        self::assertNull($service->preview($q, 0)["items"][0]["c0"]);
    }

    public function testLegacyExecutionCannotBypassRelationalSourceChecksAndStaleEditsFail(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $service = $this->service($h);
        $id = EntityId::generate();
        $q = $this->query();
        $service->save($id, $id, -1, "Fictional advanced report", $q);
        $service->save($id, EntityId::generate(), 0, "Updated advanced report", $q);
        try {
            $service->save($id, EntityId::generate(), 0, "Stale edit", $q);
            self::fail("Stale definition saved.");
        } catch (ConcurrentModificationException) {
            self::assertSame("Updated advanced report", $service->view($id)["name"]);
        }
        $legacy = new \Domain\Reporting\Service\ReportService(
            new SqlReportRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
        $this->expectException(ValidationException::class);
        $legacy->run($id);
    }

    public function testUnknownFieldsAndInvalidNumericDateAndAggregateOperationsAreRejected(): void
    {
        $h = new HiatusFixture();
        $service = $this->service($h);
        foreach (["field", "sum", "date", "sql"] as $case) {
            $q = $this->query();
            if ($case === "field") {
                $q["columns"][0]["field"] = "password";
            }
            if ($case === "sum") {
                $q["columns"][0]["aggregate"] = "sum";
            }
            if ($case === "date") {
                $q["filters"] = [
                    "condition" => "AND",
                    "rules" => [["id" => "term_starts_on", "operator" => "equal", "value" => "2026-99-99"]],
                ];
            }
            if ($case === "sql") {
                $q["joins"] = ["sis_persons; DROP TABLE users" => "left"];
            }
            try {
                $service->preview($q, 0);
                self::fail("Unsafe report structure was accepted.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFailedAuditRollsBackTheReportDefinitionNameVersionAndHistory(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $id = EntityId::generate();
        $query = $this->query();
        $this->service($h)->save($id, $id, -1, "Original fictional report", $query);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional report audit failure"));
        $failed = $this->service($h, $audit);
        $newId = EntityId::generate();
        foreach ([$id, $newId] as $target) {
            try {
                $failed->save($target, EntityId::generate(), $target === $id ? 0 : -1, "Interrupted change", $query);
                self::fail("An unaudited report change committed.");
            } catch (RuntimeException $e) {
                self::assertSame("Fictional report audit failure", $e->getMessage());
            }
        }
        $report = $this->service($h)->view($id);
        self::assertSame("Original fictional report", $report["name"]);
        self::assertSame(0, $report["version"]);
        self::assertCount(1, $f->sql->rows("sis_report_query_changes"));
        self::assertSame([], $f->sql->rows("sis_reports", ["id" => $newId->value]));
        self::assertSame([], $f->sql->rows("sis_report_queries", ["id" => $newId->value]));
    }

    public function testDateAndTextSummariesRetainTheirTypesInSummaryConditions(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $f->service->register($f->student()->id, $f->section(20, 0)->id);
        $query = [
            "dataset" => "credits",
            "columns" => [
                ["field" => "term_starts_on", "label" => "Earliest term", "aggregate" => "min"],
                ["field" => "full_name", "label" => "Last name alphabetically", "aggregate" => "max"],
            ],
            "having" => [
                "condition" => "AND",
                "rules" => [
                    ["id" => "c0", "operator" => "greater_or_equal", "value" => "2026-08-01"],
                    ["id" => "c1", "operator" => "contains", "value" => "Example"],
                ],
            ],
        ];
        $service = $this->service($h);
        self::assertSame([["c0" => "2026-08-01", "c1" => "Alex Example"]], $service->preview($query, 0)["items"]);
        $query["having"]["rules"][0]["value"] = "2027-01-01";
        self::assertSame([], $service->preview($query, 0)["items"]);
    }
}
