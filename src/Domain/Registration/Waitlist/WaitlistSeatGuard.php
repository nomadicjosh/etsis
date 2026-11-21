<?php

declare(strict_types=1);

namespace Domain\Registration\Waitlist;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Enrollment;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

/** Every registration path uses this boundary, including direct, grouped and schedule registrations. */
final readonly class WaitlistSeatGuard
{
    public function __construct(
        private WaitlistLedger $ledger,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }
    public function status(
        EntityId $section,
        EntityId $student,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->statuses($section, [$student], $budget)[$student->value];
    }

    /** One verified section read for a bounded registration group. */
    public function statuses(
        EntityId $section,
        array $students,
        ?HistoryReadBudget $budget = null,
    ): array {
        $root = $this->ledger->load($section, $budget ?? WaitlistLedger::budget());
        $active = $root->active();
        $results = [];
        foreach ($students as $student) {
            $mine = $root->forStudent($student->value);
            $index =
            $mine === null
            ? false
            : array_search($mine["id"], array_column($active, "id"), true);
            $results[$student->value] = [
                "enabled" => $root->policy()["enabled"],
                "waiting_count" => count($active),
                "can_register" => $active === [] || $active[0]["student_id"] === $student->value,
                "entry_id" => $mine["id"] ?? null,
                "rank" => $index === false ? null : $index + 1,
            ];
        }
        return $results;
    }
    public function claim(EntityId $section, EntityId $student): WaitlistClaim
    {
        $budget = WaitlistLedger::budget();
        $root = $this->ledger->load($section, $budget);
        $first = $root->active()[0] ?? null;
        if ($first !== null && $first["student_id"] !== $student->value) {
            throw new ValidationException([
                [
                    "field" => "waitlist",
                    "message" =>
                        "Earlier accepted waitlist requests have priority. " .
                        "Register the oldest request first or obtain a reviewed removal.",
                ],
            ]);
        }
        return new WaitlistClaim(
            $section,
            $student,
            $root->playhead(),
            $first["id"] ?? null,
            $budget,
        );
    }
    public function complete(WaitlistClaim $claim, Enrollment $enrollment, EntityId $actor): void
    {
        if (
            $claim->student->value !== $enrollment->studentId->value ||
            $claim->section->value !== $enrollment->sectionId->value
        ) {
            throw new ConcurrentModificationException("The seat-priority scope changed.");
        }
        $root = $this->ledger->load($claim->section, $claim->budget);
        if (
            $root->playhead() !== $claim->version ||
            ($root->active()[0]["id"] ?? null) !== $claim->entry
        ) {
            throw new ConcurrentModificationException(
                "The waitlist changed during registration. Review again.",
            );
        }
        if ($claim->entry === null) {
            return;
        }
        $entry = $root->entries()[$claim->entry];
        $operation = DerivedIdentity::from($enrollment->id, "waitlist.registration");
        $this->ledger->append(
            $claim->section,
            $root,
            [
                "operation_id" => $operation->value,
                "action" => "registered",
                "entry_id" => $entry["id"],
                "student_id" => $entry["student_id"],
                "person_id" => $entry["person_id"],
                "policy_position" => $entry["policy_position"],
                "enrollment_id" => $enrollment->id->value,
            ],
            false,
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                causation: $enrollment->id,
                reason: "Oldest accepted waitlist request completed by genuine course registration.",
            ),
        );
        $this->audit->record($actor, "registration.waitlist_registered", $enrollment->id);
        if (
            $this->ledger->load($claim->section, $claim->budget)->playhead() !== $root->playhead()
        ) {
            throw new ConcurrentModificationException(
                "The waitlist changed before registration completed.",
            );
        }
    }
}
