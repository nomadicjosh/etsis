<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\HumanResources\Repository\EmploymentProjectionVerifier;
use Domain\HumanResources\Service\TimeDecisionLedger;
use Domain\HumanResources\ValueObject\EmploymentProjectionCheck;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlPrimaryPositionRepository;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use Infrastructure\Projection\SqlEmploymentProjectionVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\EmploymentHistoryFixture;

final class EmploymentProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/EmploymentHistoryFixture.php";
    }

    private function verify(
        EmploymentHistoryFixture $f,
        string $kind,
        int $limit = 500,
    ): EmploymentProjectionCheck {
        return $f->sis
            ->employmentVerifier()
            ->{$kind}(
                $f->{$kind === "primaryPosition"
                    ? "employee"
                    : ($kind === "timeEntry"
                        ? "entry"
                        : "position")},
                $limit,
            );
    }

    public function testThreeSelectedChecksCompareProtectedFactsWithoutWritesOrPrivateOutput(): void
    {
        $f = new EmploymentHistoryFixture();
        $before = $f->facts();
        $f->sis->connection->pdo->exec("PRAGMA query_only = ON");
        foreach (
            ["primaryPosition" => 2, "position" => 1, "timeEntry" => 1] as $kind => $events
        ) {
            $result = $this->verify($f, $kind);
            self::assertSame("verified", $result->status);
            self::assertSame(1, $result->records);
            self::assertSame($events, $result->events);
            $json = json_encode($result->describe(), JSON_THROW_ON_ERROR);
            foreach (
                [
                    "Private",
                    "Fictional",
                    "4321",
                    "1234",
                    "rules",
                    "starts_at",
                    "actor_id",
                    "reason",
                ] as $private
            ) {
                self::assertStringNotContainsString($private, $json);
            }
            self::assertFalse($result->describe()["records_changed"]);
        }
        self::assertSame($before, $f->facts());
    }

    public function testLaterAppointmentAndPolicyChangesDoNotReinterpretARetainedDecision(): void
    {
        $f = new EmploymentHistoryFixture();
        $f->sis
            ->positions()
            ->end(
                $f->employee,
                $f->position,
                1,
                "2026-08-31",
                "Fictional later closure.",
            );
        $f->sis->clock->time = $f->sis->clock->time->modify("+1 month");
        $f->designate(null);
        $before = $f->facts();
        foreach (["primaryPosition", "position", "timeEntry"] as $kind) {
            self::assertSame("verified", $this->verify($f, $kind)->status);
        }
        self::assertSame($before, $f->facts());
    }

    public function testMissingProjectionDamagedEventAndChangedTimeFactsFailWithoutRepair(): void
    {
        foreach (["primaryPosition", "position", "timeEntry"] as $kind) {
            foreach (["projection", "event"] as $damage) {
                $f = new EmploymentHistoryFixture();
                $s = $f->sis;
                $target = match ($kind) {
                    'primaryPosition' => 'employee', 'position' => 'position', 'timeEntry' => 'entry',
                };
                $id = $f->{$target};
                $scope = match ($kind) {
                    "primaryPosition" => "employment_primary_position",
                    "position" => "employment_position",
                    "timeEntry" => "timesheet_decision",
                };
                if ($damage === "event") {
                    $head = $s->sql->rows("sis_history_streams", [
                        "aggregate_id" => $id->value,
                        "stream_type" => $scope,
                    ])[0];
                    $s->sql->update(
                        "sis_history_events",
                        ["sealed_event" => "Private damaged ciphertext"],
                        ["stream_id" => $head["id"]],
                    );
                } elseif ($kind === "timeEntry") {
                    $s->sql->update(
                        "sis_timesheets",
                        ["status" => "rejected"],
                        ["id" => $id->value],
                    );
                } elseif ($kind === "position") {
                    $s->sql->update(
                        "sis_employee_positions",
                        ["encrypted_position" => "Private damaged projection"],
                        ["id" => $id->value],
                    );
                } else {
                    $table = 'sis_primary_positions';
                    $key = 'employee_id';
                    $s->sql->execute(
                        "DELETE FROM " .
                            $s->sql->quote($table) .
                            " WHERE " .
                            $s->sql->quote($key) .
                            " = ?",
                        [$id->value],
                    );
                }
                $before = $f->facts();
                $result = $this->verify($f, $kind);
                self::assertSame("unverified", $result->status);
                self::assertNull($result->records);
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testUnrecordedDesignationPendingTimeAndEarlierUnknownDecisionsStayDistinct(): void
    {
        $f = new EmploymentHistoryFixture();
        $s = $f->sis;
        $other = $s
            ->timesheetService()
            ->addEmployee(
                $s->student($s->user())->personId,
                "Fictional older employee",
            );
        self::assertSame(
            "observed_empty",
            $s->employmentVerifier()->primaryPosition($other)->status,
        );
        $pending = $s
            ->timesheetService()
            ->submit(
                $other,
                new \Domain\Scheduling\TimeSlot(
                    new \DateTimeImmutable("2026-08-11T09:00:00Z"),
                    new \DateTimeImmutable("2026-08-11T10:00:00Z"),
                ),
            );
        self::assertSame(
            "observed_empty",
            $s->employmentVerifier()->timeEntry($pending->id)->status,
        );
        $s->sql->update(
            "sis_timesheets",
            ["status" => "approved", "approved_by" => $s->access->actor->value],
            ["id" => $pending->id->value],
        );
        $before = $f->facts();
        $result = $s->employmentVerifier()->timeEntry($pending->id);
        self::assertSame("history_gaps", $result->status);
        self::assertSame(["earlier_decision"], $result->issues);
        self::assertNull($result->records);
        self::assertSame($before, $f->facts());
    }

    public function testSharedLimitsAndEnclosingSnapshotsDoNotYieldPartialSuccess(): void
    {
        $f = new EmploymentHistoryFixture();
        $limited = $this->verify($f, "primaryPosition", 1);
        self::assertSame("limited", $limited->status);
        self::assertNull($limited->records);
        self::assertSame(1, $limited->events);
        self::assertSame(
            "unverified",
            $f->sis->transactions->run(fn() => $this->verify($f, "position"))
                ->status,
        );
        foreach (["primaryPosition", "position", "timeEntry"] as $kind) {
            self::assertSame(
                "unverified",
                $f->sis->employmentVerifier()->{$kind}(EntityId::generate())
                    ->status,
            );
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->verify($f, "position", 2001);
    }

    public function testConcurrentSelectedAndReferencedAgreementChangesAreReportedAsChanged(): void
    {
        foreach (["designation", "agreement"] as $change) {
            $f = new EmploymentHistoryFixture();
            $s = $f->sis;
            $store = EventStoreFixture::history($s->connection->queryBuilder());
            $history = $this->createStub(HistoricalEventStore::class);
            $called = false;
            $history
                ->method("page")
                ->willReturnCallback(function (
                    $stream,
                    $cursor,
                    $limit,
                ) use (
                    $f,
                    $store,
                    &$called,
                    $change,
                ) {
                    $page = $store->page($stream, $cursor, $limit);
                    if (!$called) {
                        $called = true;
                        if ($change === "designation") {
                            $f->designate(null);
                        } else {
                            $f->sis
                                ->positions()
                                ->end(
                                    $f->employee,
                                    $f->position,
                                    1,
                                    "2026-08-31",
                                    "Fictional concurrent closure.",
                                );
                        }
                    }
                    return $page;
                });
            $verifier = new SqlEmploymentProjectionVerifier(
                $s->sql,
                new SqlPrimaryPositionRepository($s->sql, $s->dropCipher),
                new SqlTimeRepository($s->sql, $s->dropCipher),
                $s->primaryPositionLedger($history),
                $s->positionLedger(history: $history),
                new TimeDecisionLedger($history, $s->clock),
            );
            $result = $verifier->primaryPosition($f->employee);
            self::assertSame("changed", $result->status);
            self::assertNull($result->records);
        }
    }
}
