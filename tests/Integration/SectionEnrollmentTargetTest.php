<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Service\CatalogEditor;
use Domain\Registration\Section;
use Domain\Registration\Service\SectionEnrollmentReviewService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlSectionEnrollmentReviewRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class SectionEnrollmentTargetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function target(SisFixture $s, Section $section, int $minimum, bool $active = true): void
    {
        $editor = new CatalogEditor(
            new SqlCatalogRevisionRepository($s->sql),
            $s->registration,
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $editor->section(
            new Section(
                $section->id,
                $section->courseId,
                $section->termId,
                null,
                $section->code,
                $section->capacity,
                $active,
                true,
                $section->fee,
                minimumEnrollment: $minimum,
            ),
            $editor->view("sections", $section->id)["revision"],
        );
    }

    private function reader(SisFixture $s): SectionEnrollmentReviewService
    {
        return new SectionEnrollmentReviewService(
            new SqlSectionEnrollmentReviewRepository($s->sql),
            $s->access,
        );
    }

    /** Fictional earlier current rows, with no invented academic or registration history. */
    private function current(SisFixture $s, Section $section, string $status): void
    {
        $student = $s->student();
        $s->sql->insert("sis_enrollments", [
            "id" => EntityId::generate()->value,
            "student_id" => $student->id->value,
            "section_id" => $section->id->value,
            "term_id" => $section->termId->value,
            "status" => $status,
            "registered_at" => "2026-08-10 12:00:00",
            "status_at" => "2026-08-10 12:00:00",
            "version" => 0,
        ]);
    }

    private function definition(
        array $fields = ["section_code", "active_enrollment_count", "minimum_enrollment"],
    ): array {
        return [
            "dataset" => "sections",
            "columns" => array_map(
                static fn(string $field): array => [
                    "field" => $field,
                    "label" => $field,
                    "aggregate" => "none",
                ],
                $fields,
            ),
            "joins" => [],
            "filters" => null,
            "having" => null,
            "sort" => [],
        ];
    }

    private function report(SisFixture $s, array $definition): array
    {
        return new SqlRelationalReportSource($s->sql)->execute(
            new ReportQuery($definition, AcademicReportSchema::datasets()["sections"]),
            0,
        );
    }

    public function testCatalogTargetsAreValidatedAndDoNotCreateAcademicOrFinancialDecisions(): void
    {
        $s = new SisFixture();
        $section = $s->section(20, 0);
        $before = $s->sql->rows("sis_history_events");
        $this->target($s, $section, 12);
        self::assertSame(12, $s->registration->section($section->id)->minimumEnrollment);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame([], $s->sql->rows("sis_enrollments"));
        self::assertSame([], $s->sql->rows("sis_student_accounts"));
        foreach ([-1, 21] as $invalid) {
            try {
                $this->target($s, $section, $invalid);
                self::fail("An invalid enrollment target was accepted.");
            } catch (\InvalidArgumentException) {
                self::assertSame(12, $s->registration->section($section->id)->minimumEnrollment);
            }
        }
    }

    public function testWorklistCountsOnlyActiveRegistrationsAndFiltersBeforePagingWithoutHistoryWrites(): void
    {
        $s = new SisFixture();
        $empty = $s->section(20, 0);
        $met = $s->section(20, 0);
        $unset = $s->section(20, 0);
        $inactive = $s->section(20, 0);
        $this->target($s, $empty, 2);
        $this->target($s, $met, 2);
        $this->target($s, $inactive, 2, false);
        foreach (["A", "N", "D", "W", "V"] as $status) {
            $this->current($s, $met, $status);
        }
        $before = $s->sql->rows("sis_history_events");
        $reader = $this->reader($s);
        $below = $reader->page(term: $s->term);
        self::assertSame([$empty->id->value], array_column($below["items"], "id"));
        self::assertSame(0, $below["items"][0]["enrolled"]);
        self::assertNull($below["items"][0]["published_minimum"]);
        self::assertSame([$met->id->value], array_column($reader->page(state: "met")["items"], "id"));
        self::assertSame(2, $reader->page(state: "met")["items"][0]["enrolled"]);
        self::assertSame([$unset->id->value], array_column($reader->page(state: "unset")["items"], "id"));
        self::assertCount(2, $reader->page(includeInactive: true)["items"]);
        self::assertSame([], $reader->page(search: "%")["items"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testReviewIsBoundedAndRequiresCatalogOrRegistrationDuty(): void
    {
        $s = new SisFixture();
        for ($index = 0; $index < 52; $index++) {
            $section = $s->section(20, 0);
            $this->target($s, $section, 12);
        }
        $s->access->staff = false;
        $s->access->grants = ["sis:registration:manage"];
        $reader = $this->reader($s);
        $page = $reader->page();
        self::assertCount(50, $page["items"]);
        self::assertCount(2, $reader->page(after: new EntityId($page["next_after"]))["items"]);
        $s->access->grants = ["sis:catalog:manage"];
        self::assertCount(50, $reader->page()["items"]);
        $s->access->grants = ["sis:student:read"];
        $this->expectException(AccessDeniedException::class);
        $reader->page();
    }

    public function testReviewRechecksAuthorityAfterTheProjectionRead(): void
    {
        $s = new SisFixture();
        $records = $this->createStub(\Domain\Registration\Repository\SectionEnrollmentReviewRepository::class);
        $records->method('page')->willReturnCallback(static function () use ($s): array {
            $s->access->staff = false;
            $s->access->grants = [];
            return ['items' => [], 'next_after' => null];
        });
        $this->expectException(AccessDeniedException::class);
        new SectionEnrollmentReviewService($records, $s->access)->page();
    }

    public function testReportsIncludeZeroEnrollmentAndExcludeClosedRegistrationsWithNoStudentReadDuty(): void
    {
        $s = new SisFixture();
        $empty = $s->section(20, 0);
        $met = $s->section(20, 0);
        $this->target($s, $empty, 2);
        $this->target($s, $met, 2);
        foreach (["A", "N", "D", "W"] as $status) {
            $this->current($s, $met, $status);
        }
        $definition = $this->definition();
        $rows = $this->report($s, $definition);
        $counts = array_column($rows, "c1", "c0");
        self::assertEquals([$empty->code => 0, $met->code => 2], $counts);
        $unset = $s->section(20, 0);
        $flags = $this->report($s, $this->definition(['section_code', 'below_enrollment_target']));
        self::assertEquals(
            [$empty->code => true, $met->code => false, $unset->code => null],
            array_column($flags, 'c1', 'c0')
        );

        $definition["filters"] = [
            "condition" => "AND",
            "rules" => [["id" => "below_enrollment_target", "operator" => "equal", "value" => true]],
        ];
        self::assertSame($empty->code, $this->report($s, $definition)[0]["c0"]);
        $definition["filters"]["rules"][0]["value"] = false;
        self::assertSame($met->code, $this->report($s, $definition)[0]["c0"]);
        $definition["filters"]["rules"][0] = ["id" => "below_enrollment_target", "operator" => "is_null"];
        self::assertSame($unset->code, $this->report($s, $definition)[0]["c0"]);
        $definition["filters"] = [
            "condition" => "AND",
            "rules" => [["id" => "active_enrollment_count", "operator" => "less", "value" => 12]],
        ];
        self::assertCount(3, $this->report($s, $definition));
        $definition = $this->definition(["term_code", "active_enrollment_count"]);
        $definition["columns"][1]["aggregate"] = "sum";
        self::assertSame(2, $this->report($s, $definition)[0]["c1"]);
    }

    public function testReportPermissionsAllowCountsWithoutGrantingAccessToStudentRows(): void
    {
        $s = new SisFixture();
        $section = $s->section(20, 0);
        $this->target($s, $section, 12);
        $s->access->staff = false;
        $s->access->grants = ['sis:catalog:manage', 'sis:reporting:manage', 'sis:reporting:run'];
        $service = new \Domain\Reporting\Service\RelationalReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            new SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles()
        );
        $sources = array_column($service->catalog()['datasets'], null, 'id');
        self::assertArrayHasKey('active_enrollment_count', $sources['sections']['fields']);
        self::assertArrayNotHasKey('enrollment_id', $sources['sections']['fields']);
        self::assertCount(1, $service->preview($this->definition(), 0)['items']);
        $definition = $this->definition(['section_code', 'enrollment_id']);
        $this->expectException(AccessDeniedException::class);
        $service->preview($definition, 0);
    }

    public function testSectionTotalsRejectJoinsThatWouldMultiplyTheirGrain(): void
    {
        $definition = $this->definition(["term_code", "active_enrollment_count"]);
        $definition["columns"][1]["aggregate"] = "sum";
        $definition["joins"] = ["enrollment" => "left"];
        $this->expectException(ValidationException::class);
        new ReportQuery($definition, AcademicReportSchema::datasets()["sections"]);
    }

    public function testCounterExpressionsCompileForEverySupportedDialect(): void
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
                ->willReturnCallback(
                    static fn(string $name): string => $driver === "mysql"
                        ? "`" . $name . "`"
                        : ($driver === "sqlsrv"
                            ? "[" . $name . "]"
                            : '"' . $name . '"'),
                );
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method("getConnection")->willReturn($connection);
            $definition = $this->definition([
                "section_code",
                "active_enrollment_count",
                "below_enrollment_target",
            ]);
            $plan = new \Infrastructure\Reporting\ReportSelectCompiler(
                new \Infrastructure\Persistence\SqlRecords($db),
            )->compile(new ReportQuery($definition, AcademicReportSchema::datasets()["sections"]));
            self::assertStringContainsString("COUNT(*)", $plan["sql"]);
            self::assertStringContainsString("CASE WHEN", $plan["sql"]);
            self::assertStringNotContainsString("sis_history_events", $plan["sql"]);
        }
    }
}
