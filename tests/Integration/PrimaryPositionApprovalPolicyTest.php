<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\Service\TimeReviewService;
use Domain\HumanResources\ValueObject\TimeApprovalRules;
use Domain\HumanResources\ValueObject\TimeReviewEvidence;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlTimeRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PrimaryPositionApprovalPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(): array
    {
        $s = new SisFixture();
        $owner = $s->user();
        $student = $s->student($owner);
        $employee = $s
            ->timesheetService()
            ->addEmployee($student->personId, "Fictional assistant");
        $job = new JobDefinition(
            EntityId::generate(),
            "LIB",
            "Fictional library",
            "USD",
            "hourly",
            true,
        );
        $s->hrLibrary()->save($job, null);
        $positions = [EntityId::generate(), EntityId::generate()];
        foreach ($positions as $id) {
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
                "Fictional appointment agreement.",
            );
        }
        $entry = $s
            ->timesheetService()
            ->submit(
                $employee,
                new TimeSlot(
                    new DateTimeImmutable("2026-08-10T09:00:00Z"),
                    new DateTimeImmutable("2026-08-10T10:00:00Z"),
                ),
                $positions[1],
                1,
            );
        $s->access->actor = $s->user();
        return [$s, $employee, $positions[0], $positions[1], $entry, $owner];
    }

    private function publish(
        SisFixture $s,
        bool $required,
        array $changes = [],
    ): void {
        $policy = $s->timeApprovalPolicies();
        $current = $policy->view();
        $review = $policy->review(
            array_replace(
                $current["rules"],
                ["require_primary_position" => $required],
                $changes,
            ),
            $current["version"],
            "Fictional institution approval policy.",
        );
        $policy->publish(
            new EntityId($review["publication_id"]),
            $review["rules"],
            $review["version"],
            $review["reason"],
            $review["review_token"],
        );
    }

    private function select(
        SisFixture $s,
        EntityId $employee,
        ?EntityId $position,
        ?int $positionVersion = 1,
    ): void {
        $service = $s->primaryPositions();
        $current = $service->get($employee);
        $args = [
            $employee,
            EntityId::generate(),
            $current["version"],
            $position,
            $position === null ? null : $positionVersion,
            "Private fictional primary reason.",
        ];
        $review = $service->review(...$args);
        $service->confirm(...[...$args, $review["review_token"]]);
    }

    private function reject(callable $action, string $class): void
    {
        try {
            $action();
            self::fail("An invalid primary-position approval was accepted.");
        } catch (\Throwable $error) {
            self::assertInstanceOf($class, $error);
        }
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_timesheets",
            "sis_primary_positions",
            "sis_audit",
        ]);
    }

    public function testDefaultIsOptionalAndEarlierRulesAndEvidenceRemainExact(): void
    {
        [$s, , , , $entry] = $this->fixture();
        $rules = $s->timeApprovalPolicies()->view()["rules"];
        self::assertFalse($rules["require_primary_position"]);
        unset($rules["require_primary_position"]);
        self::assertSame($rules, new TimeApprovalRules($rules)->data);
        self::assertFalse(
            new TimeApprovalRules($rules)->requiresPrimaryPosition(),
        );
        $review = new TimeReviewService(
            new SqlTimeRepository($s->sql, $s->dropCipher),
            $s->timeApprovalPolicies(),
            $s->primaryPositionLedger(),
        )->capture($entry);
        $legacy = $review->data;
        unset($legacy["policy"]["rules"]["require_primary_position"]);
        $restored = new TimeReviewEvidence($legacy);
        self::assertSame($legacy, $restored->data);
        self::assertNull($restored->summary["primary_position"]);
        foreach ([null, "true", 1, []] as $invalid) {
            $this->reject(
                fn() => new TimeApprovalRules(
                    $rules + ["require_primary_position" => $invalid],
                ),
                ValidationException::class,
            );
        }
    }

    public function testRequiredDesignationBlocksApprovalAndExceptionBypassButAllowsPendingWorkAndRejection(): void
    {
        [$s, $employee, , $worked, $entry] = $this->fixture();
        $this->publish($s, true, [
            "mode" => "exception_required",
            "daily_minutes" => 30,
        ]);
        $service = $s->timesheetService();
        $work = new TimeSlot(
            new DateTimeImmutable('2026-08-10T10:00:00Z'),
            new DateTimeImmutable('2026-08-10T11:00:00Z'),
        );
        self::assertTrue($service->workPositions($employee, $work)['time_review']['approval_blocked']);
        $pending = $service->submit($employee, $work, $worked, 1);
        self::assertSame('pending', $pending->status);
        $data = $service->entryDetails($entry->id);
        self::assertSame("pending", $data["status"]);
        self::assertTrue($data["time_review"]["approval_blocked"]);
        self::assertSame(
            "unrecorded",
            $data["time_review"]["primary_position"]["status"],
        );
        $before = $this->facts($s);
        $this->reject(
            fn() => $service->decide(
                $entry->id,
                true,
                0,
                $data["time_review"]["revision"],
                "Fictional exception.",
            ),
            ValidationException::class,
        );
        self::assertSame($before, $this->facts($s));
        $service->decide($entry->id, false, 0);
        $retained = $service->entryDetails($entry->id);
        self::assertSame("rejected", $retained["status"]);
        self::assertSame(
            "not_assessed",
            $retained["time_review"]["primary_position"]["status"],
        );
        self::assertSame(
            "unrecorded",
            $s->primaryPositions()->get($employee)["status"],
        );
    }

    public function testApprovalRetainsCurrentPrimaryCheckWithoutChangingTheWorkedPositionOrOriginalRetry(): void
    {
        [$s, $employee, $primary, $worked, $entry, $owner] = $this->fixture();
        $this->publish($s, true);
        $this->select($s, $employee, $primary);
        $service = $s->timesheetService();
        $data = $service->entryDetails($entry->id);
        self::assertFalse($data["time_review"]["approval_blocked"]);
        self::assertSame(
            "current",
            $data["time_review"]["primary_position"]["status"],
        );
        $this->reject(
            fn() => $service->decide($entry->id, true, 0),
            ValidationException::class,
        );
        $revision = $data["time_review"]["revision"];
        $service->decide($entry->id, true, 0, $revision);
        self::assertSame(
            $worked->value,
            $service->get($entry->id)->positionId->value,
        );
        $retained = $service->entryDetails($entry->id)["time_review"];
        $this->select($s, $employee, null);
        $this->publish($s, true, ["daily_minutes" => 30]);
        $before = $this->facts($s);
        $service->decide($entry->id, true, 0, $revision);
        self::assertSame($before, $this->facts($s));
        self::assertSame(
            $retained,
            $service->entryDetails($entry->id)["time_review"],
        );
        $s->access->staff = false;
        $s->access->actor = $owner;
        $ownerReview = $service->entryDetails($entry->id)['time_review'];
        $visible = $ownerReview['primary_position'];
        self::assertSame(
            ["required", "status", "checked_on"],
            array_keys($visible),
        );
        self::assertSame("current", $visible["status"]);
        self::assertStringNotContainsString(
            $primary->value,
            json_encode($visible),
        );
        self::assertStringNotContainsString(
            "Private fictional primary reason.",
            json_encode($visible),
        );
        $this->reject(
            fn() => $service->decide($entry->id, true, 0, $revision),
            AccessDeniedException::class,
        );
    }

    public function testChangedClearedEndedAndVoidedDesignationsRequireDeliberateHrResolution(): void
    {
        foreach (["changed", "cleared", "ended", "voided"] as $state) {
            [$s, $employee, $primary, , $entry] = $this->fixture();
            $this->publish($s, true);
            $this->select($s, $employee, $primary);
            if ($state === "cleared") {
                $this->select($s, $employee, null);
            } elseif ($state === "voided") {
                $s->positions()->setVoid(
                    $employee,
                    $primary,
                    1,
                    true,
                    "Fictional incorrect appointment.",
                );
            } else {
                $s->positions()->end(
                    $employee,
                    $primary,
                    1,
                    "2026-08-31",
                    "Fictional closure.",
                );
                if ($state === "ended") {
                    $s->clock->time = new DateTimeImmutable(
                        "2026-09-01T10:00:00-07:00",
                    );
                }
            }
            $data = $s->timesheetService()->entryDetails($entry->id);
            self::assertSame(
                $state,
                $data["time_review"]["primary_position"]["status"],
            );
            self::assertTrue($data["time_review"]["approval_blocked"]);
            $this->reject(
                fn() => $s
                    ->timesheetService()
                    ->decide(
                        $entry->id,
                        true,
                        0,
                        $data["time_review"]["revision"],
                    ),
                ValidationException::class,
            );
            if ($state === "changed") {
                $this->select($s, $employee, $primary, 2);
                $fresh = $s->timesheetService()->entryDetails($entry->id);
                self::assertFalse($fresh["time_review"]["approval_blocked"]);
                $s->timesheetService()->decide(
                    $entry->id,
                    true,
                    0,
                    $fresh["time_review"]["revision"],
                );
            }
        }
    }

    public function testStalePrimaryPolicyDateAndFinalAuditChangesCannotPartiallyApprove(): void
    {
        foreach (["designation", "policy", "date", "audit", "authority"] as $change) {
            [$s, $employee, $primary, , $entry] = $this->fixture();
            $this->publish($s, true);
            $this->select($s, $employee, $primary);
            $data = $s->timesheetService()->entryDetails($entry->id);
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(function () use (
                    $s,
                    $employee,
                    $change,
                ): void {
                    if ($change === "designation") {
                        $this->select($s, $employee, null);
                    } elseif ($change === "policy") {
                        $this->publish($s, false);
                    } elseif ($change === "date") {
                        $s->clock->time = $s->clock->time->modify("+1 day");
                    } elseif ($change === "authority") {
                        $s->access->staff = false;
                        $s->access->grants = [];
                    } else {
                        throw new \RuntimeException("Fictional failed audit.");
                    }
                });
            $before = $this->facts($s);
            $this->reject(
                fn() => $s
                    ->timesheetService($audit)
                    ->decide(
                        $entry->id,
                        true,
                        0,
                        $data["time_review"]["revision"],
                    ),
                $change === "authority"
                    ? AccessDeniedException::class
                    : \RuntimeException::class,
            );
            self::assertSame($before, $this->facts($s));
        }
    }

    public function testPrimaryObservationSharesReplayBudgetAndRejectsCorruptOrIncompleteEvidence(): void
    {
        [$s, $employee, $primary, , $entry] = $this->fixture();
        $this->publish($s, true);
        $this->select($s, $employee, $primary);
        $service = new TimeReviewService(
            new SqlTimeRepository($s->sql, $s->dropCipher),
            $s->timeApprovalPolicies(),
            $s->primaryPositionLedger(),
        );
        $this->reject(
            fn() => $service->capture(
                $entry,
                new HistoryReadBudget(maximumEvents: 1),
            ),
            HistoryReadLimitException::class,
        );
        $this->reject(
            fn() => $service->capture($entry, new HistoryReadBudget(maximumEvents: 2)),
            HistoryReadLimitException::class,
        );
        $data = $service->capture($entry)->data;
        foreach (["missing", "unknown_status", "bad_date", "bad_version", "extra"] as $case) {
            $broken = $data;
            if ($case === "missing") {
                unset($broken["primary_position"]);
            } elseif ($case === "unknown_status") {
                $broken["primary_position"]["status"] = "invented";
            } elseif ($case === "bad_date") {
                $broken["primary_position"]["checked_on"] = "2026-02-30";
            } elseif ($case === "bad_version") {
                $broken["primary_position"]["designation_version"] = 0;
            } else {
                $broken["primary_position"]["reason"] = "Not part of the evidence.";
            }
            $this->reject(
                fn() => new TimeReviewEvidence($broken),
                CorruptHistoryException::class,
            );
        }
        $head = $s->sql->rows("sis_history_streams", [
            "aggregate_id" => $employee->value,
            "stream_type" => "employment_primary_position",
        ])[0];
        $s->sql->update(
            "sis_history_events",
            ["sealed_event" => "Fictional damaged designation"],
            ["stream_id" => $head["id"]],
        );
        $before = $this->facts($s);
        $this->reject(
            fn() => $service->capture($entry),
            CorruptHistoryException::class,
        );
        self::assertSame($before, $this->facts($s));
        $s->timesheetService()->decide($entry->id, false, 0);
        self::assertSame(
            "rejected",
            $s->timesheetService()->get($entry->id)->status,
        );
    }

    public function testEarlierOptionalApprovalDoesNotAcquireTheNewRequirement(): void
    {
        [$s, , , , $entry] = $this->fixture();
        $service = $s->timesheetService();
        $service->decide($entry->id, true, 0);
        $original = $service->entryDetails($entry->id)["time_review"];
        $this->publish($s, true);
        $before = $this->facts($s);
        $service->decide($entry->id, true, 0);
        self::assertSame($before, $this->facts($s));
        self::assertSame(
            $original,
            $service->entryDetails($entry->id)["time_review"],
        );
        self::assertNull($original["primary_position"]);
    }

    public function testAnApprovedDecisionCannotRetainAFailedPrimaryCheck(): void
    {
        [$s, $employee, $primary, , $entry] = $this->fixture();
        $this->publish($s, true);
        $this->select($s, $employee, $primary);
        $evidence = new TimeReviewService(
            new SqlTimeRepository($s->sql, $s->dropCipher),
            $s->timeApprovalPolicies(),
            $s->primaryPositionLedger(),
        )->capture($entry)->data;
        $evidence["primary_position"]["status"] = "changed";
        $payload = [
            "operation_id" => EntityId::generate()->value,
            "entry_id" => $entry->id->value,
            "employee_id" => $employee->value,
            "position_id" => $entry->positionId->value,
            "position_version" => $entry->positionVersion,
            "evidence" => $evidence,
            "next_status" => "approved",
            "exception_reason" => null,
            "intent" => TimeApprovalRules::hash([$evidence, "approved", null]),
        ];
        $this->expectException(CorruptHistoryException::class);
        \Domain\HumanResources\TimeDecision::validate($payload);
    }

    public function testRecordingCannotCrossThePrimaryObservationDate(): void
    {
        [$s, $employee, $primary, , $entry, $owner] = $this->fixture();
        $this->publish($s, true);
        $this->select($s, $employee, $primary);
        $evidence = new TimeReviewService(
            new SqlTimeRepository($s->sql, $s->dropCipher),
            $s->timeApprovalPolicies(),
            $s->primaryPositionLedger(),
        )->capture($entry);
        $clock = $this->createStub(\Domain\Shared\Service\Clock::class);
        $clock->method('now')->willReturn($s->clock->time->modify('+1 day'));
        $ledger = new \Domain\HumanResources\Service\TimeDecisionLedger(
            \EventStoreFixture::history($s->connection->queryBuilder()),
            $clock,
        );
        $actor = $s->access->actor;
        $after = $entry->decide(true, $actor, $owner);
        $before = $this->facts($s);
        $this->reject(
            fn() => $ledger->record($entry, $after, $evidence, $actor, null),
            ConcurrentModificationException::class,
        );
        self::assertSame($before, $this->facts($s));
    }
}
