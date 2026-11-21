<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Advising\AdvisingCheckIn;
use Domain\Advising\Service\AdvisingCheckInService;
use Domain\Advising\Service\AdvisorProgressAccess;
use Domain\Advising\Service\AdvisorService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdvisingCheckInRepository;
use Infrastructure\Persistence\Repository\SqlAdvisorRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AdvisingCheckInTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function advisorService(SisFixture $s): AdvisorService
    {
        return new AdvisorService(
            new SqlAdvisorRepository($s->sql),
            $s->admissions,
            new SqlStudentRecordRepository($s->sql),
            new SqlPersonAccountRepository(
                $s->sql,
                $s->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            ),
            $s->programStatusLedger(),
            $s->primaryProgramLedger(),
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
            new \Domain\Shared\Service\ReviewProof($s->dropCipher, $s->clock),
        );
    }

    private function service(
        SisFixture $s,
        ?AuditTrail $audit = null,
        ?AdvisorProgressAccess $advisors = null,
    ): AdvisingCheckInService {
        return new AdvisingCheckInService(
            new SqlAdvisingCheckInRepository($s->sql, $s->dropCipher),
            $s->admissions,
            new SqlStudentRecordRepository($s->sql),
            new SqlPersonProfileRepository($s->sql),
            $s->programStatusLedger(),
            $s->primaryProgramLedger(),
            $advisors ?? $this->advisorService($s),
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
        );
    }

    private function setupCase(): array
    {
        $s = new SisFixture();
        $owner = $s->user();
        $student = $s->student($owner);
        $pursuit = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $student->id->value], 1)[0][
                "id"
            ],
        );
        return [$s, $student->id, $pursuit, $owner];
    }

    private function record(
        AdvisingCheckInService $service,
        EntityId $student,
        EntityId $pursuit,
        ?EntityId $id = null,
    ): array {
        return $service->record(
            $student,
            $pursuit,
            "staff",
            $id ?? EntityId::generate(),
            "2026-08-10",
            "2026-08-20",
            "Review your course plan.",
            "Fictional private note.",
        );
    }

    private function rejects(callable $run, string $type): void
    {
        try {
            $run();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($type, $exception);
            return;
        }
        self::fail("The check-in action should have been rejected.");
    }

    public function testStudentResponseClosureAndOriginalRetriesPreserveWorkingAndAcademicFacts(): void
    {
        [$s, $student, $pursuit, $owner] = $this->setupCase();
        $service = $this->service($s);
        $staff = $s->access->actor;
        $before = array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_students",
            "sis_student_programs",
        ]);
        $id = EntityId::generate();
        $created = $this->record($service, $student, $pursuit, $id);
        $row = $s->sql->rows("sis_advising_checkins")[0];
        self::assertStringNotContainsString("private note", json_encode($row));
        self::assertSame(
            "2026-08-10T17:00:00Z",
            $service->listing($student, $pursuit, "staff")["items"][0]["created_at"],
        );
        $s->access->actor = $owner;
        $s->access->staff = false;
        $item = $service->listing($student, $pursuit, "owner")["items"][0];
        foreach (
            [
                "private_note",
                "owner_user_id",
                "receipts",
                "owner_changed",
                "student_id",
                "pursuit_id",
            ] as $key
        ) {
            self::assertArrayNotHasKey($key, $item);
        }
        self::assertFalse($service->listing($student, $pursuit, "owner")["can_record"]);
        $responded = $service->act(
            $student,
            $pursuit,
            "owner",
            $id,
            "respond",
            0,
            "I reviewed my plan.",
        );
        $s->access->actor = $staff;
        $s->access->staff = true;
        $closed = $service->act(
            $student,
            $pursuit,
            "staff",
            $id,
            "close",
            1,
            "Plan discussed; follow-up complete.",
        );
        $retained = $s->sql->rows("sis_advising_checkins");
        $audits = $s->sql->rows("sis_audit");
        self::assertSame($created, $this->record($service, $student, $pursuit, $id));
        self::assertSame(
            $closed,
            $service->act(
                $student,
                $pursuit,
                "staff",
                $id,
                "close",
                1,
                "Plan discussed; follow-up complete.",
            ),
        );
        $s->access->actor = $owner;
        $s->access->staff = false;
        self::assertSame(
            $responded,
            $service->act($student, $pursuit, "owner", $id, "respond", 0, "I reviewed my plan."),
        );
        self::assertSame($retained, $s->sql->rows("sis_advising_checkins"));
        self::assertSame($audits, $s->sql->rows("sis_audit"));
        $finished = $service->listing($student, $pursuit, "owner", true)["items"][0];
        self::assertSame("Plan discussed; follow-up complete.", $finished["resolution"]);
        self::assertSame("Review your course plan.", $finished["next_step"]);
        self::assertSame(
            $before,
            array_map($s->sql->rows(...), [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_students",
                "sis_student_programs",
            ]),
        );
    }

    public function testDifferentIntentsStaleVersionsAndCrossStudentRoutingCannotOverwriteAcceptedFacts(): void
    {
        [$s, $student, $pursuit, $owner] = $this->setupCase();
        $service = $this->service($s);
        $id = new EntityId($this->record($service, $student, $pursuit)["id"]);
        $this->rejects(
            fn() => $service->record(
                $student,
                $pursuit,
                "staff",
                $id,
                "2026-08-09",
                "2026-08-20",
                "Review your course plan.",
                "Fictional private note.",
            ),
            ConcurrentModificationException::class,
        );
        $s->access->actor = $owner;
        $s->access->staff = false;
        $service->act($student, $pursuit, "owner", $id, "respond", 0, "First response.");
        $this->rejects(
            fn() => $service->act(
                $student,
                $pursuit,
                "owner",
                $id,
                "respond",
                0,
                "Changed response.",
            ),
            ConcurrentModificationException::class,
        );
        $this->rejects(
            fn() => $service->act(
                $student,
                $pursuit,
                "owner",
                $id,
                "close",
                1,
                "No student closure.",
            ),
            AccessDeniedException::class,
        );
        $s->access->staff = true;
        $this->rejects(
            fn() => $service->act($student, $pursuit, "staff", $id, "close", 0, "Stale closure."),
            ConcurrentModificationException::class,
        );
        $other = $s->student($s->user());
        $otherPursuit = new EntityId(
            $s->sql->rows("sis_student_programs", ["student_id" => $other->id->value], 1)[0]["id"],
        );
        $this->rejects(
            fn() => $service->listing($student, $otherPursuit, "staff"),
            RecordNotFoundException::class,
        );
        $this->rejects(
            fn() => $service->act(
                $other->id,
                $otherPursuit,
                "staff",
                $id,
                "close",
                1,
                "Wrong scope.",
            ),
            RecordNotFoundException::class,
        );
    }

    public function testIndependentPermissionAndStrictOwnerBoundaryEvenForPrivilegedAccounts(): void
    {
        [$s, $student, $pursuit] = $this->setupCase();
        $service = $this->service($s);
        $this->record($service, $student, $pursuit);
        $this->rejects(
            fn() => $service->listing($student, $pursuit, "owner"),
            AccessDeniedException::class,
        );
        $s->access->staff = false;
        foreach (
            [[], ["sis:student:read"], ["sis:advising:manage"], ["sis:advising:checkin"]] as $grants
        ) {
            $s->access->grants = $grants;
            $this->rejects(
                fn() => $service->listing($student, $pursuit, "staff"),
                AccessDeniedException::class,
            );
        }
        $s->access->grants = ["sis:student:read", "sis:advising:checkin"];
        self::assertCount(1, $service->listing($student, $pursuit, "staff")["items"]);
        $this->rejects(
            fn() => $service->listing($student, $pursuit, "unknown"),
            AccessDeniedException::class,
        );
        $permission = $s->sql->rows(
            "sis_access_permissions",
            ["name" => "sis:advising:checkin"],
            1,
        )[0]["id"];
        self::assertSame(
            [],
            $s->sql->rows("sis_access_role_permissions", ["permission_id" => $permission]),
        );
        self::assertSame([], $s->sql->rows("sis_access_user_permissions"));
    }

    public function testNewOwnerNeverReceivesEarlierConversationAndOnlyCancellationRemainsAvailable(): void
    {
        [$s, $student, $pursuit, $owner] = $this->setupCase();
        $service = $this->service($s);
        $staff = $s->access->actor;
        $id = new EntityId($this->record($service, $student, $pursuit)["id"]);
        $person = $s->admissions->student($student)->personId;
        $newOwner = $s->user();
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $newOwner->value],
            ["id" => $person->value],
        );
        $s->access->actor = $owner;
        $this->rejects(
            fn() => $service->listing($student, $pursuit, "owner"),
            AccessDeniedException::class,
        );
        $s->access->actor = $newOwner;
        self::assertSame([], $service->listing($student, $pursuit, "owner")["items"]);
        $this->rejects(
            fn() => $service->act($student, $pursuit, "owner", $id, "respond", 0, "Wrong account."),
            AccessDeniedException::class,
        );
        $s->access->actor = $staff;
        self::assertTrue(
            $service->listing($student, $pursuit, "staff")["items"][0]["owner_changed"],
        );
        $this->rejects(
            fn() => $service->act($student, $pursuit, "staff", $id, "close", 0, "Cannot transfer."),
            AccessDeniedException::class,
        );
        self::assertSame(
            "cancelled",
            $service->act($student, $pursuit, "staff", $id, "cancel", 0, "Account link changed.")[
                "status"
            ],
        );
        $this->record($service, $student, $pursuit);
        $s->access->actor = $newOwner;
        self::assertCount(1, $service->listing($student, $pursuit, "owner")["items"]);
        self::assertSame([], $service->listing($student, $pursuit, "owner", true)["items"]);
    }

    public function testAdvisorNeedsLiveAssignmentAndSeparateDutyAndLosesAccessWhenEmploymentEnds(): void
    {
        [$s, $student, $pursuit] = $this->setupCase();
        $person = $s->legacyPerson($s->user(), "Fictional", "Advisor");
        $employee = EntityId::generate();
        $s->sql->insert("sis_employees", [
            "id" => $employee->value,
            "person_id" => $person->id->value,
            "job_title" => "Fictional advisor",
            "active" => 1,
        ]);
        $advisors = $this->advisorService($s);
        $review = $advisors->review(
            $student,
            $pursuit,
            [
                [
                    "id" => EntityId::generate()->value,
                    "advisor_person_id" => $person->id->value,
                    "role" => "primary",
                    "starts_on" => "2026-01-01",
                    "ends_on" => null,
                ],
            ],
            $advisors->view($student, $pursuit)["revision"],
            "Fictional assignment.",
        );
        $advisors->commit(
            $student,
            $pursuit,
            new EntityId($review["operation_id"]),
            $review["rows"],
            $review["revision"],
            $review["reason"],
            $review["review_token"],
        );
        $service = $this->service($s, advisors: $advisors);
        $s->access->actor = $person->ownerUserId;
        $s->access->staff = false;
        $this->rejects(
            fn() => $service->listing($student, $pursuit, "advisor"),
            AccessDeniedException::class,
        );
        $s->access->grants = ["sis:advising:checkin"];
        self::assertTrue($service->listing($student, $pursuit, "advisor")["can_record"]);
        $service->record(
            $student,
            $pursuit,
            "advisor",
            EntityId::generate(),
            "2026-08-10",
            null,
            "Discuss next term.",
            "Advisor-only detail.",
        );
        $s->sql->update("sis_employees", ["active" => 0], ["id" => $employee->value]);
        $this->rejects(
            fn() => $service->listing($student, $pursuit, "advisor"),
            AccessDeniedException::class,
        );
        $s->access->actor = $s->user();
        $this->rejects(
            fn() => $service->listing($student, $pursuit, "advisor"),
            AccessDeniedException::class,
        );
    }

    public function testPagingAndActiveCapStayBoundedAndCompletionReleasesCapacity(): void
    {
        [$s, $student, $pursuit] = $this->setupCase();
        $service = $this->service($s);
        for ($i = 0; $i < 20; $i++) {
            $this->record($service, $student, $pursuit);
        }
        $first = $service->listing($student, $pursuit, "staff");
        self::assertCount(10, $first["items"]);
        $second = $service->listing(
            $student,
            $pursuit,
            "staff",
            after: new EntityId($first["next_after"]),
        );
        self::assertCount(10, $second["items"]);
        self::assertNull($second["next_after"]);
        self::assertSame(
            [],
            array_intersect(
                array_column($first["items"], "id"),
                array_column($second["items"], "id"),
            ),
        );
        $this->rejects(
            fn() => $this->record($service, $student, $pursuit),
            ConcurrentModificationException::class,
        );
        $service->act(
            $student,
            $pursuit,
            "staff",
            new EntityId($first["items"][0]["id"]),
            "cancel",
            0,
            "Duplicate follow-up.",
        );
        $this->record($service, $student, $pursuit);
        self::assertCount(21, $s->sql->rows("sis_advising_checkins"));
    }

    public function testInvalidDatesAndMessagesCannotCreateRecords(): void
    {
        [$s, $student, $pursuit] = $this->setupCase();
        $service = $this->service($s);
        foreach (
            [
                ["2026-02-30", null, "Valid", ""],
                ["2026-08-11", null, "Valid", ""],
                ["2026-08-10", "2026-08-09", "Valid", ""],
                ["2026-08-10", null, "", ""],
                ["2026-08-10", null, str_repeat("x", 1001), ""],
                ["2026-08-10", null, "invalid\0", ""],
                ["2026-08-10", null, "Valid", "\xff"],
            ] as [$held, $due, $step, $note]
        ) {
            $this->rejects(
                fn() => $service->record(
                    $student,
                    $pursuit,
                    "staff",
                    EntityId::generate(),
                    $held,
                    $due,
                    $step,
                    $note,
                ),
                ValidationException::class,
            );
        }
        self::assertSame([], $s->sql->rows("sis_advising_checkins"));
    }

    public function testUnicodeCiphertextRestorationAndPrivateSerializationAreBounded(): void
    {
        [$s, $student, $pursuit, $owner] = $this->setupCase();
        $service = $this->service($s);
        $id = EntityId::generate();
        $message = str_repeat("😀", 1000);
        $service->record($student, $pursuit, "staff", $id, "2026-08-10", null, $message, $message);
        $s->access->actor = $owner;
        $service->act($student, $pursuit, "owner", $id, "respond", 0, $message);
        $service->act($student, $pursuit, "staff", $id, "close", 1, $message);
        $repository = new SqlAdvisingCheckInRepository($s->sql, $s->dropCipher);
        $item = $repository->find($student, $pursuit, $id);
        self::assertSame($message, $item->view(true)["resolution"]);
        self::assertLessThan(
            65536,
            strlen($s->sql->rows("sis_advising_checkins")[0]["sealed_data"]),
        );
        self::assertStringNotContainsString("😀", json_encode($item, JSON_UNESCAPED_UNICODE));
        $data = $item->facts();
        $data["receipts"]["record"]["result"]["version"] = 3;
        $this->rejects(fn() => AdvisingCheckIn::restore($data), CorruptHistoryException::class);
        $s->sql->update("sis_advising_checkins", ["version" => 1], ["id" => $id->value]);
        $this->rejects(
            fn() => $repository->find($student, $pursuit, $id),
            CorruptHistoryException::class,
        );
        $s->sql->update(
            "sis_advising_checkins",
            ["sealed_data" => "damaged"],
            ["id" => $id->value],
        );
        $this->rejects(
            fn() => $repository->find($student, $pursuit, $id),
            CorruptHistoryException::class,
        );
    }

    public function testInactiveStudentKeepsHistoricalFollowUpButCannotReceiveAnotherCheckIn(): void
    {
        [$s, $student, $pursuit, $owner] = $this->setupCase();
        $service = $this->service($s);
        $id = new EntityId($this->record($service, $student, $pursuit)["id"]);
        $s->sql->update("sis_students", ["active" => 0], ["id" => $student->value]);
        self::assertFalse($service->listing($student, $pursuit, "staff")["can_record"]);
        $this->rejects(
            fn() => $this->record($service, $student, $pursuit),
            ValidationException::class,
        );
        $s->access->actor = $owner;
        $service->act(
            $student,
            $pursuit,
            "owner",
            $id,
            "respond",
            0,
            "Earlier follow-up remains relevant.",
        );
        $service->act(
            $student,
            $pursuit,
            "staff",
            $id,
            "close",
            1,
            "Historical follow-up completed.",
        );
        self::assertCount(1, $service->listing($student, $pursuit, "owner", true)["items"]);
    }

    public function testProgramChangeLeavesEarlierConversationUnderItsClosedPursuit(): void
    {
        [$s, $student, $pursuit, $owner] = $this->setupCase();
        $service = $this->service($s);
        $id = EntityId::generate();
        $receipt = $this->record($service, $student, $pursuit, $id);
        $nextProgram = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $nextProgram->value,
            "code" => "NEXT",
            "title" => "Fictional next program",
        ]);
        $r = $s->admissions->student($student);
        $revision = hash(
            "sha256",
            json_encode(
                [
                    $r->id->value,
                    $r->personId->value,
                    $r->programId->value,
                    $r->entryTermId->value,
                    $r->active,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $review = $s
            ->programAssignmentService()
            ->reviewChange(
                $student,
                $nextProgram,
                $s->term,
                $revision,
                "Fictional reviewed program change.",
            );
        $changed = $s->programAssignmentService()->changeProgram($student, $review["review_token"]);
        $next = new EntityId($changed["assignment_id"]);
        $events = $s->sql->rows("sis_history_events");
        self::assertFalse($service->listing($student, $pursuit, "staff")["can_record"]);
        self::assertTrue($service->listing($student, $next, "staff")["can_record"]);
        self::assertSame([], $service->listing($student, $next, "staff")["items"]);
        self::assertSame($receipt, $this->record($service, $student, $pursuit, $id));
        $this->rejects(
            fn() => $this->record($service, $student, $pursuit),
            ValidationException::class,
        );
        $staff = $s->access->actor;
        $s->access->actor = $owner;
        $s->access->staff = false;
        $service->act(
            $student,
            $pursuit,
            "owner",
            $id,
            "respond",
            0,
            "Follow-up from my earlier program.",
        );
        $s->access->actor = $staff;
        $s->access->staff = true;
        $service->act($student, $pursuit, "staff", $id, "close", 1, "Earlier follow-up completed.");
        $s->access->actor = $owner;
        $s->access->staff = false;
        self::assertSame(
            "Earlier follow-up completed.",
            $service->listing($student, $pursuit, "owner", true)["items"][0]["resolution"],
        );
        self::assertSame($events, $s->sql->rows("sis_history_events"));
    }

    public function testPortableMigrationOnlyAddsWorkingStorageAndProtectedUngrantedAuthority(): void
    {
        [$s] = $this->setupCase();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method("hasTable")->willReturn(false);
        $schema
            ->method("create")
            ->willReturnCallback(static function (
                string $name,
                callable $build,
            ) use (&$definitions): void {
                $table = new \Qubus\Expressive\Schema\CreateTable($name);
                $build($table);
                $definitions[$name] = $table;
            });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method("getSchema")->willReturn($schema);
        $connection->method("queryBuilder")->willReturn($s->connection->queryBuilder());
        $migration = new \CreateSisAdvisingCheckIns("checkin-compiler");
        $migration->setObjectMap(
            new \ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $connection,
                    "fixture",
                ),
            ]),
        );
        $before = array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_audit",
            "sis_access_role_permissions",
            "sis_access_user_permissions",
        ]);
        $migration->up();
        $migration->up();
        self::assertSame(["sis_advising_checkins"], array_keys($definitions));
        foreach (["MySQL", "PostgreSQL", "SQLite", "SQLServer", "Oracle"] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect
                ->method("quoteIdentifier")
                ->willReturnCallback(static fn(string $name): string => '"' . $name . '"');
            $class = "Qubus\\Expressive\\Schema\\Compiler\\" . $driver;
            $sql = json_encode(
                new $class($dialect)->create($definitions["sis_advising_checkins"]),
                JSON_THROW_ON_ERROR,
            );
            self::assertStringContainsString("sac_pursuit_page", $sql);
            self::assertStringContainsString("sac_student_fk", $sql);
            self::assertStringContainsString("sealed_data", $sql);
            self::assertDoesNotMatchRegularExpression(
                "/DROP |DELETE FROM|UPDATE |INSERT INTO|sis_history_events/i",
                $sql,
            );
        }
        self::assertSame(
            $before,
            array_map($s->sql->rows(...), [
                "sis_history_events",
                "sis_history_streams",
                "sis_history_operations",
                "sis_audit",
                "sis_access_role_permissions",
                "sis_access_user_permissions",
            ]),
        );
        $this->rejects($migration->down(...), \LogicException::class);
    }

    public function testAuditFailureAndLatePermissionRevocationRollBackWritesAndPrivateReads(): void
    {
        [$s, $student, $pursuit] = $this->setupCase();
        $audit = new class implements AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new \RuntimeException("Fictional unavailable audit.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $this->rejects(
            fn() => $this->record($this->service($s, $audit), $student, $pursuit),
            \RuntimeException::class,
        );
        self::assertSame([], $s->sql->rows("sis_advising_checkins"));
        $audit = new class ($s->access) implements AuditTrail {
            public function __construct(private \FixtureAccess $access)
            {
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->access->staff = false;
                $this->access->grants = [];
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $this->rejects(
            fn() => $this->record($this->service($s, $audit), $student, $pursuit),
            AccessDeniedException::class,
        );
        self::assertSame([], $s->sql->rows("sis_advising_checkins"));
        $s->access->staff = true;
        $this->record($this->service($s), $student, $pursuit);
        $this->rejects(
            fn() => $this->service($s, $audit)->listing($student, $pursuit, "staff"),
            AccessDeniedException::class,
        );
    }
}
