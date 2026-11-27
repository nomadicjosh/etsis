<?php

declare(strict_types=1);

namespace Domain\ServiceFee\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\ApplicationFormRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\ServiceFee\Repository\ServiceFeeRepository;
use Domain\ServiceFee\ValueObject\FeePolicy;
use Domain\ServiceFee\ValueObject\FeeSchema;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

use function Codefy\Framework\Helpers\trans;

final readonly class ServiceFeeService
{
    public const string MANAGE = "sis:fees:manage";
    public const string CONFIGURE = "sis:fees:configure";
    public function __construct(
        private ServiceFeeRepository $records,
        private ServiceFeeLedger $ledger,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private CatalogYearRepository $catalogs,
        private ApplicationFormRepository $forms,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private \Domain\User\Service\OwnerEmailVerification $verification,
    ) {
    }
    public function policies(): array
    {
        $this->access->requirePermission(self::CONFIGURE);
        return [
            "application" => $this->records->policy("application")->values(),
            "transcript" => $this->records->policy("transcript")->values(),
        ];
    }
    public function savePolicy(
        string $purpose,
        int $version,
        int $minor,
        string $currency,
        bool $waivers,
    ): array {
        FeeSchema::purpose($purpose);
        $actor = $this->access->requirePermission(self::CONFIGURE);
        if ($version < 0 || $version >= 2000000000) {
            throw new \InvalidArgumentException("Invalid fee policy version.");
        }
        $policy = new FeePolicy($version + 1, $minor, $currency, $waivers);
        return $this->transactions->run(function () use (
            $purpose,
            $version,
            $policy,
            $actor,
        ): array {
            if ($this->records->policy($purpose)->revision !== $version) {
                throw new ConcurrentModificationException(
                    "This fee policy changed. Reload it.",
                );
            }
            $this->records->savePolicy($purpose, $policy, $version);
            $this->audit->record(
                $actor,
                "service_fee.policy_changed",
                PublishedGradeId::forVersion(
                    new EntityId("00000000000000000000000001"),
                    "fee.policy." . $purpose,
                ),
            );
            $this->access->requirePermission(self::CONFIGURE);
            if ($actor->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            if (
                $this->records->policy($purpose)->values() !== $policy->values()
            ) {
                throw new ConcurrentModificationException(
                    "The fee policy changed during its audit. Reload it.",
                );
            }
            return $policy->values();
        });
    }
    public function prepare(
        EntityId $person,
        string $purpose,
        array $target,
        string $channel,
    ): array {
        $actor = $this->authorize($person, $channel);
        FeeSchema::purpose($purpose);
        FeeSchema::target($purpose, $target);
        return $this->transactions->run(function () use (
            $person,
            $purpose,
            $target,
            $channel,
            $actor,
        ): array {
            $this->admissions->lockPerson($person);
            $this->authorize($person, $channel, $actor);
            $p = $this->admissions->person($person);
            $owner = $p->ownerUserId ?? throw new AccessDeniedException();
            $labels = $this->target(
                $person,
                $purpose,
                $target,
                $channel,
                $owner,
            );
            $existing = $this->records->active(
                $person,
                $owner,
                $purpose,
                $target,
            );
            if ($existing !== null) {
                return $this->show(new EntityId($existing["id"]), $channel);
            }
            $policy = $this->records->policy($purpose);
            if ($policy->minor === 0) {
                return [
                    "required" => false,
                    "fee_id" => null,
                    "cleared" => true,
                ];
            }
            $id = EntityId::generate();
            $operation = PublishedGradeId::forVersion(
                $id,
                "service.fee.assess",
            );
            $details = [
                "person_id" => $person->value,
                "owner_id" => $owner->value,
                "purpose" => $purpose,
                "target" => $target,
                "labels" => $labels,
                "policy" => $policy->values(),
            ];
            $reason =
                "Service fee assessed for the selected application intake or official transcript request.";
            $this->audit->record($actor, "service_fee.assessed", $id);
            $this->authorize($person, $channel, $actor);
            if (
                $policy->values() !==
                    $this->records->policy($purpose)->values() ||
                $owner->value !==
                    $this->admissions->person($person)->ownerUserId?->value ||
                $labels !==
                    $this->target($person, $purpose, $target, $channel, $owner)
            ) {
                throw new ConcurrentModificationException(
                    "The fee target or policy changed. Review it again.",
                );
            }
            $this->ledger->record(
                $id,
                $operation,
                "assess",
                $details,
                0,
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    reason: $reason,
                ),
            );
            return $this->show($id, $channel);
        });
    }
    public function show(EntityId $fee, string $channel): array
    {
        $row =
        $this->records->find($fee) ??
        throw new RecordNotFoundException("Fee not found.");
        $person = new EntityId($row["person_id"]);
        $actor = $this->authorize($person, $channel);
        return $this->transactions->run(function () use (
            $fee,
            $person,
            $channel,
            $actor,
        ): array {
            $this->admissions->lockPerson($person);
            $root = $this->ledger->load($fee);
            $s = $root->state();
            $a = $s["assessment"];
            if (
                $a["person_id"] !== $person->value ||
                ($channel === "owner" && $a["owner_id"] !== $actor->value)
            ) {
                throw new AccessDeniedException();
            }
            $this->authorize($person, $channel, $actor);
            return [
                "required" => true,
                "fee_id" => $fee->value,
                "purpose" => $a["purpose"],
                "target" => $a["target"],
                "labels" => $a["labels"],
                "policy" => $a["policy"],
                "cleared" => $s["cleared"],
                "waived" => $s["waived"],
                "net_minor" => $s["net_minor"],
                "applied_to" => $s["applied_to"],
                "version" => $root->playhead(),
                "payments" => array_map(
                    static fn(array $r): array => array_intersect_key(
                        $r,
                        array_flip(
                            $channel === "staff"
                                ? [
                                    "payment_id",
                                    "minor",
                                    "currency",
                                    "channel",
                                    "method",
                                    "reference",
                                ]
                                : [
                                    "payment_id",
                                    "minor",
                                    "currency",
                                    "channel",
                                ],
                        ),
                    ),
                    array_values($s["payments"]),
                ),
                "refunds" => array_values(
                    array_map(
                        static fn(array $r): array => array_intersect_key(
                            $r,
                            array_flip(
                                $channel === "staff"
                                    ? ["payment_id", "minor", "reference"]
                                    : ["payment_id", "minor"],
                            ),
                        ),
                        $s["refunds"],
                    ),
                ),
            ];
        });
    }
    public function page(
        EntityId $person,
        string $channel,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($person, $channel);
        $rows = $this->records->page(
            $person,
            $channel === "owner" ? $actor : null,
            $after,
        );
        $items = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $items[] = $this->show(new EntityId($row["id"]), $channel);
        }
        $this->authorize($person, $channel, $actor);
        return [
            "items" => $items,
            "next_after" => count($rows) > 10 ? $rows[9]["id"] : null,
        ];
    }
    public function timeline(
        EntityId $fee,
        string $channel,
        int $before = 201,
    ): array {
        if ($before < 1 || $before > 201) {
            throw new \InvalidArgumentException("Invalid fee history page.");
        }
        $this->show($fee, $channel);
        $items = [];
        foreach ($this->ledger->decisions($fee) as $stored) {
            $e = $stored->event;
            if ($e->playhead() >= $before) {
                continue;
            }
            $p = $e->payload();
            $items[] = [
                "version" => $e->playhead(),
                "action" => $p["action"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "reason" =>
                    $channel === "staff" ? $stored->context->reason : null,
            ];
        }
        usort(
            $items,
            static fn(array $a, array $b): int => $b["version"] <=>
                $a["version"],
        );
        $this->show($fee, $channel);
        return [
            "items" => array_slice($items, 0, 20),
            "next_before" => count($items) > 20 ? $items[19]["version"] : null,
        ];
    }
    public function review(
        EntityId $fee,
        string $action,
        array $details,
        string $reason,
    ): array {
        $this->reason($reason);
        if (!in_array($action, ["payment", "waive", "refund"], true)) {
            throw new AccessDeniedException();
        }
        $data = $this->show($fee, "staff");
        $actor = $this->access->requirePermission(self::MANAGE);
        return $this->transactions->run(function () use (
            $fee,
            $action,
            $details,
            $reason,
            $actor,
            $data,
        ): array {
            $row = $this->records->find($fee);
            $person = new EntityId($row["person_id"]);
            $this->admissions->lockPerson($person);
            $this->authorize($person, "staff", $actor);
            $data = $this->show($fee, "staff");
            $root = $this->ledger->load($fee);
            $operation = EntityId::generate();
            $details = $this->details(
                $action,
                $details,
                $operation,
                $root->state()["assessment"]["policy"]["currency"],
            );
            $revision = FeeSchema::hash($root->state());
            $intent = FeeSchema::hash([
                $fee->value,
                $action,
                $details,
                trim($reason),
            ]);
            $this->probe($root, $operation, $action, $details, $reason, $actor);
            return [
                "fee_id" => $fee->value,
                "operation_id" => $operation->value,
                "action" => $action,
                "details" => $details,
                "reason" => trim($reason),
                "revision" => $revision,
                "summary" => $data,
                "review_token" => $this->proofs->issue(
                    "service-fee",
                    $action,
                    $fee,
                    $actor,
                    $operation,
                    FeeSchema::hash([$intent, $revision]),
                ),
            ];
        });
    }
    public function confirm(
        EntityId $fee,
        EntityId $operation,
        string $action,
        array $details,
        string $reason,
        string $revision,
        string $token,
    ): array {
        $this->reason($reason);
        if (
            !in_array($action, ["payment", "waive", "refund"], true) ||
            ($action === "payment" &&
                (($details["channel"] ?? null) !== "staff" ||
                    ($details["payment_id"] ?? null) !== $operation->value))
        ) {
            throw new AccessDeniedException();
        }
        $row =
        $this->records->find($fee) ??
        throw new RecordNotFoundException("Fee not found.");
        $person = new EntityId($row["person_id"]);
        $actor = $this->authorize($person, "staff");
        return $this->transactions->run(function () use (
            $fee,
            $operation,
            $action,
            $details,
            $reason,
            $revision,
            $token,
            $person,
            $actor,
        ): array {
            $this->admissions->lockPerson($person);
            $this->authorize($person, "staff", $actor);
            $root = $this->ledger->load($fee);
            $intent = FeeSchema::hash([
                $fee->value,
                $action,
                $details,
                trim($reason),
            ]);
            $accepted = $root->receipt($operation->value) !== null;
            $this->proofs->validate(
                $token,
                "service-fee",
                $action,
                $fee,
                $actor,
                $operation,
                FeeSchema::hash([$intent, $revision]),
                $accepted,
            );
            if (!$accepted) {
                $this->same($revision, FeeSchema::hash($root->state()));
                $this->probe(
                    $root,
                    $operation,
                    $action,
                    $details,
                    $reason,
                    $actor,
                );
                $this->audit->record($actor, "service_fee." . $action, $fee);
                $this->authorize($person, "staff", $actor);
                $this->proofs->validate(
                    $token,
                    "service-fee",
                    $action,
                    $fee,
                    $actor,
                    $operation,
                    FeeSchema::hash([$intent, $revision]),
                );
                $this->same(
                    $revision,
                    FeeSchema::hash($this->ledger->load($fee)->state()),
                );
            }
            $s = $root->state();
            $this->ledger->record(
                $fee,
                $operation,
                $action,
                $details,
                $root->playhead(),
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    new EntityId($s["decision_id"]),
                    trim($reason),
                ),
            );
            $this->authorize($person, "staff", $actor);
            return [
                "operation_id" => $operation->value,
                "status" => "recorded",
            ];
        });
    }
    private function target(
        EntityId $person,
        string $purpose,
        array $target,
        string $channel,
        EntityId $owner,
    ): array {
        if ($purpose === "transcript") {
            $s = $this->admissions->student(
                new EntityId($target["student_id"]),
            );
            if ($s->personId->value !== $person->value) {
                throw new AccessDeniedException();
            }
            return [
                "title" => $this->students->program($s->programId)["title"],
                "term" => "",
            ];
        }
        $program = new EntityId($target["program_id"]);
        $term = new EntityId($target["term_id"]);
        $this->catalogs->lockTerm($term);
        $this->catalogs->lockProgram($program);
        $this->forms->active($program);
        if ($channel === "owner") {
            $this->verification->requireForApplication($owner, $person);
        }
        return [
            "title" => $this->students->program($program)["title"],
            "term" => $this->students->term($term)["code"],
        ];
    }
    private function authorize(
        EntityId $person,
        string $channel,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        if ($channel === "owner") {
            if (
                $this->admissions->person($person)->ownerUserId?->value !==
                $actor->value
            ) {
                throw new AccessDeniedException();
            }
        } elseif ($channel === "staff") {
            if (
                $this->access->requirePermission(self::MANAGE)->value !== $actor->value ||
                $this->access->requirePermission('sis:person:manage')->value !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
        } else {
            throw new AccessDeniedException();
        }
        if (
            ($expected !== null && $expected->value !== $actor->value) ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function details(
        string $action,
        array $details,
        EntityId $operation,
        string $currency,
    ): array {
        if ($action === "payment") {
            if (
                count($details) !== 3 ||
                array_diff(array_keys($details), [
                    "minor",
                    "method",
                    "reference",
                ]) !== []
            ) {
                throw new \InvalidArgumentException(
                    "Enter the payment amount, method and explicit receipt reference.",
                );
            }
            return [
                "payment_id" => $operation->value,
                "minor" => $details["minor"],
                "currency" => $currency,
                "channel" => "staff",
                "method" => $details["method"],
                "reference" => $details["reference"],
                "session" => null,
            ];
        }
        return $details;
    }
    private function probe(
        \Domain\ServiceFee\ServiceFee $root,
        EntityId $operation,
        string $action,
        array $details,
        string $reason,
        EntityId $actor,
    ): void {
        $copy = clone $root;
        $p = [
            "operation_id" => $operation->value,
            "previous_id" => $root->state()["decision_id"],
            "action" => $action,
            "details" => $details,
            "intent" => FeeSchema::hash([
                (string) $root->aggregateId(),
                $action,
                $details,
                trim($reason),
            ]),
        ];
        $event = \Domain\ServiceFee\Event\ServiceFeeDecisionWasRecorded::occur(
            $root->aggregateId(),
            $p,
            ["__aggregate_type" => "service_fee"],
        )->withPlayhead($root->playhead() + 1);
        try {
            $copy->accept(
                new \Domain\Shared\EventSourcing\StoredEvent(
                    EntityId::generate(),
                    $event,
                    new EventContext(
                        $actor,
                        $this->clock->now(),
                        $operation,
                        new EntityId($root->state()["decision_id"]),
                        trim($reason),
                    ),
                    $this->clock->now(),
                    "",
                ),
            );
        } catch (\Throwable) {
            throw new ValidationException([
                [
                    "field" => "fee",
                    "message" =>
                        trans("This fee decision is not available for the recorded balance and policy."),
                ],
            ]);
        }
    }
    private function reason(string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => trans("Enter a reason of 1 to 1,000 characters."),
                ],
            ]);
        }
    }
    private function same(string $expected, string $actual): void
    {
        if (!hash_equals($expected, $actual)) {
            throw new ConcurrentModificationException(
                "This fee changed. Review it again.",
            );
        }
    }
}
