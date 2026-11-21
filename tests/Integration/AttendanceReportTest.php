<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Reporting\Enum\StudentPopulationAction;
use Domain\Reporting\Service\RelationalReportService;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRelationalReportRepository;
use Infrastructure\Persistence\Repository\SqlReportRepository;
use Infrastructure\Reporting\AcademicReportSchema;
use Infrastructure\Reporting\ReportSelectCompiler;
use Infrastructure\Reporting\SqlRelationalReportSource;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AttendanceReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(SisFixture $s, ?AuditTrail $audit = null): RelationalReportService
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
        $section = $s->section(faculty: $s->access->actor);
        $s->service->register($student->id, $section->id);
        $g = $s->gradebook();
        $day = new DateTimeImmutable("2026-08-11");
        $g->attendance($student->id, $section->id, $day, "absent");
        $g->attendance($student->id, $section->id, $day->modify("+1 day"), "late");
        return [$s, $student->id, $section->id, $day];
    }

    private function definition(
        array $fields = ["institutional_id", "section_code", "held_on", "attendance_status"],
    ): array {
        return [
            "dataset" => "attendance",
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

    private function followUp(): array
    {
        return $this->definition() + [
            "filters" => [
                "condition" => "AND",
                "rules" => [
                    [
                        "id" => "attendance_status",
                        "operator" => "in",
                        "value" => ["absent", "late"],
                    ],
                ],
            ],
        ];
    }

    private function reject(callable $action, string $type): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            self::assertInstanceOf($type, $e);
            return;
        }
        self::fail("An invalid attendance report action was accepted.");
    }

    private function protectedFacts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_attendance",
            "sis_scores",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_campaigns",
            "sis_deliveries",
            "sis_campaign_dispatches",
        ]);
    }

    public function testReportsUseOnlyRecordedMarksAndExplicitCalendarFiltersWithoutChangingFacts(): void
    {
        [$s, $student, $section, $day] = $this->fixture();
        // An active student without marks is not an inferred absence.
        $unmarked = $s->student($s->user());
        $s->service->register($unmarked->id, $section);
        $s->gradebook()->attendance($student, $section, $day->modify("+2 days"), "excused");
        $s->gradebook()->attendance($student, $section, $day->modify("+3 days"), "present");
        $before = $this->protectedFacts($s);
        $service = $this->service($s);
        $catalog = array_column($service->catalog()["datasets"], null, "id")["attendance"];
        self::assertArrayNotHasKey("registration_status", $catalog["fields"]);
        self::assertArrayNotHasKey("person_application_status", $catalog["fields"]);
        foreach ($catalog["fields"] as $field) {
            self::assertArrayHasKey("relation", $field);
        }
        $rows = $service->preview($this->definition(), 0)["items"];
        self::assertCount(4, $rows);
        self::assertSame(["absent", "late", "excused", "present"], array_column($rows, "c3"));
        $d = $this->followUp();
        $d["filters"]["rules"][] = [
            "id" => "held_on",
            "operator" => "less_or_equal",
            "value" => "2026-08-11",
        ];
        self::assertSame(["absent"], array_column($service->preview($d, 0)["items"], "c3"));
        $d = $this->definition(["held_on", "attendance_status", "attendance_id", "student_id"]);
        $d["columns"][0]["date_bucket"] = "month";
        $d["columns"][2]["aggregate"] = $d["columns"][3]["aggregate"] = "count_distinct";
        $summary = $service->preview($d, 0)["items"];
        self::assertCount(4, $summary);
        self::assertSame(["2026-08"], array_values(array_unique(array_column($summary, "c0"))));
        self::assertSame([1, 1, 1, 1], array_column($summary, "c2"));
        self::assertSame($before, $this->protectedFacts($s));
    }

    public function testClosedDeletedAndOtherSectionRegistrationsCannotBeReopenedByOrFiltersOrLeftJoins(): void
    {
        foreach (["D", "W", "V", "C", "deleted", "other_section"] as $state) {
            [$s, $student, $section] = $this->fixture();
            if ($state === "deleted") {
                $s->sql->execute("DELETE FROM sis_enrollments WHERE student_id = ?", [
                    $student->value,
                ]);
            } elseif ($state === "other_section") {
                $other = $s->section();
                $s->sql->update(
                    "sis_enrollments",
                    ["section_id" => $other->id->value],
                    ["student_id" => $student->value],
                );
            } else {
                $s->sql->update(
                    "sis_enrollments",
                    ["status" => $state],
                    ["student_id" => $student->value],
                );
            }
            $d = $this->definition();
            $d["joins"] = ["student" => "left", "section" => "left"];
            $d["filters"] = [
                "condition" => "OR",
                "rules" => [
                    ["id" => "attendance_status", "operator" => "equal", "value" => "absent"],
                    ["id" => "attendance_status", "operator" => "equal", "value" => "late"],
                ],
            ];
            $before = $this->protectedFacts($s);
            self::assertSame([], $this->service($s)->preview($d, 0)["items"]);
            self::assertSame($before, $this->protectedFacts($s));
        }
    }

    public function testLiveSavedFollowUpDeduplicatesStudentsAndRechecksCorrectionsBeforeMessaging(): void
    {
        [$s, $student, $section, $day] = $this->fixture();
        $reports = $this->service($s);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional attendance follow-up", $this->followUp());
        $before = $this->protectedFacts($s);
        self::assertContains("messaging", $reports->view($id)["student_actions"]);
        $page = $reports->population($id, 0, StudentPopulationAction::Messaging);
        self::assertSame([$student->value], array_column($page["items"], "id"));
        self::assertCount(
            1,
            $reports->selectStudents($id, [$student->value], 0, StudentPopulationAction::Messaging)[
                "items"
            ],
        );
        self::assertSame($before, $this->protectedFacts($s));
        $person = $s->admissions->person($s->admissions->student($student)->personId);
        $s->sql->update(
            "users",
            ["email" => "fictional-attendance@example.invalid"],
            ["user_id" => $person->ownerUserId->value],
        );
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
        $recipients = $campaigns->studentRecipients([new EntityId($page["items"][0]["id"])])["items"];
        self::assertCount(1, $recipients);
        self::assertSame($person->ownerUserId->value, $recipients[0]["user_id"]);
        self::assertArrayNotHasKey("email", $recipients[0]);
        self::assertSame($before, $this->protectedFacts($s));
        // Real monotonic corrections remove both matching marks; the old shortlist must fail.
        foreach ($s->gradebook()->attendanceHistory($student, $section)["items"] as $mark) {
            $s->gradebook()->attendance(
                $student,
                $section,
                new DateTimeImmutable($mark["held_on"]),
                "present",
                $mark["revision"],
            );
        }
        self::assertSame(
            [],
            $reports->population($id, 0, StudentPopulationAction::Messaging)["items"],
        );
        $this->reject(
            fn() => $reports->selectStudents(
                $id,
                [$student->value],
                0,
                StudentPopulationAction::Messaging,
            ),
            ConcurrentModificationException::class,
        );
    }

    public function testRealEarlyDropExcludesLiveMarksButPreservesAuthorizedStaticCaptureAndWorkingRows(): void
    {
        [$s, $student, $section] = $this->fixture();
        $reports = $this->service($s);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional fixed attendance review", $this->definition());
        $s->reportLifecycles()->configure($id, EntityId::generate(), 0, "static", null);
        $marks = $s->sql->rows("sis_attendance");
        $r = $s->drops->review($student, $section);
        $s->drops->commit(
            $student,
            $section,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["effective_on"],
        );
        self::assertSame([], $reports->preview($this->definition(), 0)["items"]);
        self::assertCount(2, $reports->run($id, 0)["items"]);
        self::assertSame([], $reports->view($id)["student_actions"]);
        $this->reject(
            fn() => $reports->population($id, 0, StudentPopulationAction::Messaging),
            ValidationException::class,
        );
        self::assertSame($marks, $s->sql->rows("sis_attendance"));
    }

    public function testGlobalSourceDutiesRemainNecessaryForOwnersFacultyAndSharedOrStaticReports(): void
    {
        [$s] = $this->fixture();
        $reports = $this->service($s);
        $id = EntityId::generate();
        $reports->save(
            $id,
            $id,
            -1,
            "Fictional shared attendance",
            $this->definition(["attendance_id"]),
        );
        $recipient = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $recipient);
        $s->access->staff = false;
        foreach ([["sis:student:read"], ["sis:gradebook:manage"], []] as $grants) {
            $s->access->grants = ["sis:reporting:run", "sis:reporting:manage", ...$grants];
            // Even an assigned teacher does not acquire institution-wide reporting authority.
            self::assertNotContains(
                "attendance",
                array_column($reports->catalog()["datasets"], "id"),
            );
            $this->reject(
                fn() => $reports->preview($this->definition(["attendance_id"]), 0),
                AccessDeniedException::class,
            );
        }
        $s->access->actor = $recipient;
        $s->access->grants = ["sis:reporting:run", "sis:student:read"];
        $this->reject(fn() => $reports->run($id, 0), AccessDeniedException::class);
        $s->access->grants[] = "sis:gradebook:manage";
        self::assertCount(2, $reports->run($id, 0)["items"]);
        $s->access->grants[] = "sis:reporting:manage";
        // The recipient still cannot change the report without its ownership/share-management contract.
        $this->reject(
            fn() => $reports->save(
                $id,
                EntityId::generate(),
                0,
                "Unauthorized edit",
                $this->definition(),
            ),
            AccessDeniedException::class,
        );
    }

    public function testPermissionsAreRecheckedAfterReadingAndSourceContainsNoPrivateEvidence(): void
    {
        [$s] = $this->fixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willReturnCallback(static function () use ($s): void {
            $s->access->staff = false;
            $s->access->grants = [];
        });
        $this->reject(
            fn() => $this->service($s, $audit)->preview($this->definition(), 0),
            AccessDeniedException::class,
        );
        $fields = AcademicReportSchema::datasets()["attendance"]["fields"];
        foreach (
            [
                "reason",
                "actor_id",
                "email",
                "medical_notes",
                "version",
                "sealed_event",
                "encrypted_content",
            ] as $key
        ) {
            self::assertArrayNotHasKey($key, $fields);
        }
    }

    public function testActiveBoundaryAndDateBucketsCompileForAllFiveDrivers(): void
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
                ->willReturnCallback(static fn(string $v): string => '"' . $v . '"');
            $db = $this->createStub(\Qubus\Expressive\Database::class);
            $db->method("getConnection")->willReturn($connection);
            $d = $this->definition(["held_on", "attendance_id"]);
            $d["columns"][0]["date_bucket"] = "month";
            $d["columns"][1]["aggregate"] = "count_distinct";
            $plan = new ReportSelectCompiler(
                new \Infrastructure\Persistence\SqlRecords($db),
            )->compile(new ReportQuery($d, AcademicReportSchema::datasets()["attendance"]));
            self::assertStringContainsString("EXISTS (SELECT 1", $plan["sql"]);
            self::assertStringContainsString("attendance_enrollment", $plan["sql"]);
            self::assertStringContainsString("IN ('A', 'N')", $plan["sql"]);
            self::assertStringContainsString("COUNT(DISTINCT", $plan["sql"]);
            self::assertStringNotContainsString("sis_history_events", $plan["sql"]);
            self::assertSame([], $plan["parameters"]);
        }
    }
    public function testStaticSharedResultsStillRequireCurrentAttendanceSourceDuties(): void
    {
        [$s] = $this->fixture();
        $reports = $this->service($s);
        $id = EntityId::generate();
        $reports->save($id, $id, -1, "Fictional static attendance", $this->definition());
        $s->reportLifecycles()->configure($id, EntityId::generate(), 0, "static", null);
        $reader = $s->user();
        new SqlReportRepository($s->sql, $s->clock)->share($id, $reader);
        $s->access->actor = $reader;
        $s->access->staff = false;
        $s->access->grants = ["sis:reporting:run", "sis:student:read"];
        $this->reject(fn() => $reports->run($id, 0), AccessDeniedException::class);
        $s->access->grants[] = "sis:gradebook:manage";
        self::assertCount(2, $reports->run($id, 0)["items"]);
        $s->access->grants = ["sis:reporting:run", "sis:gradebook:manage"];
        $this->reject(fn() => $reports->run($id, 0), AccessDeniedException::class);
    }

    public function testDetailPagingAndDuplicateNamedJoinsPreserveOneMarkPerClassDate(): void
    {
        [$s, $student, $section, $day] = $this->fixture();
        for ($i = 2; $i < 52; $i++) {
            $s->gradebook()->attendance(
                $student,
                $section,
                $day->modify("+" . $i . " days"),
                "present",
            );
        }
        $d = $this->definition(["attendance_id", "held_on", "full_name"]);
        $d["aliases"] = ["copy1" => ["relation" => "person", "label" => "Second name branch"]];
        $d["columns"][] = [
            "field" => "copy1__full_name",
            "label" => "Second name",
            "aggregate" => "none",
        ];
        $page = $this->service($s)->preview($d, 0);
        self::assertCount(50, $page["items"]);
        self::assertSame(50, $page["next_offset"]);
        $last = $this->service($s)->preview($d, 50);
        self::assertCount(2, $last["items"]);
        self::assertNull($last["next_offset"]);
        self::assertCount(
            52,
            array_unique(array_column([...$page["items"], ...$last["items"]], "c0")),
        );
    }
    public function testCompleteEncryptedExportKeepsTheSameLiveRegistrationBoundary(): void
    {
        [$s, $student] = $this->fixture();
        $source = new SqlRelationalReportSource($s->sql);
        $builder = new \Infrastructure\Reporting\SqlReportExportCapture(
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
        $query = new ReportQuery($this->definition(), $source->datasets()["attendance"]);
        $before = $this->protectedFacts($s);
        $artifact = $builder->capture(EntityId::generate(), EntityId::generate(), $query, null);
        self::assertSame(2, $artifact->rows);
        self::assertStringNotContainsString("2026-08-11", implode("", $artifact->chunks));
        self::assertSame($before, $this->protectedFacts($s));
        // A fictional closed current projection must remain excluded by the complete-export reader too.
        $s->sql->update("sis_enrollments", ["status" => "W"], ["student_id" => $student->value]);
        self::assertSame(
            0,
            $builder->capture(EntityId::generate(), EntityId::generate(), $query, null)->rows,
        );
        self::assertFalse($s->connection->pdo->inTransaction());
    }
}
