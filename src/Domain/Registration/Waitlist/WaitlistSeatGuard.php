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
        private \Domain\Shared\Security\StudentAccess $access,
        private \Domain\Shared\Service\ReviewProof $proofs,
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
    public function claim(EntityId $section, EntityId $student, ?WaitlistBypass $bypass = null): WaitlistClaim
    {
        $budget = WaitlistLedger::budget();
        $root = $this->ledger->load($section, $budget);
        $first = $root->active()[0] ?? null;
        if ($bypass !== null) {
            $this->bypassAuthority($bypass);
            $this->bypassEntry($root, $section, $student, $bypass);
        } elseif ($first !== null && $first["student_id"] !== $student->value) {
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
            $bypass?->entry->value ?? $first["id"] ?? null,
            $budget,
            $bypass,
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
            ($claim->bypass === null && ($root->active()[0]["id"] ?? null) !== $claim->entry)
        ) {
            throw new ConcurrentModificationException(
                "The waitlist changed during registration. Review again.",
            );
        }
        if ($claim->entry === null) {
            return;
        }
        $bypass = $claim->bypass;
        if ($bypass !== null) {
            $this->bypassAuthority($bypass, $actor);
            $this->bypassEntry($root, $claim->section, $claim->student, $bypass);
        }
        $entry = $root->entries()[$claim->entry];
        $operation = $bypass?->operation ?? DerivedIdentity::from($enrollment->id, "waitlist.registration");
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
            ] + ($bypass === null ? [] : ['priority_bypass' => [
                'review_revision' => $bypass->revision(),
                'queue_version' => $root->playhead(),
                'earlier_entry_ids' => array_column(array_slice(
                    $root->active(),
                    0,
                    array_search($entry['id'], array_column($root->active(), 'id'), true),
                ), 'id'),
            ]]),
            false,
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                causation: $enrollment->id,
                reason: $bypass?->reason ??
                    "Oldest accepted waitlist request completed by genuine course registration.",
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

    private function bypassAuthority(WaitlistBypass $bypass, ?EntityId $expected = null): void
    {
        $actor = $this->access->requirePermission(WaitlistBypass::DUTY);
        foreach ([WaitlistService::DUTY, 'sis:registration:manage'] as $duty) {
            if ($this->access->requirePermission($duty)->value !== $actor->value) {
                throw new \Domain\Shared\Exception\AccessDeniedException();
            }
        }
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException();
        }
        $this->proofs->validate(
            $bypass->token,
            'waitlist',
            'priority_bypass',
            $bypass->section,
            $actor,
            $bypass->operation,
            $bypass->revision(),
        );
    }

    private function bypassEntry(
        SectionWaitlist $root,
        EntityId $section,
        EntityId $student,
        WaitlistBypass $bypass,
    ): void {
        $entry = $root->forStudent($student->value);
        if (
            $bypass->section->value !== $section->value || $bypass->student->value !== $student->value ||
            $bypass->version !== $root->playhead() || ($entry['id'] ?? null) !== $bypass->entry->value ||
            ($entry['person_id'] ?? null) !== $bypass->person->value ||
            ($root->active()[0]['id'] ?? null) === $bypass->entry->value
        ) {
            throw new ConcurrentModificationException('The reviewed waitlist bypass changed. Review again.');
        }
    }
}
