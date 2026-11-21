<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\Service\ReportSnapshotReader;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingOccurrence;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportLifecycleRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use Infrastructure\Reporting\SqlReportExportCapture;
use Infrastructure\Reporting\SqlReportResultReader;
use PHPUnit\Framework\MockObject\Runtime\PropertyHook;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class CalendarReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(SisFixture $s): RelationalReportService
    {
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

    private function definition(string $dataset = "meetings", string $bucket = "month"): array
    {
        $date = $dataset === "applications" ? "submitted_on" : "starts_at";
        $identity = $dataset === "applications" ? "application_id" : "meeting_id";
        return [
            "dataset" => $dataset,
            "columns" => [
                ["field" => $date, "label" => "Period", "aggregate" => "none", "date_bucket" => $bucket],
                ["field" => $identity, "label" => "Records", "aggregate" => "count_distinct"],
            ],
            "sort" => [["column" => 0, "direction" => "asc"]],
        ];
    }

    private function meeting(SisFixture $s, string $starts, string $status = "approved"): void
    {
        $at = new DateTimeImmutable($starts);
        new SqlBookingRepository($s->sql)->add(
            new Booking(
                EntityId::generate(),
                null,
                $s->access->actor,
                "Fictional meeting",
                [BookingOccurrence::plan(new TimeSlot($at, $at->modify("+1 hour")))],
                $status,
            ),
        );
    }

    public function testUtcMeetingPeriodsKeepYearsAndOriginalTimeFiltersAndConditionalSummaries(): void
    {
        $s = new SisFixture();
        $this->meeting($s, "2025-11-01T10:00:00Z");
        $this->meeting($s, "2026-10-31T20:00:00-07:00"); // November 1 UTC.
        $this->meeting($s, "2026-11-02T10:00:00Z", "requested");
        $this->meeting($s, "2026-12-01T10:00:00Z");
        $d = $this->definition();
        $d["columns"][] = [
            "field" => "meeting_id",
            "label" => "Approved",
            "aggregate" => "count_distinct",
            "conditions" => [
                "condition" => "AND",
                "rules" => [["id" => "booking_status", "operator" => "equal", "value" => "approved"]],
            ],
        ];
        $before = $s->sql->rows("sis_history_events");
        self::assertSame(
            [
                ["c0" => "2025-11", "c1" => 1, "c2" => 1],
                ["c0" => "2026-11", "c1" => 2, "c2" => 1],
                ["c0" => "2026-12", "c1" => 1, "c2" => 1],
            ],
            $this->service($s)->preview($d, 0)["items"],
        );
        $d["filters"] = [
            "condition" => "AND",
            "rules" => [
                ["id" => "starts_at", "operator" => "greater_or_equal", "value" => "2026-11-01 04:00:00"],
            ],
        ];
        $d["having"] = [
            "condition" => "AND",
            "rules" => [["id" => "c2", "operator" => "greater", "value" => 0]],
        ];
        self::assertSame(
            [["c0" => "2026-12", "c1" => 1, "c2" => 1]],
            $this->service($s)->preview($d, 0)["items"],
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testRecordedSubmissionDatesKeepTheirBusinessCalendarAndUnknownEarlierFacts(): void
    {
        $s = new SisFixture();
        foreach (["2025-11-30T23:30:00-08:00", "2026-11-30T23:30:00-08:00"] as $time) {
            $s->clock->time = new DateTimeImmutable($time);
            $person = $s->legacyPerson($s->user(), "Fictional", "Applicant")->id;
            $s->submissionService()->apply(EntityId::generate(), $person, $s->program, $s->term);
        }
        $person = $s->legacyPerson($s->user(), "Earlier", "Unknown")->id;
        $s->sql->insert("sis_applications", [
            "id" => EntityId::generate()->value,
            "person_id" => $person->value,
            "program_id" => $s->program->value,
            "entry_term_id" => $s->term->value,
            "status" => "submitted",
            "version" => 0,
        ]);
        $before = $s->sql->rows("sis_history_events");
        $rows = $this->service($s)->preview($this->definition("applications"), 0)["items"];
        self::assertSame([null, "2025-11", "2026-11"], array_column($rows, "c0"));
        self::assertSame([1, 1, 1], array_column($rows, "c1"));
        $d = $this->definition("applications", "day");
        $d["filters"] = [
            "condition" => "AND",
            "rules" => [["id" => "submitted_on", "operator" => "is_not_null"]],
        ];
        self::assertSame(
            ["2025-11-30", "2026-11-30"],
            array_column($this->service($s)->preview($d, 0)["items"], "c0"),
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
    }

    public function testDayYearMinMaxAndDistinctPeriodsRetainOutputTypesAndSummaryFilters(): void
    {
        $s = new SisFixture();
        $this->meeting($s, "2026-11-01T10:00:00Z");
        $this->meeting($s, "2026-11-01T12:00:00Z");
        $this->meeting($s, "2027-11-02T10:00:00Z");
        $d = $this->definition(bucket: "day");
        self::assertSame(
            ["2026-11-01", "2027-11-02"],
            array_column($this->service($s)->preview($d, 0)["items"], "c0"),
        );
        $d["columns"] = [
            [
                "field" => "starts_at",
                "label" => "Earliest month",
                "aggregate" => "min",
                "date_bucket" => "month",
            ],
            ["field" => "starts_at", "label" => "Latest day", "aggregate" => "max", "date_bucket" => "day"],
            [
                "field" => "starts_at",
                "label" => "Years",
                "aggregate" => "count_distinct",
                "date_bucket" => "year",
            ],
        ];
        $query = new ReportQuery($d, AcademicReportSchema::datasets()["meetings"]);
        self::assertSame(["string", "date", "integer"], array_map($query->outputType(...), [0, 1, 2]));
        $d["having"] = [
            "condition" => "AND",
            "rules" => [["id" => "c1", "operator" => "equal", "value" => "2027-11-02"]],
        ];
        self::assertSame(
            [["c0" => "2026-11", "c1" => "2027-11-02", "c2" => 2]],
            $this->service($s)->preview($d, 0)["items"],
        );
    }

    public function testPaymentsRemainCurrencySeparatedAndCannotCombineCurrenciesAcrossMonths(): void
    {
        $s = new SisFixture();
        foreach (["USD", "JPY"] as $currency) {
            $account = StudentAccount::open(new AccountId(), $s->student()->id, $s->term, $currency);
            $account->post(
                EntityId::generate(),
                EntryType::Payment,
                new Money(2500, $currency),
                $s->access->actor,
                staffRecorded: true,
            );
            $s->saveAccount($account);
        }
        $d = [
            "dataset" => "postings",
            "columns" => [
                [
                    "field" => "posting_recorded_at",
                    "label" => "Posting month UTC",
                    "aggregate" => "none",
                    "date_bucket" => "month",
                ],
                ["field" => "currency", "label" => "Currency", "aggregate" => "none"],
                ["field" => "posting_amount", "label" => "Payments", "aggregate" => "sum"],
            ],
            "filters" => [
                "condition" => "AND",
                "rules" => [
                    ["id" => "posting_reversed", "operator" => "equal", "value" => false],
                    ["id" => "posting_type", "operator" => "equal", "value" => "payment"],
                ],
            ],
        ];
        $before = $s->sql->rows("sis_history_events");
        $rows = array_column($this->service($s)->preview($d, 0)["items"], null, "c1");
        self::assertSame(25.0, $rows["USD"]["c2"]);
        self::assertSame(2500.0, $rows["JPY"]["c2"]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/D', $rows["USD"]["c0"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        unset($d["columns"][1]);
        $d["columns"] = array_values($d["columns"]);
        $this->expectException(ValidationException::class);
        $this->service($s)->preview($d, 0);
    }

    public function testDynamicSaveShareStaticCaptureAndCompleteCsvUseTheSameCalendarDefinition(): void
    {
        $s = new SisFixture();
        $this->meeting($s, "2026-11-01T10:00:00Z");
        $d = $this->definition();
        $report = EntityId::generate();
        $service = $this->service($s);
        $service->save($report, EntityId::generate(), -1, "Fictional monthly meetings", $d);
        self::assertSame($d, $service->view($report)["definition"]);
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($report, $recipient);
        $owner = $s->access->actor;
        $s->access->actor = $recipient;
        self::assertSame([["c0" => "2026-11", "c1" => 1]], $service->run($report, 0)["items"]);
        $s->access->actor = $owner;
        $s->reportLifecycles()->configure($report, EntityId::generate(), 0, "static", null);
        $this->meeting($s, "2026-12-01T10:00:00Z");
        self::assertSame(2, count($service->preview($d, 0)["items"]));
        self::assertSame([["c0" => "2026-11", "c1" => 1]], $service->run($report, 0)["items"]);
        $capture = new SqlReportExportCapture(
            new SqlReportResultReader($s->connection->queryBuilder(), new SqlRelationalReportSource($s->sql)),
            new ReportSnapshotReader(new SqlReportLifecycleRepository($s->sql), $s->dropCipher),
            $s->dropCipher,
        );
        $job = EntityId::generate();
        $artifact = $capture->capture(
            $job,
            $report,
            new ReportQuery($d, AcademicReportSchema::datasets()["meetings"]),
            null,
        );
        $packet = json_decode($s->dropCipher->open($artifact->chunks[1]), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString("2026-11,1", $packet["content"]);
        self::assertStringContainsString("2026-12,1", $packet["content"]);
        $static = $capture->capture(
            $job,
            $report,
            new ReportQuery($d, AcademicReportSchema::datasets()["meetings"]),
            new SqlReportLifecycleRepository($s->sql)->get($report),
        );
        $packet = json_decode($s->dropCipher->open($static->chunks[1]), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString("2026-11,1", $packet["content"]);
        self::assertStringNotContainsString("2026-12", $packet["content"]);
        $s->access->actor = $recipient;
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:run"];
        $this->expectException(AccessDeniedException::class);
        $service->run($report, 0);
    }

    public function testInvalidPeriodsNonDatesCalculationsAndHiddenSourcePermissionsCannotBeBypassed(): void
    {
        $s = new SisFixture();
        foreach ([null, false, 12, "week", "month'); DROP TABLE users;--"] as $invalid) {
            $d = $this->definition();
            $d["columns"][0]["date_bucket"] = $invalid;
            try {
                new ReportQuery($d, AcademicReportSchema::datasets()["meetings"]);
                self::fail("An invalid calendar period was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows("sis_booking_slots"));
            }
        }
        foreach (["meeting_id", "room_capacity"] as $field) {
            $d = $this->definition();
            $d["columns"][0]["field"] = $field;
            try {
                new ReportQuery($d, AcademicReportSchema::datasets()["meetings"]);
                self::fail("A non-date calendar field was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows("sis_booking_slots"));
            }
        }
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:run"];
        $this->expectException(AccessDeniedException::class);
        $this->service($s)->preview($this->definition(), 0);
    }

    public function testCalendarSqlCompilesTypedNativeDatesAndUtcStringsForAllSupportedDialects(): void
    {
        foreach (["mysql", "pgsql", "sqlite", "sqlsrv", "oci"] as $driver) {
            $pdo = $this->createStub(\PDO::class);
            $pdo->method("getAttribute")->willReturn($driver);
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method(PropertyHook::get("pdo"))->willReturn($pdo);
            $connection
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method("getConnection")->willReturn($connection);
            $compiler = new ReportSelectCompiler(new \Infrastructure\Persistence\SqlRecords($db));
            foreach (["applications", "meetings"] as $dataset) {
                foreach (["day" => 10, "month" => 7, "year" => 4] as $period => $length) {
                    $d = $this->definition($dataset, $period);
                    $date = $d["columns"][0]["field"];
                    $d["filters"] = [
                        "condition" => "AND",
                        "rules" => [["id" => $date, "operator" => "is_not_null"]],
                    ];
                    $plan = $compiler->compile(
                        new ReportQuery($d, AcademicReportSchema::datasets()[$dataset]),
                    );
                    self::assertStringContainsString(", 1, " . $length . ")", $plan["sql"]);
                    self::assertStringContainsString("GROUP BY", $plan["sql"]);
                    if ($dataset === "applications" && in_array($driver, ["pgsql", "oci"], true)) {
                        self::assertStringContainsString("TO_CHAR(", $plan["sql"]);
                    }
                    self::assertSame([], $plan["parameters"]);
                }
            }
        }
    }
}
