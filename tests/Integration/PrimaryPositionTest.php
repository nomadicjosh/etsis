<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\ValueObject\PrimaryPositionId;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlPrimaryPositionRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SisFixture;

final class PrimaryPositionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    /** @return array{SisFixture,EntityId,EntityId,EntityId,EntityId,JobDefinition} */
    private function setupEmployee(): array
    {
        $s = new SisFixture();
        $owner = $s->user();
        $student = $s->student($owner);
        $employee = $s
            ->timesheetService()
            ->addEmployee($student->personId, "General employment title");
        $job = new JobDefinition(
            EntityId::generate(),
            "LIB.ASST",
            "Fictional library assistant",
            "USD",
            "hourly",
            true,
        );
        $s->hrLibrary()->save($job, null);
        $one = EntityId::generate();
        $two = EntityId::generate();
        foreach ([$one, $two] as $id) {
            $s->positions()->assign(
                $employee,
                $id,
                $job->id,
                0,
                null,
                "2026-08-01",
                null,
                2500,
                1200,
                "Fictional original position",
            );
        }
        return [$s, $employee, $one, $two, $owner, $job];
    }
    /** @return array<string,mixed> */
    private function reviewed(
        SisFixture $s,
        EntityId $employee,
        ?EntityId $position,
        int $version = 0,
        ?int $positionVersion = 1,
    ): array {
        $args = [
            $employee,
            EntityId::generate(),
            $version,
            $position,
            $position === null ? null : $positionVersion,
            "Private fictional HR primary reason",
        ];
        $review = $s->primaryPositions()->review(...$args);
        return ["args" => $args, "token" => $review["review_token"], "review" => $review];
    }
    private function confirm(SisFixture $s, array $review): void
    {
        $s->primaryPositions()->confirm(...[...$review["args"], $review["token"]]);
    }

    public function testSelectChangeClearRetainsProtectedOriginalsAndCanonicalIdentity(): void
    {
        [$s, $employee, $one, $two, , $job] = $this->setupEmployee();
        $people = $s->sql->rows("sis_persons");
        $employees = $s->sql->rows("sis_employees");
        $grants = $s->sql->rows("sis_access_role_permissions");
        self::assertSame("unrecorded", $s->primaryPositions()->get($employee)["status"]);
        $first = $this->reviewed($s, $employee, $one);
        $this->confirm($s, $first);
        self::assertSame("current", $s->primaryPositions()->get($employee)["status"]);
        $this->confirm($s, $this->reviewed($s, $employee, $two, 1));
        $this->confirm($s, $this->reviewed($s, $employee, null, 2));
        $history = $s->primaryPositions()->history($employee);
        self::assertCount(3, $history["items"]);
        $stream = new StreamReference(new PrimaryPositionId($employee->value), 'employment_primary_position');
        $domain = \EventStoreFixture::history($s->connection->queryBuilder())->page($stream);
        $framework = iterator_to_array(\EventStoreFixture::framework(
            $s->connection->queryBuilder()
        )->getAggregateHistoryFor($stream->aggregateId));
        self::assertCount(3, $framework);
        self::assertSame($domain->events[0]->event->payload(), $framework[0]->payload());
        self::assertSame($s->clock->now()->format(DATE_ATOM), $history['items'][0]['effective_at']);
        self::assertSame(
            [$one->value, $two->value, null],
            array_map(static fn(array $i) => $i["selection"]["id"] ?? null, $history["items"]),
        );
        self::assertSame($job->title, $history["items"][0]["selection"]["title"]);
        self::assertSame("cleared", $s->primaryPositions()->get($employee)["status"]);
        $events = $s->sql->rows("sis_history_events");
        self::assertStringNotContainsString("Private fictional", json_encode($events));
        self::assertStringNotContainsString(
            "LIB.ASST",
            json_encode($s->sql->rows("sis_primary_positions")),
        );
        $s->clock->time = $s->clock->time->modify("+1 day");
        $this->confirm($s, $first);
        self::assertSame($events, $s->sql->rows("sis_history_events"));
        self::assertSame("cleared", $s->primaryPositions()->get($employee)["status"]);
        self::assertSame($people, $s->sql->rows("sis_persons"));
        self::assertSame($employees, $s->sql->rows("sis_employees"));
        self::assertSame($grants, $s->sql->rows("sis_access_role_permissions"));
    }
    public function testOwnerReadsMinimizedContextAndStillChoosesBetweenConcurrentWorkedPositions(): void
    {
        [$s, $employee, $one, $two, $owner] = $this->setupEmployee();
        $this->confirm($s, $this->reviewed($s, $employee, $one));
        $s->access->actor = $owner;
        $s->access->staff = false;
        $before = $s->sql->rows("sis_history_events");
        $data = $s->timesheetService()->employeeDetails($employee);
        $summary = $s->primaryPositions()->get($employee);
        $work = new TimeSlot(
            new DateTimeImmutable("2026-08-10T11:00:00-07:00"),
            new DateTimeImmutable("2026-08-10T12:00:00-07:00"),
        );
        $choices = $s->timesheetService()->workPositions($employee, $work);
        self::assertCount(2, $choices["items"]);
        self::assertSame($one->value, $choices["primary_position"]["selection"]["id"]);
        self::assertSame($one->value, $data["primary_position"]["selection"]["id"]);
        self::assertDoesNotMatchRegularExpression(
            "/reason|rate_minor|weekly_minutes|grade|actor_id/",
            json_encode($summary),
        );
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        $this->expectException(ValidationException::class);
        $s->timesheetService()->submit($employee, $work);
    }
    public function testLaterAppointmentEditsRequireExplicitReaffirmationAndCannotRelabelRetainedSelection(): void
    {
        [$s, $employee, $one, , , $job] = $this->setupEmployee();
        $this->confirm($s, $this->reviewed($s, $employee, $one));
        $s->positions()->end($employee, $one, 1, "2026-08-31", "Fictional scheduled closure");
        self::assertSame("changed", $s->primaryPositions()->get($employee)["status"]);
        $s->hrLibrary()->save(
            new JobDefinition($job->id, $job->code, "Future job label", "USD", "annual", false),
            0,
        );
        self::assertSame($job->title, $s->primaryPositions()->get($employee)["selection"]["title"]);
        $this->confirm($s, $this->reviewed($s, $employee, $one, 1, 2));
        self::assertSame("current", $s->primaryPositions()->get($employee)["status"]);
        $s->clock->time = new DateTimeImmutable("2026-09-01T10:00:00-07:00");
        self::assertSame("ended", $s->primaryPositions()->get($employee)["status"]);
        self::assertSame($one->value, $s->primaryPositions()->get($employee)["selection"]["id"]);
        $this->confirm($s, $this->reviewed($s, $employee, null, 2));
        self::assertSame("cleared", $s->primaryPositions()->get($employee)["status"]);
    }
    public function testSourceChangeAfterReviewIsRejectedWithoutRecording(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $s->positions()->end($employee, $one, 1, "2026-08-31", "Fictional date change");
        $before = $s->sql->rows("sis_history_events");
        try {
            $this->confirm($s, $review);
            self::fail("Stale source accepted");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }
    public function testCompetingPrimaryReviewCannotOverwriteNewerDesignation(): void
    {
        [$s, $employee, $one, $two] = $this->setupEmployee();
        $first = $this->reviewed($s, $employee, $one);
        $second = $this->reviewed($s, $employee, $two);
        $this->confirm($s, $first);
        $this->expectException(ConcurrentModificationException::class);
        $this->confirm($s, $second);
    }
    public function testExpiredUnacceptedReviewIsRejected(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $s->clock->time = $s->clock->time->modify("+20 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $this->confirm($s, $review);
    }
    public function testReviewedReasonCannotBeChangedDuringConfirmation(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $review["args"][5] = "Different fictional reason";
        $this->expectException(ValidationException::class);
        $this->confirm($s, $review);
    }
    public function testOwnerCannotReadPrivateHistoryOrMakePrimaryDecisions(): void
    {
        [$s, $employee, $one, , $owner] = $this->setupEmployee();
        $s->access->actor = $owner;
        $s->access->staff = false;
        foreach (["history", "review"] as $action) {
            try {
                if ($action === "history") {
                    $s->primaryPositions()->history($employee);
                } else {
                    $this->reviewed($s, $employee, $one);
                }
                self::fail("Owner received HR duty");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testBothIndependentHrDutiesAreRequired(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $s->access->staff = false;
        foreach ([["sis:hr:manage"], ["sis:hr:primary"]] as $grants) {
            $s->access->grants = $grants;
            try {
                $this->reviewed($s, $employee, $one);
                self::fail("Missing duty accepted");
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testUnrelatedOwnerCannotReadAnotherEmployeesPrimarySummary(): void
    {
        [$s, $employee] = $this->setupEmployee();
        $s->access->actor = $s->user();
        $s->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $s->primaryPositions()->get($employee);
    }
    public function testOtherEmployeesPositionCannotBeDesignated(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $other = $s
            ->timesheetService()
            ->addEmployee($s->student($s->user())->personId, "Other employee");
        $this->expectException(AccessDeniedException::class);
        $this->reviewed($s, $other, $one);
    }
    public function testVoidedAndFuturePositionsCannotBeDesignated(): void
    {
        [$s, $employee, $one, $two] = $this->setupEmployee();
        $s->positions()->setVoid($employee, $one, 1, true, "Fictional incorrect agreement");
        $s->clock->time = new DateTimeImmutable("2026-07-01T10:00:00-07:00");
        foreach ([[$one, 2], [$two, 1]] as [$id, $version]) {
            try {
                $this->reviewed($s, $employee, $id, 0, $version);
                self::fail("Invalid appointment accepted");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testAuditFailureRollsBackEventProjectionAndReceipt(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $before = $s->sql->rows("sis_history_events");
        $audit = new class implements AuditTrail {
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit unavailable");
            }
        };
        try {
            $s->primaryPositions($audit)->confirm(...[...$review["args"], $review["token"]]);
            self::fail("Audit failure ignored");
        } catch (RuntimeException $e) {
            self::assertSame("Fictional audit unavailable", $e->getMessage());
        }
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame([], $s->sql->rows("sis_primary_positions"));
        $this->confirm($s, $review);
        self::assertSame(1, $s->primaryPositions()->get($employee)["version"]);
    }
    public function testSourceMutationDuringAuditRollsBackBothChanges(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $before = $s->sql->rows("sis_history_events");
        $audit = new class ($s, $employee, $one) implements AuditTrail {
            public function __construct(
                private SisFixture $s,
                private EntityId $employee,
                private EntityId $position,
            ) {
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->s
                    ->positions()
                    ->end(
                        $this->employee,
                        $this->position,
                        1,
                        "2026-08-31",
                        "Fictional concurrent source change",
                    );
            }
        };
        try {
            $s->primaryPositions($audit)->confirm(...[...$review["args"], $review["token"]]);
            self::fail("Audit source mutation ignored");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
        self::assertSame([], $s->sql->rows("sis_primary_positions"));
        self::assertSame(1, $s->positions()->get($employee, $one)["version"]);
    }
    public function testAuthorityRevocationDuringAuditRollsBackDecision(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $audit = new class ($s) implements AuditTrail {
            public function __construct(private SisFixture $s)
            {
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->s->access->staff = false;
            }
        };
        $before = $s->sql->rows("sis_history_events");
        try {
            $s->primaryPositions($audit)->confirm(...[...$review["args"], $review["token"]]);
            self::fail("Revoked authority accepted");
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows("sis_history_events"));
        }
    }
    public function testProjectionLossFailsClosedAndCanBeRebuiltFromAuthoritativeHistory(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $this->confirm($s, $this->reviewed($s, $employee, $one));
        $root = $s->primaryPositionLedger()->inspect($employee)["root"];
        $s->sql->execute("DELETE FROM sis_primary_positions WHERE employee_id = ?", [
            $employee->value,
        ]);
        try {
            $s->primaryPositions()->get($employee);
            self::fail("Missing projection silently inferred");
        } catch (CorruptHistoryException) {
            self::assertTrue(true);
        }
        new SqlPrimaryPositionRepository($s->sql, $s->dropCipher)->save($root->designation(), 0);
        self::assertSame($one->value, $s->primaryPositions()->get($employee)["selection"]["id"]);
    }
    public function testHistoryPagesFreezeTheirBoundaryAndKeepReasonsOffOwnerReads(): void
    {
        [$s, $employee, $one, $two] = $this->setupEmployee();
        for ($version = 0; $version < 21; $version++) {
            $this->confirm(
                $s,
                $this->reviewed($s, $employee, $version % 2 === 0 ? $one : $two, $version),
            );
        }
        $first = $s->primaryPositions()->history($employee);
        self::assertCount(20, $first["items"]);
        self::assertSame(20, $first["next_after"]);
        $this->confirm($s, $this->reviewed($s, $employee, null, 21));
        $last = $s->primaryPositions()->history($employee, 20, $first["through_version"]);
        self::assertCount(1, $last["items"]);
        self::assertSame(21, $last["items"][0]["version"]);
        self::assertNull($last["next_after"]);
        self::assertArrayNotHasKey("actor_id", $last["items"][0]);
        self::assertSame("Private fictional HR primary reason", $last["items"][0]["reason"]);
    }
    public function testReplayStopsBeforeAnOversizedStreamIsRead(): void
    {
        [$s, $employee] = $this->setupEmployee();
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history
            ->expects(self::once())
            ->method("page")
            ->willReturn(new \Domain\Shared\EventSourcing\EventPage([], null, 2001));
        $this->expectException(\Domain\Shared\EventSourcing\HistoryReadLimitException::class);
        $s->primaryPositionLedger($history)->inspect($employee);
    }
    public function testAnotherSuperPersonsDesignationRequiresSuperAuthority(): void
    {
        [$s, $employee, $one, , $owner] = $this->setupEmployee();
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $owner,
            $roles->roleNamed("super")->id,
            true,
        );
        $this->expectException(AccessDeniedException::class);
        $this->reviewed($s, $employee, $one);
    }
    public function testAcceptedOriginalRetrySurvivesLaterSourceVoidAndReviewExpiry(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $this->confirm($s, $review);
        $s->positions()->setVoid($employee, $one, 1, true, "Fictional later void");
        $s->clock->time = $s->clock->time->modify("+1 day");
        $before = $s->sql->rows("sis_history_events");
        $this->confirm($s, $review);
        self::assertSame($before, $s->sql->rows("sis_history_events"));
        self::assertSame("voided", $s->primaryPositions()->get($employee)["status"]);
    }
    public function testReviewIsBoundToTheActingStaffMember(): void
    {
        [$s, $employee, $one] = $this->setupEmployee();
        $review = $this->reviewed($s, $employee, $one);
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $this->confirm($s, $review);
    }
}
