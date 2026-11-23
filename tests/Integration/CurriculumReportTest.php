<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Reporting\Service\RelationalReportService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use StudentSpecializationRequirementsFixture;

final class CurriculumReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/StudentSpecializationRequirementsFixture.php";
    }
    private function fixture(): StudentSpecializationRequirementsFixture
    {
        $f = new StudentSpecializationRequirementsFixture(
            componentKinds: [
                "DATA" => \Domain\Catalog\ValueObject\CurriculumComponentKind::Major,
                "ART" => \Domain\Catalog\ValueObject\CurriculumComponentKind::Concentration,
            ],
            kindLimits: [
                "major" => ["minimum" => 1, "maximum" => 1],
                "concentration" => ["minimum" => 0, "maximum" => 1],
            ],
            organized: true,
            parentMajorCodes: ["ART" => "DATA"],
        );
        $f->select(["DATA", "ART"]);
        return $f;
    }
    private function reports(StudentSpecializationRequirementsFixture $f): RelationalReportService
    {
        $s = $f->r->sis;
        return new RelationalReportService(
            new SqlReportRepository($s->sql, $s->clock),
            new SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
    }
    private function definition(
        string $source = "selected_components",
        array $fields = [
            "component_code",
            "component_kind",
            "department_title",
            "school_title",
            "parent_major_code",
        ],
    ): array {
        return [
            "dataset" => $source,
            "columns" => array_map(
                static fn(string $key): array => [
                    "field" => $key,
                    "label" => $key,
                    "aggregate" => "none",
                ],
                $fields,
            ),
            "sort" => [["column" => 0, "direction" => "asc"]],
        ];
    }
    public function testCurrentOwnershipAndRetainedStudentLabelsHaveIndependentSources(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $reports = $this->reports($f);
        $before = $s->sql->rows("sis_history_events");
        $rows = $reports->preview($this->definition(), 0)["items"];
        self::assertCount(2, $rows);
        self::assertSame(
            ["ART", "concentration", "Fictional ART department", "Fictional Arts college", "DATA"],
            array_values($rows[0]),
        );
        $s->sql->update(
            "sis_program_specializations",
            ["title" => "Current renamed component"],
            ["id" => $f->choices["ART"]["id"]],
        );
        $s->sql->update(
            "sis_catalog_classifications",
            ["title" => "Current renamed department"],
            ["id" => $f->choices["ART"]["department"]["id"]],
        );
        self::assertSame($rows, $reports->preview($this->definition(), 0)["items"]);
        $current = $reports->preview(
            $this->definition("catalog_components", [
                "component_code",
                "component_title",
                "department_title",
            ]),
            0,
        )["items"];
        self::assertSame("Current renamed component", $current[0]["c1"]);
        self::assertSame("Current renamed department", $current[0]["c2"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }
    public function testDistinctStudentsDoNotBecomeAComponentCountAndRemovalPreservesHistory(): void
    {
        $f = $this->fixture();
        $reports = $this->reports($f);
        $s = $f->r->sis;
        $d = [
            "dataset" => "selected_components",
            "columns" => [
                ["field" => "student_id", "label" => "Students", "aggregate" => "count_distinct"],
                ["field" => "component_row_id", "label" => "Components", "aggregate" => "count"],
            ],
        ];
        self::assertSame([["c0" => 1, "c1" => 2]], $reports->preview($d, 0)["items"]);
        $before = count($s->sql->rows("sis_history_events"));
        $f->assign("DATA");
        self::assertSame([["c0" => 1, "c1" => 2]], $reports->preview($d, 0)["items"]);
        $f->select(["DATA"]);
        self::assertSame([["c0" => 1, "c1" => 1]], $reports->preview($d, 0)["items"]);
        self::assertGreaterThan($before, count($s->sql->rows("sis_history_events")));
    }
    public function testPermissionGatesApplyToFieldsAndSharedStaticReports(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $reports = $this->reports($f);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional curriculum report", $this->definition());
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->reportLifecycles()->configure($id, EntityId::generate(), 0, "static", null);
        $f->select(["DATA"]);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:run", "sis:student:read"];
        self::assertCount(2, $reports->run($id, 0)["items"]);
        $s->access->grants = ["sis:reporting:run"];
        $this->expectException(AccessDeniedException::class);
        $reports->run($id, 0);
    }
    public function testCurrentCatalogPermissionCannotReadStudentSelectionsAndPrivateFieldsAreUnavailable(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:manage", "sis:reporting:run", "sis:catalog:manage"];
        self::assertCount(
            2,
            $this->reports($f)->preview(
                $this->definition("catalog_components", ["component_code"]),
                0,
            )["items"],
        );
        $this->expectException(AccessDeniedException::class);
        $this->reports($f)->preview($this->definition(), 0);
    }
    public function testMissingRowsBlockReportsAndSelectedReadsRatherThanReturningPartialTotals(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $s->sql->execute("DELETE FROM sis_student_curriculum_components WHERE component_code = ?", [
            "ART",
        ]);
        try {
            $this->reports($f)->preview($this->definition(), 0);
            self::fail("A partial curriculum report escaped.");
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $this->expectException(CorruptHistoryException::class);
        $f->service->view($f->student, $f->assignment);
    }
    public function testFailedSelectionAuditRollsBackNormalizedRowsAndProtectedHistory(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $before = array_map($s->sql->rows(...), [
            "sis_student_curriculum_components",
            "sis_student_specializations",
            "sis_history_events",
            "sis_history_operations",
            "sis_audit",
        ]);
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit
            ->expects(self::once())
            ->method("record")
            ->willThrowException(new \RuntimeException("Fictional required audit failure."));
        $service = $f->service(audit: $audit);
        $r = $service->review(
            $f->student,
            $f->assignment,
            $f->service->view($f->student, $f->assignment)["revision"],
            [$f->choices["DATA"]["id"]],
            "Fictional atomic selection.",
        );
        try {
            $service->commit($f->student, $f->assignment, $r["review_token"]);
            self::fail("The audit failure escaped rollback.");
        } catch (\RuntimeException) {
            self::assertSame(
                $before,
                array_map($s->sql->rows(...), [
                    "sis_student_curriculum_components",
                    "sis_student_specializations",
                    "sis_history_events",
                    "sis_history_operations",
                    "sis_audit",
                ]),
            );
        }
    }
    public function testEarlierUnrecordedFieldsRemainUnknownInDerivedRows(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $projection = new \Infrastructure\Persistence\Repository\SqlStudentSpecializationRepository(
            $s->sql,
        )->get($f->assignment);
        $program = new \Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository(
            $s->sql,
        )->retained(new EntityId($f->programVersion));
        unset($program["specializations"]["kind_limits"]);
        foreach ($program["specializations"]["offered"] as &$choice) {
            unset($choice["kind"], $choice["department"], $choice["parent_major"], $choice['additional_departments']);
        }
        unset($choice);
        $rows = \Domain\Student\ValueObject\SelectedCurriculumRows::from(
            $projection["payload"],
            $program,
            $projection["position"],
        );
        foreach ($rows as $row) {
            self::assertSame(0, $row["kind_recorded"]);
            self::assertNull($row["component_kind"]);
            self::assertSame(0, $row["department_recorded"]);
            self::assertNull($row["department_title"]);
            self::assertSame(0, $row["parent_major_recorded"]);
            self::assertNull($row["parent_major_code"]);
        }
    }
    public function testCohortComparisonAndFiltersExposeAnUnreviewedBindingChange(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $reports = $this->reports($f);
        $d = $this->definition(fields: ["component_code", "cohort_matches"]);
        self::assertSame([true, true], array_column($reports->preview($d, 0)["items"], "c1"));
        $repo = new \Infrastructure\Persistence\Repository\SqlCatalogYearRepository($s->sql);
        $s->transactions->run(function () use ($s, $f, $repo): void {
            $s->sql->lock("sis_students", $f->student->value, "id");
            $repo->bind(
                $f->assignment,
                new EntityId($f->programVersion),
                $repo->binding($f->assignment)["version"],
            );
        });
        self::assertSame([false, false], array_column($reports->preview($d, 0)["items"], "c1"));
        $d["filters"] = [
            "condition" => "AND",
            "rules" => [["id" => "cohort_matches", "operator" => "equal", "value" => true]],
        ];
        self::assertSame([], $reports->preview($d, 0)["items"]);
    }
    public function testPrivateHistoryFieldsCannotBeSelectedOrUsedOnlyInFilters(): void
    {
        $f = $this->fixture();
        $reports = $this->reports($f);
        foreach (
            ["reason", "actor_id", "selected_json", "reference_json", "owner_user_id"] as $field
        ) {
            $d = $this->definition();
            $d["filters"] = [
                "condition" => "AND",
                "rules" => [["id" => $field, "operator" => "is_null"]],
            ];
            try {
                $reports->preview($d, 0);
                self::fail("A protected field was accepted by the report.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testFinalProjectionOrAuthorityChangeRollsBackTheEntireSelection(): void
    {
        foreach (["projection", "authority"] as $failure) {
            $f = $this->fixture();
            $s = $f->r->sis;
            $tables = [
                "sis_student_curriculum_components",
                "sis_student_specializations",
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_audit",
            ];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
            $audit
                ->expects(self::once())
                ->method("record")
                ->willReturnCallback(static function () use ($s, $failure): void {
                    if ($failure === "authority") {
                        $s->access->staff = false;
                    } else {
                        $s->sql->execute("DELETE FROM sis_student_curriculum_components");
                    }
                });
            $service = $f->service(audit: $audit);
            $r = $service->review(
                $f->student,
                $f->assignment,
                $f->service->view($f->student, $f->assignment)["revision"],
                [$f->choices["DATA"]["id"]],
                "Fictional final validation.",
            );
            try {
                $service->commit($f->student, $f->assignment, $r["review_token"]);
                self::fail("A final failure was accepted.");
            } catch (CorruptHistoryException | AccessDeniedException) {
                self::assertSame($before, array_map($s->sql->rows(...), $tables));
            }
        }
    }
    public function testOrphanedComponentRowsCannotEnterAReport(): void
    {
        $f = $this->fixture();
        $s = $f->r->sis;
        $row = $s->sql->rows('sis_student_curriculum_components', [], 1)[0];
        $row['id'] = EntityId::generate()->value;
        $row['assignment_id'] = EntityId::generate()->value;
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        $s->sql->insert('sis_student_curriculum_components', $row);
        $s->connection->pdo->exec('PRAGMA foreign_keys = ON');
        $this->expectException(ValidationException::class);
        $this->reports($f)->preview($this->definition(), 0);
    }
}
