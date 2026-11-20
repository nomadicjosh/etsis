<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\Service\AdvisorProgressAccess;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentStandingService;
use Domain\Student\ValueObject\StandingPeriods;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use Infrastructure\Persistence\Repository\SqlStudentStandingRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class StudentStandingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function service(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?AdvisorProgressAccess $advisors = null,
    ): StudentStandingService {
        $advisors ??= new class implements AdvisorProgressAccess {
            public function requireAdvisor(EntityId $student, EntityId $pursuit): EntityId
            {
                throw new AccessDeniedException();
            }
        };
        return new StudentStandingService(
            new SqlStudentStandingRepository($s->sql),
            $s->admissions,
            new SqlStudentRecordRepository($s->sql),
            $s->programStatusLedger(),
            $s->primaryProgramLedger(),
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
            $advisors,
        );
    }

    private function pursuit(SisFixture $s, EntityId $student): EntityId
    {
        return new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $student->value], 1)[0]["id"],
        );
    }

    private function row(array $definition): array
    {
        return [
            "id" => EntityId::generate()->value,
            "definition_id" => $definition["id"],
            "code" => $definition["code"],
            "title" => $definition["title"],
            "starts_on" => "2026-08-01",
            "ends_on" => null,
            "policy_reference" => "Fictional handbook 2026, section 7",
        ];
    }

    private function review(
        StudentStandingService $service,
        EntityId $student,
        EntityId $pursuit,
        array $rows,
    ): array {
        return $service->review(
            $student,
            $pursuit,
            $rows,
            $service->view($student, $pursuit)["revision"],
            "Fictional registrar determination.",
        );
    }

    private function commit(
        StudentStandingService $service,
        EntityId $student,
        EntityId $pursuit,
        array $review,
    ): array {
        return $service->commit(
            $student,
            $pursuit,
            new EntityId($review["operation_id"]),
            $review["rows"],
            $review["revision"],
            $review["reason"],
            $review["review_token"],
        );
    }

    public function testReviewedStandingIsRetainedInTheSingleJournalAndDoesNotChangeOtherAcademicFacts(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $events = $s->sql->rows("sis_history_events");
        $definition = $service->saveDefinition(null, "good", "Good standing", true, null);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        $review = $this->review($service, $student->id, $p, [$this->row($definition)]);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        $result = $this->commit($service, $student->id, $p, $review);
        self::assertSame("updated", $result["status"]);
        self::assertCount(count($events) + 1, $s->sql->rows("sis_history_events"));
        self::assertSame([], $s->sql->rows("sis_academic_credits"));
        self::assertSame([], $s->sql->rows("sis_enrollments"));
        self::assertTrue($s->admissions->student($student->id)->active);
        $framework = \Domain\Student\StudentStanding::reconstituteFromEventStream(
            \EventStoreFixture::framework($s->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\Student\ValueObject\StudentStandingId($p->value),
            ),
        );
        self::assertSame($review["rows"], $framework->rows());
        self::assertSame($result, $this->commit($service, $student->id, $p, $review));
        self::assertSame($review["reason"], $service->timeline($student->id, $p)["items"][0]["reason"]);
    }

    public function testOwnerReadPreservesAcceptedLabelsAndWithholdsReasonsAndPolicyReferences(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $definition = $service->saveDefinition(null, "PROB", "Academic review", true, null);
        $this->commit(
            $service,
            $student->id,
            $p,
            $this->review($service, $student->id, $p, [$this->row($definition)]),
        );
        $service->saveDefinition(
            new EntityId($definition["id"]),
            "PROB",
            "Renamed label",
            false,
            $definition["version"],
        );
        self::assertSame("Academic review", $service->view($student->id, $p)["rows"][0]["title"]);
        $s->access->staff = false;
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $before = $s->sql->rows("sis_history_events");
        $read = $service->read($student->id, $p);
        self::assertSame(["code", "title", "starts_on", "ends_on"], array_keys($read["items"][0]));
        self::assertSame("Academic review", $read["items"][0]["title"]);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $s->access->actor = $s->user();
        $s->access->staff = true;
        $this->expectException(AccessDeniedException::class);
        $service->read($student->id, $p);
    }

    public function testDatesAreInclusiveAndPeriodsMustNotOverlap(): void
    {
        $row = $this->row([
            "id" => EntityId::generate()->value,
            "code" => "GOOD",
            "title" => "Good standing",
        ]);
        $row["ends_on"] = "2026-08-09";
        $next = array_replace($row, [
            "id" => EntityId::generate()->value,
            "starts_on" => "2026-08-10",
            "ends_on" => null,
        ]);
        self::assertCount(2, new StandingPeriods([$next, $row])->rows);
        $next["starts_on"] = "2026-08-09";
        $this->expectException(\InvalidArgumentException::class);
        new StandingPeriods([$row, $next]);
    }

    public function testOldReceiptsDoNotReplaceLaterStandingAndRemainReadableAfterPursuitClosure(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $definition = $service->saveDefinition(null, "GOOD", "Good standing", true, null);
        $row = $this->row($definition);
        $first = $this->review($service, $student->id, $p, [$row]);
        $receipt = $this->commit($service, $student->id, $p, $first);
        $row["ends_on"] = "2026-08-09";
        $later = $this->review($service, $student->id, $p, [$row]);
        $this->commit($service, $student->id, $p, $later);
        $s->transactions->run(
            fn() => $s
                ->programStatusLedger()
                ->change(
                    new SqlStudentRecordRepository($s->sql)->assignment($p),
                    "closed",
                    EntityId::generate(),
                    $s->access->actor,
                    "Fictional program closure.",
                ),
        );
        $events = $s->sql->rows("sis_history_events");
        $s->clock->time = $s->clock->time->modify("+1 day");
        self::assertSame($receipt, $this->commit($service, $student->id, $p, $first));
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        $view = $service->view($student->id, $p);
        self::assertFalse($view["editable"]);
        self::assertSame("2026-08-09", $view["rows"][0]["ends_on"]);
        $this->expectException(AccessDeniedException::class);
        $this->review($service, $student->id, $p, []);
    }

    public function testChangedDefinitionsInvalidatePendingReviewsButRetiredPeriodsCanBeEnded(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $d = $service->saveDefinition(null, "GOOD", "Good standing", true, null);
        $row = $this->row($d);
        $pending = $this->review($service, $student->id, $p, [$row]);
        $service->saveDefinition(new EntityId($d["id"]), "GOOD", "Good standing", false, $d["version"]);
        try {
            $this->commit($service, $student->id, $p, $pending);
            self::fail("A retired definition must require another review.");
        } catch (ValidationException) {
            self::assertSame([], $service->view($student->id, $p)["rows"]);
        }
        $service->saveDefinition(new EntityId($d["id"]), "GOOD", "Good standing", true, 2);
        $this->commit($service, $student->id, $p, $this->review($service, $student->id, $p, [$row]));
        $service->saveDefinition(new EntityId($d["id"]), "GOOD", "Good standing", false, 3);
        $row["ends_on"] = "2026-08-09";
        $this->commit($service, $student->id, $p, $this->review($service, $student->id, $p, [$row]));
        $row["ends_on"] = "2026-08-10";
        $this->expectException(ValidationException::class);
        $this->review($service, $student->id, $p, [$row]);
    }

    public function testDefinitionSetupHasIndependentAuthorityAndOptimisticConcurrency(): void
    {
        $s = new SisFixture();
        $service = $this->service($s);
        $s->access->staff = false;
        $s->access->grants = [StudentStandingService::CONFIGURE];
        $definition = $service->saveDefinition(null, "GOOD", "Good standing", true, null);
        self::assertCount(1, $service->definitions(setup: true)["items"]);
        try {
            $service->definitions();
            self::fail("Configuration must not grant student-standing management.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $service->saveDefinition(new EntityId($definition["id"]), "GOOD", "Updated standing", true, 1);
        $this->expectException(ConcurrentModificationException::class);
        $service->saveDefinition(new EntityId($definition["id"]), "GOOD", "Lost update", true, 1);
    }

    public function testTamperedProjectionCannotBeReadOrConfirmed(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $d = $service->saveDefinition(null, "GOOD", "Good standing", true, null);
        $row = $this->row($d);
        $this->commit($service, $student->id, $p, $this->review($service, $student->id, $p, [$row]));
        $s->sql->update("sis_student_standing", ["title" => "Unrecorded decision"], ["id" => $row["id"]]);
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $service->read($student->id, $p);
    }

    public function testFinalExpiryAndAuthorityLossRollBackAllCoupledFacts(): void
    {
        foreach (["expiry", "authority", "audit", "definition", "pursuit"] as $failure) {
            $s = new SisFixture();
            $student = $s->student();
            $p = $this->pursuit($s, $student->id);
            $setup = $this->service($s);
            $d = $setup->saveDefinition(null, "GOOD", "Good standing", true, null);
            $review = $this->review($setup, $student->id, $p, [$this->row($d)]);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method("record")->willReturnCallback(static function () use ($s, $failure, $d, $p): void {
                if ($failure === "expiry") {
                    $s->clock->time = $s->clock->time->modify("+20 minutes");
                } elseif ($failure === "authority") {
                    $s->access->staff = false;
                } elseif ($failure === "definition") {
                    $s->sql->update("sis_standing_definitions", ["active" => 0], ["id" => $d["id"]]);
                } elseif ($failure === "pursuit") {
                    $s->sql->update("sis_student_programs", ["status" => "closed"], ["id" => $p->value]);
                } else {
                    throw new \RuntimeException("Fictional audit failure.");
                }
            });
            $events = $s->sql->rows("sis_history_events");
            try {
                $this->commit($this->service($s, $audit), $student->id, $p, $review);
                self::fail("Final failures must roll back.");
            } catch (\RuntimeException | ValidationException $error) {
                self::assertNotSame("Final failures must roll back.", $error->getMessage());
                self::assertSame($events, $s->sql->rows("sis_history_events"));
                self::assertSame([], $s->sql->rows("sis_student_standing"));
            }
        }
    }

    public function testStandingChoicesAreBoundedAndLiteralWildcardsAreNotExpanded(): void
    {
        $s = new SisFixture();
        $service = $this->service($s);
        for ($i = 0; $i < 23; $i++) {
            $service->saveDefinition(null, "S." . $i, "Fictional standing " . $i, true, null);
        }
        $first = $service->definitions();
        self::assertCount(20, $first["items"]);
        self::assertNotNull($first["next_after"]);
        self::assertCount(3, $service->definitions(after: new EntityId($first["next_after"]))["items"]);
        self::assertSame([], $service->definitions("%")["items"]);
    }

    public function testASecondPendingReviewCannotOverwriteTheFirstAcceptedDecision(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $d = $service->saveDefinition(null, "GOOD", "Good standing", true, null);
        $a = $this->row($d);
        $b = array_replace($a, ["starts_on" => "2026-08-02"]);
        $first = $this->review($service, $student->id, $p, [$a]);
        $second = $this->review($service, $student->id, $p, [$b]);
        $this->commit($service, $student->id, $p, $first);
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->commit($service, $student->id, $p, $second);
            self::fail("A stale review cannot replace an accepted determination.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
            self::assertSame("2026-08-01", $service->view($student->id, $p)["rows"][0]["starts_on"]);
        }
    }

    public function testMigrationIsIdempotentPortableAndPreservesRecordedFactsAndGrants(): void
    {
        $s = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (string $name, callable $build) use (&$definitions): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $definitions[] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $connection
            ->method("quoteIdentifier")
            ->willReturnCallback(static fn(string $id): string => '"' . $id . '"');
        $migration = new \CreateSisStudentStanding("standing-schema");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "migration",
                ),
            ]),
        );
        $before = $s->sql->rows("sis_history_events");
        $grants = $s->sql->rows("sis_access_role_permissions");
        $migration->up();
        $migration->up();
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame($grants, $s->sql->rows("sis_access_role_permissions"));
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $dialect) {
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $dialect;
            $sql = json_encode(new $class($connection)->create($definitions[1]), JSON_THROW_ON_ERROR);
            self::assertStringContainsString("ss_pursuit", $sql);
            self::assertStringContainsString("policy_reference", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/AUTO_INCREMENT|sis_history_events|DELETE FROM/i",
                $sql,
            );
        }
    }

    public function testReportStudioUsesRetainedStandingLabelsDatedFiltersAndItsOwnAuthorization(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $definition = $service->saveDefinition(null, "GOOD", "Retained standing label", true, null);
        $row = $this->row($definition);
        $earlier = array_replace($row, [
            "id" => EntityId::generate()->value,
            "starts_on" => "2026-01-01",
            "ends_on" => "2026-07-31",
        ]);
        $this->commit(
            $service,
            $student->id,
            $p,
            $this->review($service, $student->id, $p, [$earlier, $row]),
        );
        $service->saveDefinition(new EntityId($definition["id"]), "GOOD", "New library label", true, 1);
        $reports = new \Domain\Reporting\Service\RelationalReportService(
            new \Infrastructure\Persistence\Repository\SqlReportRepository($s->sql, $s->clock),
            new \Infrastructure\Persistence\Repository\SqlRelationalReportRepository($s->sql),
            new \Infrastructure\Reporting\SqlRelationalReportSource($s->sql),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
            $s->reportLifecycles(),
        );
        $query = [
            "dataset" => "standing",
            "columns" => [
                ["field" => "standing_title", "label" => "Standing", "aggregate" => "none"],
                ["field" => "program_code", "label" => "Program", "aggregate" => "none"],
                ["field" => "effective_from", "label" => "From", "aggregate" => "none"],
            ],
            "filters" => [
                "condition" => "AND",
                "rules" => [
                    ["id" => "effective_from", "operator" => "less_or_equal", "value" => "2026-08-10"],
                    [
                        "condition" => "OR",
                        "rules" => [
                            ["id" => "effective_through", "operator" => "is_null", "value" => null],
                            [
                                "id" => "effective_through",
                                "operator" => "greater_or_equal",
                                "value" => "2026-08-10",
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $before = $s->sql->rows("sis_history_events");
        $rows = $reports->preview($query, 0)["items"];
        self::assertCount(1, $rows);
        self::assertSame("Retained standing label", $rows[0]["c0"]);
        self::assertSame("2026-08-01", $rows[0]["c2"]);
        $query["columns"] = [
            ["field" => "student_id", "label" => "Students", "aggregate" => "count_distinct"],
        ];
        unset($query["filters"]);
        self::assertSame(1, $reports->preview($query, 0)["items"][0]["c0"]);
        $fields = \Infrastructure\Reporting\AcademicReportSchema::datasets()["standing"]["fields"];
        self::assertArrayNotHasKey("policy_reference", $fields);
        self::assertArrayNotHasKey("reason", $fields);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $s->access->staff = false;
        $s->access->grants = ["sis:student:read", "sis:reporting:run"];
        $this->expectException(AccessDeniedException::class);
        $reports->preview($query, 0);
    }

    public function testProgramChangeDoesNotCopyStandingIntoTheNewPursuit(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $p = $this->pursuit($s, $student->id);
        $service = $this->service($s);
        $definition = $service->saveDefinition(null, "GOOD", "Good standing", true, null);
        $accepted = $this->review($service, $student->id, $p, [$this->row($definition)]);
        $this->commit($service, $student->id, $p, $accepted);
        $program = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $program->value,
            "code" => "FICTIONAL.NEXT",
            "title" => "Fictional next program",
        ]);
        $revision = hash(
            "sha256",
            json_encode(
                [
                    $student->id->value,
                    $student->personId->value,
                    $student->programId->value,
                    $student->entryTermId->value,
                    $student->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $review = $s
            ->programAssignmentService()
            ->reviewChange(
                $student->id,
                $program,
                $s->term,
                $revision,
                "Fictional registrar program change.",
            );
        $new = $s->programAssignmentService()->changeProgram($student->id, $review["review_token"]);
        self::assertFalse($service->view($student->id, $p)["editable"]);
        self::assertSame([], $service->view($student->id, new EntityId($new["assignment_id"]))["rows"]);
        self::assertSame(1, $this->commit($service, $student->id, $p, $accepted)["version"]);
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $s->access->staff = false;
        self::assertSame("closed", $service->read($student->id, $p)["pursuit_status"]);
        self::assertSame("Good standing", $service->read($student->id, $p)["items"][0]["title"]);
    }
}
